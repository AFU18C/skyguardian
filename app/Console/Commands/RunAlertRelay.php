<?php

namespace App\Console\Commands;

use App\Models\AlertSource;
use App\Models\TechnicalTelegramAccount;
use App\Services\Telegram\TelegramComponentPowerStore;
use App\Services\Telegram\TelethonAccountService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Throwable;

class RunAlertRelay extends Command
{
    protected $signature = 'alerts:relay {--once : Выполнить один цикл и завершить} {--sleep=1 : Пауза служебного цикла в секундах}';

    protected $description = 'Получает новые сообщения из Telegram-источников и публикует их в назначенные чаты';

    public function handle(TelethonAccountService $telethon): int
    {
        $lock = Cache::lock('skyguardian-alert-relay', 300);

        if (! $lock->get()) {
            $this->warn('Сервис автопубликации уже запущен.');
            return self::SUCCESS;
        }

        $sleep = max(1, (int) $this->option('sleep'));
        $this->info('SkyGuardian alert relay запущен.');

        try {
            do {
                $this->processSources($telethon);
                $lock->forceRelease();
                $lock = Cache::lock('skyguardian-alert-relay', 300);
                $lock->get();

                if (! $this->option('once')) {
                    sleep($sleep);
                }
            } while (! $this->option('once'));
        } finally {
            $lock->release();
        }

        return self::SUCCESS;
    }

    private function processSources(TelethonAccountService $telethon): void
    {
        $state = app(TelegramComponentPowerStore::class)->section('alerts');
        $disabledAccountIds = $state['disabled_account_ids'];
        $disabledApiIds = $state['disabled_api_ids'];

        if ($disabledApiIds !== []) {
            $disabledAccountIds = array_values(array_unique(array_merge(
                $disabledAccountIds,
                TechnicalTelegramAccount::query()
                    ->whereIn('telegram_api_credential_id', $disabledApiIds)
                    ->pluck('id')
                    ->map(fn ($id): int => (int) $id)
                    ->all(),
            )));
        }

        $sources = AlertSource::query()
            ->where('autopublish_enabled', true)
            ->where('source_status', 'available')
            ->where('destination_status', 'available')
            ->when($disabledAccountIds !== [], function ($query) use ($disabledAccountIds): void {
                $query->whereNotIn('reader_account_id', $disabledAccountIds)
                    ->whereNotIn('publisher_account_id', $disabledAccountIds);
            })
            ->with([
                'readerAccount.telegramApiCredential',
                'publisherAccount.telegramApiCredential',
            ])
            ->get();

        foreach ($sources as $source) {
            $interval = min(43200, max(3, (int) ($source->poll_interval_seconds ?: 3)));

            if ($source->last_polled_at
                && $source->last_polled_at->copy()->addSeconds($interval)->isFuture()) {
                continue;
            }

            $source->update(['last_polled_at' => now()]);

            try {
                $result = $telethon->relayOnce($source);
                $lastMessageId = (int) ($result['last_message_id'] ?? $source->last_source_message_id ?? 0);
                $received = (int) ($result['received'] ?? 0);
                $published = (int) ($result['published'] ?? 0);

                $updates = [
                    'last_source_message_id' => $lastMessageId ?: null,
                    'last_error' => null,
                ];

                if ($received > 0) {
                    $updates['last_received_at'] = now();
                }
                if ($published > 0) {
                    $updates['last_published_at'] = now();
                    $this->line("{$source->label}: опубликовано {$published}.");
                }

                $source->update($updates);
            } catch (Throwable $exception) {
                $source->update(['last_error' => $exception->getMessage()]);
                report($exception);
                $this->error("{$source->label}: {$exception->getMessage()}");
            }
        }
    }
}
