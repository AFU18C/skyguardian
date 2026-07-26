<?php

namespace App\Http\Controllers;

use App\Models\GroupChannelBot;
use App\Services\GroupChannelSubscriptionGateService;
use App\Services\GroupChannelWebhookService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Throwable;

class GroupChannelWebhookController extends Controller
{
    public function __invoke(
        Request $request,
        string $fingerprint,
        string $secret,
        GroupChannelSubscriptionGateService $subscriptionGate,
        GroupChannelWebhookService $service,
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

        $bot = GroupChannelBot::query()
            ->where('token_fingerprint', $fingerprint)
            ->where('chat_id', (string) $chatId)
            ->where('is_active', true)
            ->first();

        if (! $bot) {
            return response('', 200);
        }

        try {
            $update = $subscriptionGate->filterUpdate($bot, $update);
            $service->handle($bot, $update);
            $bot->update([
                'last_update_at' => now(),
                'webhook_last_error' => null,
            ]);
        } catch (Throwable $e) {
            report($e);
            $bot->update([
                'last_update_at' => now(),
                'webhook_last_error' => $e->getMessage(),
            ]);
        }

        return response('', 200);
    }

    private function chatId(array $update): int|string|null
    {
        return data_get($update, 'message.chat.id')
            ?? data_get($update, 'edited_message.chat.id')
            ?? data_get($update, 'chat_join_request.chat.id')
            ?? data_get($update, 'callback_query.message.chat.id')
            ?? data_get($update, 'my_chat_member.chat.id');
    }
}
