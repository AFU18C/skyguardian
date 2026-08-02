<?php

namespace App\Services;

use App\Models\GroupChannelBot;
use App\Models\GroupChannelWebhookUpdate;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class GroupChannelWebhookUpdateService
{
    public function __construct(
        private readonly GroupChannelWebhookService $webhook,
        private readonly GroupChannelSubscriptionGateService $subscriptionGate,
    ) {}

    public function store(GroupChannelBot $bot, array $update): GroupChannelWebhookUpdate
    {
        $updateId = $update['update_id'] ?? null;
        if (! is_int($updateId) && ! ctype_digit((string) $updateId)) {
            throw new RuntimeException('Telegram update_id отсутствует или имеет неверный формат.');
        }

        return GroupChannelWebhookUpdate::query()->firstOrCreate([
            'group_channel_bot_id' => $bot->id,
            'telegram_update_id' => (int) $updateId,
        ], [
            'payload' => $update,
            'status' => GroupChannelWebhookUpdate::STATUS_PENDING,
        ]);
    }

    public function process(GroupChannelWebhookUpdate $storedUpdate): void
    {
        $claimed = DB::transaction(function () use ($storedUpdate): ?GroupChannelWebhookUpdate {
            $update = GroupChannelWebhookUpdate::query()
                ->with('bot')
                ->lockForUpdate()
                ->findOrFail($storedUpdate->id);

            if ($update->status === GroupChannelWebhookUpdate::STATUS_PROCESSED) {
                return null;
            }

            if (
                $update->status === GroupChannelWebhookUpdate::STATUS_PROCESSING
                && $update->updated_at->gt(now()->subMinutes(2))
            ) {
                throw new RuntimeException('Telegram update уже обрабатывается.');
            }

            $update->forceFill([
                'status' => GroupChannelWebhookUpdate::STATUS_PROCESSING,
                'attempts' => $update->attempts + 1,
                'last_error' => null,
            ])->save();

            return $update;
        });

        if ($claimed === null) {
            return;
        }

        try {
            $bot = $claimed->bot;
            if (! $bot || ! $bot->is_active) {
                throw new RuntimeException('Бот для Telegram update отключён или удалён.');
            }

            $update = $this->normalize($claimed->payload ?? []);
            $update = $this->subscriptionGate->filterUpdate($bot, $update);
            $this->webhook->handle($bot, $update);

            $claimed->forceFill([
                'status' => GroupChannelWebhookUpdate::STATUS_PROCESSED,
                'processed_at' => now(),
                'last_error' => null,
            ])->save();
            $bot->update([
                'last_update_at' => now(),
                'webhook_last_error' => null,
            ]);
        } catch (Throwable $e) {
            $claimed->forceFill([
                'status' => GroupChannelWebhookUpdate::STATUS_FAILED,
                'last_error' => $e->getMessage(),
            ])->save();
            throw $e;
        }
    }

    private function normalize(array $update): array
    {
        if (isset($update['channel_post']) && is_array($update['channel_post'])) {
            $update['message'] = $update['channel_post'];
        }

        if (isset($update['edited_channel_post']) && is_array($update['edited_channel_post'])) {
            $update['edited_message'] = $update['edited_channel_post'];
        }

        return $update;
    }
}
