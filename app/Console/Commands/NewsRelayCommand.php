<?php

namespace App\Console\Commands;

use App\Models\NewsSource;
use App\Services\Telegram\TelethonAccountService;
use Illuminate\Console\Command;
use Throwable;

class NewsRelayCommand extends Command
{
    protected $signature = 'news:relay {--once : Выполнить один цикл и завершить}';

    protected $description = 'Получает новые сообщения из источников новостей и публикует их в назначение';

    public function handle(TelethonAccountService $telethon): int
    {
        $lockDirectory = storage_path('app/private/telegram');
        if (! is_dir($lockDirectory) && ! mkdir($lockDirectory, 0770, true) && ! is_dir($lockDirectory)) {
            $this->error('Не удалось создать каталог блокировки новостного relay.');

            return self::FAILURE;
        }

        $lockHandle = fopen($lockDirectory.'/news-relay.lock', 'c+');
        if ($lockHandle === false) {
            $this->error('Не удалось открыть файл блокировки новостного relay.');

            return self::FAILURE;
        }

        if (! flock($lockHandle, LOCK_EX | LOCK_NB)) {
            fclose($lockHandle);
            $this->warn('Новостной relay уже запущен в другом процессе.');

            return $this->option('once') ? self::SUCCESS : self::FAILURE;
        }

        try {
            do {
                $this->processCycle($telethon);

                if ($this->option('once')) {
                    break;
                }

                sleep(1);
            } while (true);
        } finally {
            flock($lockHandle, LOCK_UN);
            fclose($lockHandle);
        }

        return self::SUCCESS;
    }

    private function processCycle(TelethonAccountService $telethon): void
    {
        $now = now();

        NewsSource::query()
            ->with(['readerAccount.telegramApiCredential', 'publisherAccount.telegramApiCredential'])
            ->where('autopublish_enabled', true)
            ->where('source_status', 'available')
            ->where('destination_status', 'available')
            ->orderBy('id')
            ->eachById(function (NewsSource $source) use ($telethon, $now): void {
                $interval = max(3, (int) ($source->poll_interval_seconds ?: 3));

                if ($source->last_polled_at?->copy()->addSeconds($interval)->isFuture()) {
                    return;
                }

                $readerReady = $source->readerAccount
                    && $source->readerAccount->status === 'connected'
                    && $source->readerAccount->telegramApiCredential;
                $publisherReady = $source->publisherAccount
                    && $source->publisherAccount->status === 'connected'
                    && $source->publisherAccount->telegramApiCredential;

                if (! $readerReady || ! $publisherReady) {
                    $problems = [];
                    if (! $readerReady) {
                        $problems[] = 'аккаунт чтения не подключён или не имеет Telegram API';
                    }
                    if (! $publisherReady) {
                        $problems[] = 'аккаунт публикации не подключён или не имеет Telegram API';
                    }

                    $message = ucfirst(implode('; ', $problems)).'. Автопубликация отключена.';

                    $source->update([
                        'autopublish_enabled' => false,
                        'source_status' => $readerReady ? $source->source_status : 'not_checked',
                        'destination_status' => $publisherReady ? $source->destination_status : 'not_checked',
                        'last_polled_at' => $now,
                        'last_error' => $message,
                    ]);

                    $this->warn(($source->label ?: 'Источник #'.$source->id).': '.$message);

                    return;
                }

                try {
                    $result = $telethon->relayOnce($source);
                    $published = max(0, (int) ($result['published'] ?? 0));
                    $lastMessageId = max(
                        (int) ($source->last_source_message_id ?? 0),
                        (int) ($result['last_message_id'] ?? 0),
                    );
                    $partialFailure = (bool) ($result['partial_failure'] ?? false);
                    $partialMessage = $partialFailure
                        ? mb_substr((string) ($result['message'] ?? 'Часть сообщений не удалось опубликовать.'), 0, 2000)
                        : null;

                    $source->update([
                        'last_source_message_id' => $lastMessageId,
                        'last_received_at' => $published > 0 ? $now : $source->last_received_at,
                        'last_published_at' => $published > 0 ? $now : $source->last_published_at,
                        'last_polled_at' => $now,
                        'last_error' => $partialMessage,
                    ]);

                    $label = $source->label ?: 'Источник #'.$source->id;
                    if ($published > 0) {
                        $this->info($label.': опубликовано '.$published.'.');
                    }
                    if ($partialFailure) {
                        $failedId = (int) ($result['failed_message_id'] ?? 0);
                        $suffix = $failedId > 0 ? ' Сообщение #'.$failedId.' будет повторено.' : '';
                        $this->warn($label.': частичная ошибка — '.$partialMessage.'.'.$suffix);
                    }
                } catch (Throwable $exception) {
                    $message = mb_substr($exception->getMessage(), 0, 2000);

                    $source->update([
                        'last_polled_at' => $now,
                        'last_error' => $message,
                    ]);

                    $this->error(($source->label ?: 'Источник #'.$source->id).': '.$message);
                }
            }, column: 'id');
    }
}
