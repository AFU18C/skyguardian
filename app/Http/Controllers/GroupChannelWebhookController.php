<?php

namespace App\Http\Controllers;

use App\Models\GroupChannelBot;
use App\Services\GroupChannelWebhookUpdateService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Throwable;

class GroupChannelWebhookController extends Controller
{
    public function __invoke(
        Request $request,
        string $fingerprint,
        string $secret,
        GroupChannelWebhookUpdateService $service,
    ): Response {
        GroupChannelBot::query()
            ->where('token_fingerprint', $fingerprint)
            ->where('webhook_secret', $secret)
            ->firstOrFail();

        $headerSecret = $request->header('X-Telegram-Bot-Api-Secret-Token');
        abort_unless(
            is_string($headerSecret) && hash_equals($secret, $headerSecret),
            403,
        );

        $update = $request->all();
        $chatId = $this->chatId($update);
        if ($chatId === null) {
            return response('', 200);
        }

        $botQuery = GroupChannelBot::query()
            ->where('token_fingerprint', $fingerprint)
            ->where('webhook_secret', $secret)
            ->where('is_active', true);
        $historyBotId = $this->historyBotId($update);

        if ($historyBotId !== null) {
            $botQuery->whereKey($historyBotId);
        } else {
            $botQuery->where('chat_id', (string) $chatId);
        }

        $bot = $botQuery->first();

        if (! $bot) {
            return response('', 200);
        }

        try {
            $storedUpdate = $service->store($bot, $update);
            $service->process($storedUpdate);
        } catch (Throwable $e) {
            report($e);

            try {
                $bot->update([
                    'last_update_at' => now(),
                    'webhook_last_error' => $e->getMessage(),
                ]);
            } catch (Throwable $statusError) {
                report($statusError);
            }

            return response('', 500);
        }

        return response('', 200);
    }

    private function historyBotId(array $update): ?int
    {
        if (data_get($update, 'message.chat.type') === 'private') {
            $text = trim((string) data_get($update, 'message.text', ''));
            if (preg_match('/^\/start(?:@[A-Za-z0-9_]+)?\s+ah_(\d+)_/', $text, $matches)) {
                return (int) $matches[1];
            }
        }

        if (data_get($update, 'callback_query.message.chat.type') === 'private') {
            $data = (string) data_get($update, 'callback_query.data', '');
            if (preg_match('/^sg_ahr:(\d+):/', $data, $matches)) {
                return (int) $matches[1];
            }
        }

        return null;
    }

    private function chatId(array $update): int|string|null
    {
        return data_get($update, 'message.chat.id')
            ?? data_get($update, 'edited_message.chat.id')
            ?? data_get($update, 'channel_post.chat.id')
            ?? data_get($update, 'edited_channel_post.chat.id')
            ?? data_get($update, 'chat_join_request.chat.id')
            ?? data_get($update, 'callback_query.message.chat.id')
            ?? data_get($update, 'my_chat_member.chat.id');
    }
}
