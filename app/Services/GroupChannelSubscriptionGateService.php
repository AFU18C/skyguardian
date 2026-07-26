<?php

namespace App\Services;

use App\Models\GroupChannelBot;
use Throwable;

class GroupChannelSubscriptionGateService
{
    public function __construct(private readonly GroupChannelTelegramService $telegram) {}

    public function filterUpdate(GroupChannelBot $bot, array $update): array
    {
        if (! $bot->moduleEnabled('subscription_check')) {
            return $update;
        }

        $members = data_get($update, 'message.new_chat_members');
        if (! is_array($members) || $members === []) {
            return $update;
        }

        $allowed = [];

        foreach ($members as $member) {
            if (! is_array($member) || ! isset($member['id']) || ($member['is_bot'] ?? false)) {
                $allowed[] = $member;

                continue;
            }

            $userId = (string) $member['id'];
            if ($this->isSubscribed($bot, $userId)) {
                $allowed[] = $member;

                continue;
            }

            $this->removeFromChat($bot, $userId);
            $this->notify($bot, $member);
        }

        data_set($update, 'message.new_chat_members', $allowed);

        return $update;
    }

    private function isSubscribed(GroupChannelBot $bot, string $userId): bool
    {
        $channels = $bot->moduleSetting('subscription_check', 'channels', []);

        if (! is_array($channels) || $channels === []) {
            return true;
        }

        foreach ($channels as $channel) {
            $reference = $this->chatReference((string) $channel);
            if ($reference === '') {
                continue;
            }

            try {
                $member = $this->telegram->request($bot, 'getChatMember', [
                    'chat_id' => $reference,
                    'user_id' => $userId,
                ]);
            } catch (Throwable) {
                return false;
            }

            if (! is_array($member) || ! in_array($member['status'] ?? '', [
                'member',
                'administrator',
                'creator',
            ], true)) {
                return false;
            }
        }

        return true;
    }

    private function removeFromChat(GroupChannelBot $bot, string $userId): void
    {
        $this->telegram->request($bot, 'banChatMember', [
            'chat_id' => $bot->chat_id,
            'user_id' => $userId,
            'revoke_messages' => true,
        ]);
        $this->telegram->request($bot, 'unbanChatMember', [
            'chat_id' => $bot->chat_id,
            'user_id' => $userId,
            'only_if_banned' => true,
        ]);
    }

    private function notify(GroupChannelBot $bot, array $member): void
    {
        $channels = collect($bot->moduleSetting('subscription_check', 'channels', []))
            ->filter(fn (mixed $channel): bool => is_string($channel) && trim($channel) !== '')
            ->implode(', ');
        $name = trim((string) ($member['first_name'] ?? '').' '.(string) ($member['last_name'] ?? ''));

        try {
            $this->telegram->request($bot, 'sendMessage', [
                'chat_id' => $bot->chat_id,
                'text' => ($name !== '' ? $name.', ' : '').'вступление доступно после подписки: '.$channels,
                'disable_notification' => true,
            ]);
        } catch (Throwable) {
            // Удаление участника уже выполнено; ошибка уведомления не отменяет проверку.
        }
    }

    private function chatReference(string $value): string
    {
        $value = trim($value);

        if (str_starts_with($value, 'https://t.me/')) {
            return '@'.trim(substr($value, strlen('https://t.me/')), '/');
        }

        if ($value !== '' && ! str_starts_with($value, '@') && ! str_starts_with($value, '-100')) {
            return '@'.$value;
        }

        return $value;
    }
}
