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

            if (in_array($update->status, [
                GroupChannelWebhookUpdate::STATUS_PROCESSED,
                GroupChannelWebhookUpdate::STATUS_DEAD_LETTER,
            ], true)) {
                return null;
            }

            if ($update->attempts >= GroupChannelWebhookUpdate::MAX_ATTEMPTS) {
                $update->forceFill([
                    'status' => GroupChannelWebhookUpdate::STATUS_DEAD_LETTER,
                    'dead_letter_at' => $update->dead_letter_at ?? now(),
                    'next_attempt_at' => null,
                ])->save();

                return null;
            }

            if ($update->status === GroupChannelWebhookUpdate::STATUS_PROCESSING
                && $update->updated_at->gt(now()->subMinutes(2))) {
                return null;
            }

            if ($update->status === GroupChannelWebhookUpdate::STATUS_FAILED
                && $update->next_attempt_at?->isFuture()) {
                return null;
            }

            $update->forceFill([
                'status' => GroupChannelWebhookUpdate::STATUS_PROCESSING,
                'attempts' => $update->attempts + 1,
                'last_error' => null,
                'next_attempt_at' => null,
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
                'next_attempt_at' => null,
                'dead_letter_at' => null,
            ])->save();
            $bot->update([
                'webhook_last_error' => null,
            ]);
        } catch (Throwable $e) {
            $isDeadLetter = $claimed->attempts >= GroupChannelWebhookUpdate::MAX_ATTEMPTS;
            $delaySeconds = min(900, 5 * (2 ** max(0, $claimed->attempts - 1)));

            $claimed->forceFill([
                'status' => $isDeadLetter
                    ? GroupChannelWebhookUpdate::STATUS_DEAD_LETTER
                    : GroupChannelWebhookUpdate::STATUS_FAILED,
                'last_error' => mb_substr($e->getMessage(), 0, 4000),
                'next_attempt_at' => $isDeadLetter ? null : now()->addSeconds($delaySeconds),
                'dead_letter_at' => $isDeadLetter ? now() : null,
            ])->save();

            $claimed->bot?->update([
                'webhook_last_error' => $isDeadLetter
                    ? 'Webhook update #'.$claimed->telegram_update_id.' перемещён в dead-letter: '.$e->getMessage()
                    : $e->getMessage(),
            ]);

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
