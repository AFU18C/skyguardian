<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\GroupChannelBot;
use App\Services\GroupChannelTelegramService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class GroupChannelCheckController extends Controller
{
    public function __construct(private readonly GroupChannelTelegramService $telegram) {}

    public function __invoke(GroupChannelBot $groupChannelBot): RedirectResponse
    {
        try {
            $this->ensureTokenMetadata($groupChannelBot);
            $me = $this->telegram->request($groupChannelBot, 'getMe');
            $chat = $this->telegram->request($groupChannelBot, 'getChat', [
                'chat_id' => $this->chatReference($groupChannelBot->group_link),
            ]);
            $member = $this->telegram->request($groupChannelBot, 'getChatMember', [
                'chat_id' => $chat['id'],
                'user_id' => $me['id'],
            ]);

            $permissions = [
                'send_messages' => in_array($member['status'] ?? '', ['creator', 'administrator', 'member'], true),
                'delete_messages' => (bool) ($member['can_delete_messages'] ?? false),
                'pin_messages' => (bool) ($member['can_pin_messages'] ?? false),
                'restrict_members' => (bool) ($member['can_restrict_members'] ?? false),
                'invite_users' => (bool) ($member['can_invite_users'] ?? false),
                'manage_chat' => (bool) ($member['can_manage_chat'] ?? false),
                'manage_topics' => (bool) ($member['can_manage_topics'] ?? false),
                'manage_video_chats' => (bool) ($member['can_manage_video_chats'] ?? false),
                'post_messages' => (bool) ($member['can_post_messages'] ?? false),
                'edit_messages' => (bool) ($member['can_edit_messages'] ?? false),
                'is_administrator' => in_array($member['status'] ?? '', ['creator', 'administrator'], true),
            ];

            $groupChannelBot->update([
                'chat_id' => (string) $chat['id'],
                'chat_type' => (string) ($chat['type'] ?? $groupChannelBot->chat_type),
                'bot_username' => $me['username'] ?? null,
                'status' => $permissions['is_administrator'] ? 'connected' : 'limited',
                'permissions' => $permissions,
                'last_error' => null,
                'last_manual_check_at' => now(),
            ]);

            return $this->backToManagement($groupChannelBot, [
                'type' => $permissions['is_administrator'] ? 'success' : 'warning',
                'title' => 'Проверка завершена',
                'message' => $permissions['is_administrator']
                    ? 'Подключение и права бота обновлены.'
                    : 'Бот найден, но у него недостаточно прав администратора.',
            ]);
        } catch (Throwable $e) {
            report($e);
            $groupChannelBot->update([
                'status' => 'error',
                'last_error' => $e->getMessage(),
                'last_manual_check_at' => now(),
            ]);

            return $this->backToManagement($groupChannelBot, [
                'type' => 'error',
                'title' => 'Ошибка проверки',
                'message' => $e->getMessage(),
            ]);
        }
    }

    private function backToManagement(GroupChannelBot $bot, array $toast): RedirectResponse
    {
        return back()->with([
            'toast' => $toast,
            'open_group_channel_manage' => $bot->id,
            'open_group_channel_scroll' => '.sg-bot-rights',
        ]);
    }

    private function ensureTokenMetadata(GroupChannelBot $bot): void
    {
        if ($bot->token_fingerprint && $bot->webhook_secret) {
            return;
        }

        $fingerprint = hash('sha256', (string) $bot->bot_token);
        $existing = GroupChannelBot::query()->where('token_fingerprint', $fingerprint)->first();
        $bot->update([
            'token_fingerprint' => $fingerprint,
            'webhook_secret' => $existing?->webhook_secret ?: Str::random(48),
        ]);
        $bot->refresh();
    }

    private function chatReference(string $link): string
    {
        $path = trim((string) parse_url($link, PHP_URL_PATH), '/');

        if ($path === '' || str_starts_with($path, '+') || str_starts_with($path, 'joinchat/')) {
            throw new RuntimeException('Для ручной проверки нужна публичная ссылка вида https://t.me/username.');
        }

        return '@'.explode('/', $path)[0];
    }
}
