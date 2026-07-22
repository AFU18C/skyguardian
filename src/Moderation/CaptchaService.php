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
        $items = $this->store->read('captcha');
        $items[$token] = ['chat_id' => $chatId, 'user_id' => $userId, 'expires_at' => time() + $timeoutSeconds];
        $this->store->write('captcha', $items);
        return $token;
    }

    public function confirm(string $token): bool
    {
        $items = $this->store->read('captcha');
        $item = $items[$token] ?? null;
        if (!is_array($item) || (int) $item['expires_at'] < time()) {
            return false;
        }
        $this->telegram->call('restrictChatMember', [
            'chat_id' => $item['chat_id'],
            'user_id' => $item['user_id'],
            'permissions' => ['can_send_messages' => true, 'can_send_audios' => true, 'can_send_documents' => true, 'can_send_photos' => true, 'can_send_videos' => true, 'can_send_video_notes' => true, 'can_send_voice_notes' => true, 'can_send_polls' => true, 'can_send_other_messages' => true, 'can_add_web_page_previews' => true, 'can_change_info' => false, 'can_invite_users' => true, 'can_pin_messages' => false, 'can_manage_topics' => false],
        ]);
        unset($items[$token]);
        $this->store->write('captcha', $items);
        return true;
    }

    public function expire(): int
    {
        $items = $this->store->read('captcha');
        $count = 0;
        foreach ($items as $token => $item) {
            if ((int) ($item['expires_at'] ?? 0) >= time()) continue;
            $this->telegram->call('banChatMember', ['chat_id' => $item['chat_id'], 'user_id' => $item['user_id']]);
            unset($items[$token]);
            $count++;
        }
        $this->store->write('captcha', $items);
        return $count;
    }
}
