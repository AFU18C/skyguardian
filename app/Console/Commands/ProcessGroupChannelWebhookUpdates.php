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
        $staleCutoff = now()->subMinutes(2);

        GroupChannelWebhookUpdate::query()
            ->where('status', GroupChannelWebhookUpdate::STATUS_PROCESSING)
            ->where('attempts', '>=', 10)
            ->where('updated_at', '<=', $staleCutoff)
            ->update([
                'status' => GroupChannelWebhookUpdate::STATUS_DEAD,
                'next_attempt_at' => null,
                'dead_lettered_at' => now(),
                'last_error' => 'Обработка прервалась на последней разрешённой попытке.',
                'updated_at' => now(),
            ]);

        GroupChannelWebhookUpdate::query()
            ->where(function ($query) use ($staleCutoff): void {
                $query->where('status', GroupChannelWebhookUpdate::STATUS_PENDING)
                    ->orWhere('status', GroupChannelWebhookUpdate::STATUS_FAILED)
                    ->orWhere(function ($query) use ($staleCutoff): void {
                        $query->where('status', GroupChannelWebhookUpdate::STATUS_PROCESSING)
                            ->where('updated_at', '<=', $staleCutoff);
                    });
            })
            ->where('attempts', '<', 10)
            ->where(function ($query): void {
                $query->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now());
            })
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

        GroupChannelWebhookUpdate::query()
            ->where('status', GroupChannelWebhookUpdate::STATUS_DEAD)
            ->where('updated_at', '<', now()->subDays((int) config('skyguardian.retention.failed_webhook_updates_days', 30)))
            ->delete();

        return self::SUCCESS;
    }
}
