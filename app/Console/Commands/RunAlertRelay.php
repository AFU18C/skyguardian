<?php

namespace App\Console\Commands;

use App\Models\AlertSource;
use App\Services\Telegram\TelethonAccountService;
use Illuminate\Console\Command;
use Throwable;

class RunAlertRelay extends Command
{
    protected $signature = 'alerts:relay {--once : Выполнить один цикл и завершить} {--sleep=1 : Пауза служебного цикла в секундах}';

    protected $description = 'Получает новые сообщения из Telegram-источников и публикует их в назначенные чаты';

    public function handle(TelethonAccountService $telethon): int
    {
        $lockDirectory = storage_path('app/private/telegram');
        if (! is_dir($lockDirectory) && ! mkdir($lockDirectory, 0770, true) && ! is_dir($lockDirectory)) {
            $this->error('Не удалось создать каталог блокировки тревожного relay.');

            return self::FAILURE;
        }

        $lockHandle = fopen($lockDirectory.'/alert-relay.lock', 'c+');
        if ($lockHandle === false) {
            $this->error('Не удалось открыть файл блокировки тревожного relay.');

            return self::FAILURE;
        }

        if (! flock($lockHandle, LOCK_EX | LOCK_NB)) {
            fclose($lockHandle);
            $this->warn('Сервис автопубликации уже запущен.');

            return self::SUCCESS;
        }

        $sleep = max(1, (int) $this->option('sleep'));
        $this->info('SkyGuardian alert relay запущен.');

        try {
            do {
                $this->processSources($telethon);

                if (! $this->option('once')) {
                    sleep($sleep);
                }
            } while (! $this->option('once'));
        } finally {
            flock($lockHandle, LOCK_UN);
            fclose($lockHandle);
        }

        return self::SUCCESS;
    }

    private function processSources(TelethonAccountService $telethon): void
    {
        $sources = AlertSource::query()
            ->where('autopublish_enabled', true)
            ->where('source_status', 'available')
            ->where('destination_status', 'available')
            ->with([
                'readerAccount.telegramApiCredential',
                'publisherAccount.telegramApiCredential',
            ])
            ->get();

        foreach ($sources as $source) {
            $source->refresh();
            $source->loadMissing([
                'readerAccount.telegramApiCredential',
                'publisherAccount.telegramApiCredential',
            ]);

            if (! $source->autopublish_enabled
                || $source->source_status !== 'available'
                || $source->destination_status !== 'available'
                || $source->readerAccount?->status === 'disabled'
                || $source->publisherAccount?->status === 'disabled'
                || ! $source->readerAccount?->telegramApiCredential?->is_enabled
                || ! $source->publisherAccount?->telegramApiCredential?->is_enabled) {
                continue;
            }

            $interval = min(43200, max(3, (int) ($source->poll_interval_seconds ?: 3)));

            if ($source->last_polled_at
                && $source->last_polled_at->copy()->addSeconds($interval)->isFuture()) {
                continue;
            }

            $source->update(['last_polled_at' => now()]);
            $source->refresh();
            $source->loadMissing([
                'readerAccount.telegramApiCredential',
                'publisherAccount.telegramApiCredential',
            ]);

            if (! $source->autopublish_enabled
                || $source->readerAccount?->status === 'disabled'
                || $source->publisherAccount?->status === 'disabled'
                || ! $source->readerAccount?->telegramApiCredential?->is_enabled
                || ! $source->publisherAccount?->telegramApiCredential?->is_enabled) {
                continue;
            }

            try {
                $result = $telethon->relayOnce($source);
                $lastMessageId = max(
                    (int) ($source->last_source_message_id ?? 0),
                    (int) ($result['last_message_id'] ?? 0),
                );
                $received = max(0, (int) ($result['received'] ?? 0));
                $published = max(0, (int) ($result['published'] ?? 0));
                $partialFailure = (bool) ($result['partial_failure'] ?? false);
                $partialMessage = $partialFailure
                    ? mb_substr((string) ($result['message'] ?? 'Часть сообщений не удалось опубликовать.'), 0, 2000)
                    : null;

                $updates = [
                    'last_source_message_id' => $lastMessageId ?: null,
                    'last_error' => $partialMessage,
                ];

                if ($received > 0) {
                    $updates['last_received_at'] = now();
                }
                if ($published > 0) {
                    $updates['last_published_at'] = now();
                    $this->line("{$source->label}: опубликовано {$published}.");
                }

                $source->update($updates);

                if ($partialFailure) {
                    $failedId = (int) ($result['failed_message_id'] ?? 0);
                    $suffix = $failedId > 0 ? " Сообщение #{$failedId} будет повторено." : '';
                    $this->warn("{$source->label}: частичная ошибка — {$partialMessage}.{$suffix}");
                }
            } catch (Throwable $exception) {
                $message = mb_substr($exception->getMessage(), 0, 2000);
                $source->update(['last_error' => $message]);
                report($exception);
                $this->error("{$source->label}: {$message}");
            }
        }
    }
}
