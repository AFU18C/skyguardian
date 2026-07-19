<?php

namespace App\Console\Commands;

use App\Models\NewsSource;
use App\Services\Telegram\TelethonAccountService;
use Illuminate\Console\Command;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\Cache;
use Throwable;

class NewsRelayCommand extends Command
{
    protected $signature = 'news:relay {--once : Выполнить один цикл и завершить}';

    protected $description = 'Получает новые сообщения из источников новостей и публикует их в назначение';

    public function handle(TelethonAccountService $telethon): int
    {
        $lock = Cache::lock('skyguardian:news-relay', 300);

        if (! $lock->get()) {
            $this->warn('Новостной relay уже запущен в другом процессе.');

            return self::FAILURE;
        }

        try {
            do {
                $this->processCycle($telethon, $lock);

                if ($this->option('once')) {
                    break;
                }

                sleep(1);
            } while (true);
        } finally {
            $lock->release();
        }

        return self::SUCCESS;
    }

    private function processCycle(TelethonAccountService $telethon, Lock $lock): void
    {
        $now = now();
        $lock->forceRelease();
        $lock->get();

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

                try {
                    $result = $telethon->relayOnce($source);
                    $published = max(0, (int) ($result['published'] ?? 0));
                    $lastMessageId = max(
                        (int) ($source->last_source_message_id ?? 0),
                        (int) ($result['last_message_id'] ?? 0),
                    );

                    $source->update([
                        'last_source_message_id' => $lastMessageId,
                        'last_received_at' => $published > 0 ? $now : $source->last_received_at,
                        'last_published_at' => $published > 0 ? $now : $source->last_published_at,
                        'last_polled_at' => $now,
                        'last_error' => null,
                    ]);

                    if ($published > 0) {
                        $this->info(($source->label ?: 'Источник #'.$source->id).': опубликовано '.$published.'.');
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
