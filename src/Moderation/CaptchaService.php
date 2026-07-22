<?php
declare(strict_types=1);

namespace SkyGuardian\Moderation;

use SkyGuardian\Storage\JsonStore;
use SkyGuardian\Telegram\BotApiClient;

final class CaptchaService
{
    public function __construct(private readonly JsonStore $store, private readonly BotApiClient $telegram) {}

    public function create(string $chatId, int $userId, int $timeoutSeconds): string
    {
        $token = bin2hex(random_bytes(16));
        $this->store->update('captcha', static function (array $items) use ($token, $chatId, $userId, $timeoutSeconds): array {
            $items[$token] = ['chat_id' => $chatId, 'user_id' => $userId, 'expires_at' => time() + max(30, $timeoutSeconds)];
            return $items;
        });
        return $token;
    }

    public function confirm(string $token): bool
    {
        return $this->confirmForUser($token, null);
    }

    public function confirmForUser(string $token, ?int $callbackUserId): bool
    {
        $accepted = null;
        $this->store->update('captcha', static function (array $items) use ($token, $callbackUserId, &$accepted): array {
            $item = $items[$token] ?? null;
            if (!is_array($item) || (int) ($item['expires_at'] ?? 0) < time()) return $items;
            if ($callbackUserId !== null && (int) ($item['user_id'] ?? 0) !== $callbackUserId) return $items;
            $accepted = $item;
            unset($items[$token]);
            return $items;
        });
        if (!is_array($accepted)) return false;
        $this->telegram->call('restrictChatMember', [
            'chat_id' => $accepted['chat_id'],
            'user_id' => $accepted['user_id'],
            'permissions' => ['can_send_messages' => true, 'can_send_audios' => true, 'can_send_documents' => true, 'can_send_photos' => true, 'can_send_videos' => true, 'can_send_video_notes' => true, 'can_send_voice_notes' => true, 'can_send_polls' => true, 'can_send_other_messages' => true, 'can_add_web_page_previews' => true, 'can_invite_users' => true],
        ]);
        return true;
    }

    public function expire(): int
    {
        $expired = [];
        $this->store->update('captcha', static function (array $items) use (&$expired): array {
            foreach ($items as $token => $item) {
                if ((int) ($item['expires_at'] ?? 0) >= time()) continue;
                $expired[] = $item;
                unset($items[$token]);
            }
            return $items;
        });
        foreach ($expired as $item) {
            $this->telegram->call('banChatMember', ['chat_id' => $item['chat_id'], 'user_id' => $item['user_id'], 'revoke_messages' => false]);
            $this->telegram->call('unbanChatMember', ['chat_id' => $item['chat_id'], 'user_id' => $item['user_id'], 'only_if_banned' => true]);
        }
        return count($expired);
    }
}
