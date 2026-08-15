<?php

namespace App\Console\Commands;

use App\Models\AdminAuditLog;
use App\Models\GroupChannelMessage;
use App\Models\GroupChannelWebhookUpdate;
use Illuminate\Console\Command;

class PruneOperationalData extends Command
{
    protected $signature = 'skyguardian:data:prune';

    protected $description = 'Remove expired operational and audit records without dropping pending work';

    public function handle(): int
    {
        $messageCutoff = now()->subDays(max(1, (int) config('skyguardian.retention.group_channel_messages_days', 30)));
        $auditCutoff = now()->subDays(max(7, (int) config('skyguardian.retention.audit_log_days', 180)));
        $webhookCutoff = now()->subDays(max(7, (int) config('skyguardian.retention.failed_webhook_updates_days', 30)));

        $messages = GroupChannelMessage::query()
            ->where('created_at', '<', $messageCutoff)
            ->where(function ($query): void {
                $query->whereNull('delete_at')
                    ->orWhereNotNull('deleted_at_telegram')
                    ->orWhereNotNull('delete_failed_at');
            })
            ->delete();

        $auditLogs = AdminAuditLog::query()
            ->where('created_at', '<', $auditCutoff)
            ->delete();

        $webhooks = GroupChannelWebhookUpdate::query()
            ->whereIn('status', [
                GroupChannelWebhookUpdate::STATUS_PROCESSED,
                GroupChannelWebhookUpdate::STATUS_DEAD,
            ])
            ->where('updated_at', '<', $webhookCutoff)
            ->delete();

        $this->components->info("Removed {$messages} messages, {$auditLogs} audit entries and {$webhooks} webhook updates.");

        return self::SUCCESS;
    }
}
