<?php

namespace App\Services;

use App\Contracts\TelegramGateway;
use App\Jobs\PublishNewsMessage;
use App\Models\NewsPublication;
use App\Models\Source;
use Illuminate\Support\Facades\DB;
use Throwable;

class NewsPollingService
{
    public function __construct(
        private readonly TelegramGateway $telegram,
        private readonly NewsMessageFormatter $formatter,
    ) {
    }

    /**
     * @return array{received: int, queued: int, skipped: int, baseline: bool, flood_wait: int|null}
     */
    public function poll(Source $source, bool $manual = false): array
    {
        $source->loadMissing('telegramAccount.telegramApp');
        $account = $source->telegramAccount;

        if (! $account || ! $account->telegramApp) {
            return $this->fail($source, new \RuntimeException('Технический аккаунт канала не найден.'), $manual);
        }

        if (! $manual && ! $source->is_active) {
            return $this->result();
        }

        try {
            $account->update(['last_attempt_at' => now()]);

            if ($source->resume_from_latest || $source->last_message_id === null) {
                $latestMessageId = $this->telegram->latestMessageId(
                    $account,
                    (string) ($source->peer_id ?: $source->identifier),
                );

                $this->markSuccessful(
                    $source,
                    $manual,
                    [
                        'last_message_id' => $latestMessageId,
                        'resume_from_latest' => false,
                    ],
                );

                return $this->result(baseline: true);
            }

            $messages = $this->telegram->messagesAfter(
                $account,
                (string) ($source->peer_id ?: $source->identifier),
                (int) $source->last_message_id,
            );

            if ($messages === []) {
                $this->markSuccessful($source, $manual);

                return $this->result();
            }

            $batches = $this->messageBatches($messages);
            $createdPublicationIds = [];
            $queued = 0;
            $skipped = 0;

            DB::transaction(function () use (
                $source,
                $account,
                $batches,
                &$createdPublicationIds,
                &$queued,
                &$skipped,
            ): void {
                /** @var Source $lockedSource */
                $lockedSource = Source::query()->lockForUpdate()->findOrFail($source->id);
                $highestMessageId = (int) $lockedSource->last_message_id;

                foreach ($batches as $batch) {
                    $messageIds = array_values(array_map(
                        fn (array $message): int => (int) $message['id'],
                        $batch,
                    ));
                    $highestMessageId = max($highestMessageId, ...$messageIds);
                    $groupedId = $batch[0]['grouped_id'] ?? null;
                    $identity = $groupedId ? 'group:'.$groupedId : 'message:'.max($messageIds);
                    $sourcePeer = (string) ($lockedSource->peer_id ?: $lockedSource->identifier);
                    $destination = (string) ($lockedSource->publication_peer_id ?: $lockedSource->publication_identifier);
                    $dedupeKey = hash('sha256', $lockedSource->id.'|'.$destination.'|'.$identity);
                    $passes = $this->formatter->passes($lockedSource, $batch);

                    $publication = NewsPublication::query()->firstOrCreate(
                        ['dedupe_key' => $dedupeKey],
                        [
                            'source_id' => $lockedSource->id,
                            'telegram_account_id' => $account->id,
                            'telegram_message_id' => max($messageIds),
                            'grouped_id' => $groupedId ? (string) $groupedId : null,
                            'message_ids' => $messageIds,
                            'source_peer_id' => $sourcePeer,
                            'destination_peer_id' => $destination,
                            'status' => $passes
                                ? NewsPublication::STATUS_PENDING
                                : NewsPublication::STATUS_SKIPPED,
                            'available_at' => now(),
                        ],
                    );

                    if (! $publication->wasRecentlyCreated) {
                        continue;
                    }

                    if ($passes) {
                        $queued++;
                        $createdPublicationIds[] = $publication->id;
                    } else {
                        $skipped++;
                    }
                }

                $lockedSource->update(['last_message_id' => $highestMessageId]);
            });

            foreach ($createdPublicationIds as $publicationId) {
                PublishNewsMessage::dispatch($publicationId)
                    ->onQueue('news')
                    ->afterCommit();
            }

            $this->markSuccessful($source, $manual);

            return $this->result(
                received: count($messages),
                queued: $queued,
                skipped: $skipped,
            );
        } catch (Throwable $exception) {
            $wait = TelegramFloodWait::seconds($exception);

            if ($wait !== null) {
                $until = now()->addSeconds($wait);
                $account->update([
                    'status' => 'rate_limited',
                    'flood_wait_until' => $until,
                    'last_error' => null,
                ]);
                $source->update([
                    'flood_wait_until' => $until,
                    'next_check_at' => $manual ? $source->next_check_at : $until,
                    'last_error' => null,
                ]);

                return $this->result(floodWait: $wait);
            }

            return $this->fail($source, $exception, $manual);
        }
    }

