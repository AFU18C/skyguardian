<?php

namespace App\Console\Commands;

use App\Models\GroupChannelWebhookUpdate;
use App\Services\GroupChannelWebhookUpdateService;
use Illuminate\Console\Command;
use Throwable;

class ProcessGroupChannelWebhookUpdates extends Command
{
    protected $signature = 'skyguardian:group-channel-webhook-updates:process {--limit=50}';

    protected $description = 'Обрабатывает сохранённые Telegram webhook updates с retry/backoff';

    public function handle(GroupChannelWebhookUpdateService $service): int
    {
        $limit = max(1, min(500, (int) $this->option('limit')));
        $failed = 0;

        GroupChannelWebhookUpdate::query()
            ->where(function ($query): void {
                $query->where('status', GroupChannelWebhookUpdate::STATUS_PENDING)
                    ->orWhere(function ($query): void {
                        $query->where('status', GroupChannelWebhookUpdate::STATUS_FAILED)
                            ->where(function ($query): void {
                                $query->whereNull('next_attempt_at')
                                    ->orWhere('next_attempt_at', '<=', now());
                            });
                    })
                    ->orWhere(function ($query): void {
                        $query->where('status', GroupChannelWebhookUpdate::STATUS_PROCESSING)
                            ->where('updated_at', '<=', now()->subMinutes(2));
                    });
            })
            ->where('attempts', '<', GroupChannelWebhookUpdate::MAX_ATTEMPTS)
            ->oldest('updated_at')
            ->limit($limit)
            ->get()
            ->each(function (GroupChannelWebhookUpdate $update) use ($service, &$failed): void {
                try {
                    $service->process($update);
                } catch (Throwable $e) {
                    $failed++;
                    report($e);
                    $this->error('Webhook update #'.$update->id.': '.$e->getMessage());
                }
            });

        GroupChannelWebhookUpdate::query()
            ->where('status', GroupChannelWebhookUpdate::STATUS_PROCESSED)
            ->where('processed_at', '<', now()->subDays(7))
            ->delete();

        GroupChannelWebhookUpdate::query()
            ->where('status', GroupChannelWebhookUpdate::STATUS_DEAD_LETTER)
            ->where('dead_letter_at', '<', now()->subDays(30))
            ->delete();

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
