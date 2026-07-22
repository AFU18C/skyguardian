<?php
declare(strict_types=1);

namespace SkyGuardian\Moderation;

use SkyGuardian\Telegram\BotApiClient;

final class ModerationService
{
    public function __construct(
        private readonly BotApiClient $telegram,
        private readonly MessageInspector $inspector,
        private readonly SpamGuard $spam
    ) {}

    public function handle(array $message, array $settings, bool $isAdmin): ?string
    {
        if ($isAdmin && ($settings['admin_bypass'] ?? true)) return null;
        $chatId = (string) ($message['chat']['id'] ?? '');
        $userId = (int) ($message['from']['id'] ?? 0);
        $messageId = (int) ($message['message_id'] ?? 0);
        $reason = null;
        if (($settings['anti_spam'] ?? false) && $this->spam->isSpam($chatId, $userId)) {
            $reason = 'spam';
        }
        $reason ??= $this->inspector->inspect((string) ($message['text'] ?? $message['caption'] ?? ''), $settings);
        if ($reason === null) return null;
        $this->telegram->call('deleteMessage', ['chat_id' => $chatId, 'message_id' => $messageId]);
        $mute = max(0, (int) ($settings['mute_seconds'] ?? 0));
        if ($mute > 0) {
            $this->telegram->call('restrictChatMember', [
                'chat_id' => $chatId,
                'user_id' => $userId,
                'until_date' => time() + $mute,
                'permissions' => ['can_send_messages' => false],
            ]);
        }
        return $reason;
    }
}