    /**
     * @return array{received: int, queued: int, skipped: int, baseline: bool, flood_wait: int|null}
     */
    private function fail(Source $source, Throwable $exception, bool $manual): array
    {
        $failures = min(20, (int) $source->consecutive_failures + 1);
        $retryDelay = max(
            (int) $source->check_interval_seconds,
            min(300, 5 * (2 ** min(6, $failures - 1))),
        );

        $source->update([
            'last_checked_at' => $manual ? $source->last_checked_at : now(),
            'last_manual_checked_at' => $manual ? now() : $source->last_manual_checked_at,
            'next_check_at' => $manual ? $source->next_check_at : now()->addSeconds($retryDelay),
            'is_available' => false,
            'consecutive_failures' => $failures,
            'last_error' => mb_substr($exception->getMessage(), 0, 1000),
        ]);

        report($exception);

        return $this->result();
    }

    /**
     * @param array<string, mixed> $extra
     */
    private function markSuccessful(Source $source, bool $manual, array $extra = []): void
    {
        $source->update(array_merge([
            'last_checked_at' => $manual ? $source->last_checked_at : now(),
            'last_manual_checked_at' => $manual ? now() : $source->last_manual_checked_at,
            'last_success_at' => now(),
            'next_check_at' => $manual
                ? $source->next_check_at
                : now()->addSeconds((int) $source->check_interval_seconds),
            'is_available' => true,
            'flood_wait_until' => null,
            'consecutive_failures' => 0,
            'last_error' => null,
        ], $extra));

        $source->telegramAccount?->update([
            'status' => 'connected',
            'last_success_at' => now(),
            'flood_wait_until' => null,
            'last_error' => null,
        ]);
    }

    /**
     * @param list<array<string, mixed>> $messages
     * @return list<list<array<string, mixed>>>
     */
    private function messageBatches(array $messages): array
    {
        $grouped = [];

        foreach ($messages as $message) {
            $groupedId = $message['grouped_id'] ?? null;
            $key = $groupedId
                ? 'group:'.$groupedId
                : 'message:'.(int) ($message['id'] ?? 0);
            $grouped[$key][] = $message;
        }

        $batches = array_values($grouped);

        foreach ($batches as &$batch) {
            usort($batch, fn (array $left, array $right): int => (int) $left['id'] <=> (int) $right['id']);
        }
        unset($batch);

        usort($batches, function (array $left, array $right): int {
            return (int) $left[0]['id'] <=> (int) $right[0]['id'];
        });

        return $batches;
    }

    /**
     * @return array{received: int, queued: int, skipped: int, baseline: bool, flood_wait: int|null}
     */
    private function result(
        int $received = 0,
        int $queued = 0,
        int $skipped = 0,
        bool $baseline = false,
        ?int $floodWait = null,
    ): array {
        return [
            'received' => $received,
            'queued' => $queued,
            'skipped' => $skipped,
            'baseline' => $baseline,
            'flood_wait' => $floodWait,
        ];
    }
}
