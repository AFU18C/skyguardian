<?php

namespace App\Console\Commands;

use App\Models\GroupChannelBot;
use App\Services\GroupChannelWebhookRegistrationService;
use Illuminate\Console\Command;
use Throwable;

class MigrateGroupChannelWebhookUrls extends Command
{
    protected $signature = 'skyguardian:webhooks:migrate-url';

    protected $description = 'Перерегистрирует активные Telegram webhook без секрета в URL';

    public function handle(GroupChannelWebhookRegistrationService $registration): int
    {
        GroupChannelBot::query()
            ->where('is_active', true)
            ->whereNotNull('bot_token')
            ->orderBy('id')
            ->get()
            ->each(function (GroupChannelBot $bot) use ($registration): void {
                try {
                    $registration->register($bot);
                    $this->info('Webhook #'.$bot->id.' обновлён.');
                } catch (Throwable $e) {
                    report($e);
                    $bot->update(['webhook_last_error' => $e->getMessage()]);
                    $this->warn('Webhook #'.$bot->id.' сохранён на совместимом адресе: '.$e->getMessage());
                }
            });

        // A failed migration keeps the compatible endpoint operational and
        // must not interrupt deployment of unrelated news/alert services.
        return self::SUCCESS;
    }
}
