<?php

namespace App\Services;

use App\Models\GroupChannelBot;
use Illuminate\Support\Str;
use RuntimeException;

class GroupChannelWebhookRegistrationService
{
    public const ALLOWED_UPDATES = [
        'message',
        'edited_message',
        'channel_post',
        'edited_channel_post',
        'chat_join_request',
        'callback_query',
        'my_chat_member',
    ];

    public function __construct(private readonly GroupChannelTelegramService $telegram) {}

    public function register(GroupChannelBot $bot): void
    {
        $this->ensureTokenMetadata($bot);
        $this->ensureChatId($bot);
        $newSecret = Str::random(48);

        $this->telegram->request($bot, 'setWebhook', [
            'url' => route('group-channel.webhook', [
                'fingerprint' => $bot->token_fingerprint,
            ]),
            'secret_token' => $newSecret,
            'allowed_updates' => json_encode(self::ALLOWED_UPDATES, JSON_THROW_ON_ERROR),
            'drop_pending_updates' => false,
        ]);

        GroupChannelBot::query()
            ->where('token_fingerprint', $bot->token_fingerprint)
            ->get()
            ->each(fn (GroupChannelBot $matchingBot) => $matchingBot->update([
                'webhook_secret' => $newSecret,
                'webhook_registered_at' => now(),
                'webhook_last_error' => null,
            ]));

        $bot->refresh();
    }

    private function ensureTokenMetadata(GroupChannelBot $bot): void
    {
        if ($bot->token_fingerprint && $bot->webhook_secret) {
            return;
        }

        $fingerprint = hash('sha256', (string) $bot->bot_token);
        $existing = GroupChannelBot::query()
            ->where('token_fingerprint', $fingerprint)
            ->first();

        $bot->update([
            'token_fingerprint' => $fingerprint,
            'webhook_secret' => $existing?->webhook_secret ?: Str::random(48),
        ]);
        $bot->refresh();
    }

    private function ensureChatId(GroupChannelBot $bot): void
    {
        if ($bot->chat_id) {
            return;
        }

        $chat = $this->telegram->request($bot, 'getChat', [
            'chat_id' => $this->chatReference($bot->group_link),
        ]);

        $bot->update([
            'chat_id' => (string) $chat['id'],
            'chat_type' => (string) ($chat['type'] ?? $bot->chat_type),
        ]);
        $bot->refresh();
    }

    private function chatReference(string $link): string
    {
        $path = trim((string) parse_url($link, PHP_URL_PATH), '/');

        if ($path === '' || str_starts_with($path, '+') || str_starts_with($path, 'joinchat/')) {
            throw new RuntimeException('Для подключения webhook нужна публичная ссылка вида https://t.me/username.');
        }

        return '@'.explode('/', $path)[0];
    }
}
