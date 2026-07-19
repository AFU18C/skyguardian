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
        do {
            $now = now();

            NewsSource::query()
                ->with(['readerAccount.telegramApiCredential', 'publisherAccount.telegramApiCredential'])
                ->where('autopublish_enabled', true)
                ->where('source_status', 'available')
                ->where('destination_status', 'available')
                ->orderBy('id')
                ->each(function (NewsSource $source) use ($telethon, $now): void {
                    $interval = max(3, (int) ($source->poll_interval_seconds ?: 3));

                    if ($source->last_polled_at && $source->last_polled_at->addSeconds($interval)->isFuture()) {
                        return;
                    }

                    try {
                        $result = $telethon->relayOnce($source);
                        $published = (int) ($result['published'] ?? 0);

                        $source->update([
                            'last_source_message_id' => (int) ($result['last_message_id'] ?? $source->last_source_message_id ?? 0),
                            'last_received_at' => $published > 0 ? $now : $source->last_received_at,
                            'last_published_at' => $published > 0 ? $now : $source->last_published_at,
                            'last_polled_at' => $now,
                            'last_error' => null,
                        ]);
                    } catch (Throwable $exception) {
                        $source->update([
                            'last_polled_at' => $now,
                            'last_error' => $exception->getMessage(),
                        ]);

                        $this->error('Источник #'.$source->id.': '.$exception->getMessage());
                    }
                });

            if ($this->option('once')) {
                break;
            }

            sleep(1);
        } while (true);

        return self::SUCCESS;
    }
}
