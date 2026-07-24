<?php

namespace App\Jobs;

use App\Contracts\TelegramGateway;
use App\Models\NewsPublication;
use App\Services\NewsMessageFormatter;
use App\Services\TelegramFloodWait;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;
use Throwable;

class PublishNewsMessage implements ShouldBeUnique, ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 1000;

    public int $timeout = 300;

    public bool $failOnTimeout = true;

    public int $uniqueFor = 900;

    public function __construct(public readonly int $publicationId)
    {
        $this->onQueue('news');
    }

    public function handle(
        TelegramGateway $telegram,
        NewsMessageFormatter $formatter,
    ): void {
        $publication = NewsPublication::query()
            ->with([
                'source.telegramAccount.telegramApp',
                'telegramAccount.telegramApp',
            ])
            ->find($this->publicationId);

        if (! $publication || in_array($publication->status, [
            NewsPublication::STATUS_SENT,
            NewsPublication::STATUS_SKIPPED,
            NewsPublication::STATUS_FAILED,
        ], true)) {
            return;
        }

        $source = $publication->source;

        if (! $source) {
            return;
        }

        if (! $source->is_active) {
            $publication->update([
                'status' => NewsPublication::STATUS_SKIPPED,
                'last_error' => 'Канал данных выключен до публикации.',
            ]);

            return;
        }

        $account = $publication->telegramAccount;

        if (! $account || $source->telegram_account_id !== $account->id) {
            $publication->update([
                'status' => NewsPublication::STATUS_SKIPPED,
                'last_error' => 'Технический аккаунт канала был изменён или удалён.',
            ]);

            return;
        }

        $source->setAttribute('publication_peer_id', $publication->destination_peer_id);

        $lock = Cache::lock('news:telegram-account:'.$account->id, 600);

        if (! $lock->get()) {
            $this->release(3);

            return;
        }

        try {
            $publication->update([
                'status' => NewsPublication::STATUS_PROCESSING,
                'attempts' => $publication->attempts + 1,
                'last_attempt_at' => now(),
                'last_error' => null,
            ]);

            try {
                $messages = $telegram->messagesByIds(
                    $account,
                    $publication->source_peer_id,
                    $publication->message_ids,
                );

                if ($messages === []) {
                    throw new \RuntimeException('Исходное сообщение больше недоступно в Telegram.');
                }

                $formatted = $formatter->format($source, $messages);

                if (trim(strip_tags($formatted['body'])) === '' && ! $formatter->hasMedia($messages)) {
                    $publication->update([
                        'status' => NewsPublication::STATUS_SKIPPED,
                        'last_error' => null,
                    ]);

                    return;
                }

                $destinationMessageId = $telegram->publish(
                    $source,
                    $messages,
                    $formatted['body'],
                    $formatted['html'],
                );

                $publication->update([
                    'status' => NewsPublication::STATUS_SENT,
                    'published_at' => now(),
                    'destination_message_id' => $destinationMessageId,
                    'last_error' => null,
                ]);
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
                        'last_error' => null,
                    ]);
                    $publication->update([
                        'status' => NewsPublication::STATUS_PENDING,
                        'attempts' => max(0, $publication->attempts - 1),
                        'available_at' => $until,
                        'last_error' => null,
                    ]);
                    $this->release($wait);

                    return;
                }

                if ($publication->attempts >= 5) {
                    $publication->update([
                        'status' => NewsPublication::STATUS_FAILED,
                        'last_error' => mb_substr($exception->getMessage(), 0, 1000),
                    ]);
                    $this->fail($exception);

                    return;
                }

                $publication->update([
                    'status' => NewsPublication::STATUS_PENDING,
                    'available_at' => now()->addSeconds($this->retryDelay($publication->attempts)),
                    'last_error' => mb_substr($exception->getMessage(), 0, 1000),
                ]);

                throw $exception;
            }
        } finally {
            $lock->release();
        }
    }

    public function failed(?Throwable $exception): void
    {
        NewsPublication::query()
            ->whereKey($this->publicationId)
            ->update([
                'status' => NewsPublication::STATUS_FAILED,
                'last_error' => mb_substr($exception?->getMessage() ?: 'Неизвестная ошибка публикации.', 0, 1000),
            ]);
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [10, 30, 60, 300];
    }

    public function uniqueId(): string
    {
        return 'news-publication:'.$this->publicationId;
    }

    /**
     * @return list<string>
     */
    public function tags(): array
    {
        return ['news', 'publication:'.$this->publicationId];
    }

    private function retryDelay(int $attempt): int
    {
        return $this->backoff()[min(max(0, $attempt - 1), 3)];
    }
}
