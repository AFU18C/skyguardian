<?php

namespace App\Console\Commands;

use App\Models\GroupChannelWebhookUpdate;
use App\Services\GroupChannelWebhookUpdateService;
use Illuminate\Console\Command;
use Throwable;

class ProcessGroupChannelWebhookUpdates extends Command
{
    protected $signature = 'skyguardian:group-channel-webhook-updates:process {--limit=50}';

    protected $description = 'Повторяет обработку неуспешных Telegram webhook updates';

    public function handle(GroupChannelWebhookUpdateService $service): int
    {
        $limit = max(1, (int) $this->option('limit'));

        GroupChannelWebhookUpdate::query()
            ->where(function ($query): void {
                $query->where('status', GroupChannelWebhookUpdate::STATUS_PENDING)
                    ->orWhere('status', GroupChannelWebhookUpdate::STATUS_FAILED)
                    ->orWhere(function ($query): void {
                        $query->where('status', GroupChannelWebhookUpdate::STATUS_PROCESSING)
                            ->where('updated_at', '<=', now()->subMinutes(2));
                    });
            })
            ->where('attempts', '<', 10)
            ->oldest('updated_at')
            ->limit($limit)
            ->get()
            ->each(function (GroupChannelWebhookUpdate $update) use ($service): void {
                try {
                    $service->process($update);
                } catch (Throwable $e) {
                    report($e);
                    $this->error('Webhook update #'.$update->id.': '.$e->getMessage());
                }
            });

        GroupChannelWebhookUpdate::query()
            ->where('status', GroupChannelWebhookUpdate::STATUS_PROCESSED)
            ->where('processed_at', '<', now()->subDays(7))
            ->delete();

        return self::SUCCESS;
    }
}
