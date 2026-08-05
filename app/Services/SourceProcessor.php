<?php

namespace App\Services;

use App\Models\Source;
use RuntimeException;
use Throwable;

class SourceProcessor
{
    public function __construct(
        private readonly TelethonClient $telethon,
        private readonly OperationGate $gate,
        private readonly SourceScheduler $scheduler,
    ) {}

    public function process(Source $source): array
    {
        $source->loadMissing(['technicalAccount.telegramApi', 'rules']);
        $account = $source->technicalAccount;

        if (! $account || ! $account->is_active) {
            $this->scheduler->scheduleNext($source);
            throw new RuntimeException('Для источника не назначен активный технический аккаунт.');
        }

        $token = $this->gate->acquire('source.process', $account, 180);

        try {
            if ($source->last_message_id === null) {
                $result = $this->telethon->call('latest_message_id', $account, [
                    'peer' => $source->source_peer,
                ]);

                $source->forceFill([
                    'last_message_id' => max((int) ($result['latest_message_id'] ?? 0), 0),
                    'last_error' => null,
                    'last_success_at' => now(),
                ])->save();
                $this->scheduler->scheduleNext($source);

                return [
                    'source_id' => $source->id,
                    'initialized' => true,
                    'messages_found' => 0,
                    'messages_copied' => 0,
                    'last_message_id' => $source->fresh()->last_message_id,
                ];
            }

            $result = $this->telethon->call('fetch_messages', $account, [
                'peer' => $source->source_peer,
                'min_id' => $source->last_message_id,
                'limit' => 100,
            ]);

            $messages = $result['messages'] ?? [];
            $messageIds = array_values(array_map(
                static fn (array $message): int => (int) $message['id'],
                $messages,
            ));
            $copiedCount = 0;
            $failedCount = 0;
            $copyError = null;
            $lastProcessedId = $messageIds ? max($messageIds) : null;
            $pendingCopy = $source->pending_copy;

            if ($messageIds && $source->destination_peer) {
                $mapButtonUrl = $this->mapButtonUrl($source);
                $copyResult = $mapButtonUrl !== null
                    ? $this->copyMessagesWithMapButtons($source, $messages, $mapButtonUrl)
                    : $this->telethon->call('copy_messages', $account, [
                        'source_peer' => $source->source_peer,
                        'destination_peer' => $source->destination_peer,
                        'message_ids' => $messageIds,
                        'settings' => $this->copySettings($source),
                    ]);
                $copiedCount = (int) ($copyResult['copied_count'] ?? count($messageIds));
                $failedCount = (int) ($copyResult['failed_count'] ?? 0);
                $pendingCopy = data_get($copyResult, 'partial_delivery');

                if ($failedCount > 0) {
                    $lastProcessedId = data_get($copyResult, 'last_processed_id');
                    $firstError = (string) data_get($copyResult, 'failed.0.error', 'Неизвестная ошибка Telegram.');
                    $copyError = "Не скопировано сообщений: {$failedCount}. {$firstError}";
                } elseif (is_numeric($copyResult['last_processed_id'] ?? null)) {
                    $lastProcessedId = (int) $copyResult['last_processed_id'];
                }

                $buttonError = trim((string) ($copyResult['button_error'] ?? ''));

                if ($buttonError !== '') {
                    $copyError = trim(($copyError !== null ? $copyError.' ' : '').'Кнопка карты: '.$buttonError);
                }
            }

            $changes = [
                'last_error' => $copyError,
                'last_success_at' => now(),
                'pending_copy' => $pendingCopy,
            ];

            if ($lastProcessedId !== null) {
                $changes['last_message_id'] = max((int) $lastProcessedId, (int) $source->last_message_id);
            }

            $source->forceFill($changes)->save();
            $this->scheduler->scheduleNext($source);

            return [
                'source_id' => $source->id,
                'initialized' => false,
                'messages_found' => count($messageIds),
                'messages_copied' => $copiedCount,
                'messages_failed' => $failedCount,
                'last_message_id' => $source->fresh()->last_message_id,
            ];
        } catch (Throwable $e) {
            $source->forceFill(['last_error' => $e->getMessage()])->save();
            $this->scheduler->scheduleNext($source);
            throw $e;
        } finally {
            $this->gate->release($token);
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $messages
     * @return array<string, mixed>
     */
    private function copyMessagesWithMapButtons(Source $source, array $messages, string $url): array
    {
        $account = $source->technicalAccount;
        $copiedCount = 0;
        $failedCount = 0;
        $failed = [];
        $lastProcessedId = null;
        $partialDelivery = null;
        $resumePartial = $source->pending_copy;
        $buttonErrors = [];
        $buttonService = app(SourceMapButtonService::class);

        foreach ($this->groupedMessageIds($messages) as $messageIds) {
            $settings = $this->copySettings($source);
            $settings['resume_partial'] = $resumePartial;
            $copyResult = $this->telethon->call('copy_messages', $account, [
                'source_peer' => $source->source_peer,
                'destination_peer' => $source->destination_peer,
                'message_ids' => $messageIds,
                'settings' => $settings,
            ]);
            $groupCopied = (int) ($copyResult['copied_count'] ?? count($messageIds));
            $groupFailed = (int) ($copyResult['failed_count'] ?? 0);
            $copiedCount += $groupCopied;
            $failedCount += $groupFailed;

            if ($groupFailed > 0) {
                $failed = array_merge($failed, (array) ($copyResult['failed'] ?? []));
                $partialDelivery = data_get($copyResult, 'partial_delivery');
                $groupLastProcessedId = data_get($copyResult, 'last_processed_id');

                if (is_numeric($groupLastProcessedId)) {
                    $lastProcessedId = max((int) ($lastProcessedId ?? 0), (int) $groupLastProcessedId);
                }

                break;
            }

            $resumePartial = null;
            $partialDelivery = null;
            $lastProcessedId = max((int) ($lastProcessedId ?? 0), max($messageIds));

            if ($groupCopied < 1) {
                continue;
            }

            try {
                $latest = $this->telethon->call('latest_message_id', $account, [
                    'peer' => $source->destination_peer,
                ]);
                $destinationMessageId = (int) ($latest['latest_message_id'] ?? 0);

                if ($destinationMessageId < 1) {
                    $buttonErrors[] = 'Не удалось определить опубликованное сообщение.';
                    continue;
                }

                $buttonError = $buttonService->attach($source, $destinationMessageId, $url);

                if ($buttonError !== null) {
                    $buttonErrors[] = $buttonError;
                }
            } catch (Throwable $exception) {
                report($exception);
                $buttonErrors[] = 'Не удалось определить сообщение или добавить кнопку.';
            }
        }

        return [
            'copied_count' => $copiedCount,
            'failed_count' => $failedCount,
            'failed' => $failed,
            'last_processed_id' => $lastProcessedId,
            'partial_delivery' => $partialDelivery,
            'button_error' => implode(' ', array_values(array_unique($buttonErrors))),
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $messages
     * @return array<int, array<int, int>>
     */
    private function groupedMessageIds(array $messages): array
    {
        usort($messages, static fn (array $left, array $right): int => (int) ($left['id'] ?? 0) <=> (int) ($right['id'] ?? 0));
        $groups = [];

        foreach ($messages as $message) {
            $messageId = (int) ($message['id'] ?? 0);

            if ($messageId < 1) {
                continue;
            }

            $groupedId = $message['grouped_id'] ?? null;
            $key = $groupedId !== null && $groupedId !== ''
                ? 'group:'.(string) $groupedId
                : 'message:'.$messageId;
            $groups[$key] ??= [];
            $groups[$key][] = $messageId;
        }

        return array_values($groups);
    }

    private function mapButtonUrl(Source $source): ?string
    {
        $url = trim((string) $this->ruleValue($source, 'map_button_url', ''));

        return $url !== '' ? $url : null;
    }

    private function copySettings(Source $source): array
    {
        $removePhrases = preg_split('/\R/u', (string) $this->ruleValue($source, 'remove_phrases', '')) ?: [];
        $blockedKeywords = preg_split('/\R/u', (string) $this->ruleValue($source, 'blocked_keywords', '')) ?: [];

        return [
            'copy_mode' => (string) $this->ruleValue($source, 'copy_mode', 'original'),
            'strip_links' => (bool) $this->ruleValue($source, 'strip_links', false),
            'strip_hashtags' => (bool) $this->ruleValue($source, 'strip_hashtags', false),
            'strip_mentions' => (bool) $this->ruleValue($source, 'strip_mentions', false),
            'remove_phrases' => array_values(array_filter(array_map('trim', $removePhrases))),
            'footer_html' => (string) $this->ruleValue($source, 'footer_html', ''),
            'blocked_keywords' => array_values(array_filter(array_map('trim', $blockedKeywords))),
            'resume_partial' => $source->pending_copy,
        ];
    }

    private function ruleValue(Source $source, string $key, mixed $default): mixed
    {
        $rule = $source->rules->firstWhere('key', $key);

        if (! $rule || ! $rule->is_active) {
            return $default;
        }

        return data_get($rule->value, 'value', $default);
    }
}
