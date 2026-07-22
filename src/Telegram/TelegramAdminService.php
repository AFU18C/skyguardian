<?php
declare(strict_types=1);

namespace SkyGuardian\Telegram;

final class TelegramAdminService
{
    public function __construct(private readonly BotApiClient $api) {}

    public function execute(string $action, array $input): array
    {
        $chatId = $input['chat_id'] ?? null;
        return match ($action) {
            'message-delete' => $this->api->call('deleteMessage', ['chat_id' => $chatId, 'message_id' => (int) ($input['message_id'] ?? 0)]),
            'message-pin' => $this->api->call('pinChatMessage', ['chat_id' => $chatId, 'message_id' => (int) ($input['message_id'] ?? 0), 'disable_notification' => (bool) ($input['disable_notification'] ?? false)]),
            'message-unpin' => $this->api->call('unpinChatMessage', ['chat_id' => $chatId, 'message_id' => (int) ($input['message_id'] ?? 0)]),
            'member-get' => $this->api->call('getChatMember', ['chat_id' => $chatId, 'user_id' => (int) ($input['user_id'] ?? 0)]),
            'member-restrict' => $this->api->call('restrictChatMember', ['chat_id' => $chatId, 'user_id' => (int) ($input['user_id'] ?? 0), 'until_date' => (int) ($input['until_date'] ?? 0), 'permissions' => (array) ($input['permissions'] ?? ['can_send_messages' => false])]),
            'member-unrestrict' => $this->api->call('restrictChatMember', ['chat_id' => $chatId, 'user_id' => (int) ($input['user_id'] ?? 0), 'permissions' => self::memberPermissions()]),
            'member-ban' => $this->api->call('banChatMember', ['chat_id' => $chatId, 'user_id' => (int) ($input['user_id'] ?? 0), 'until_date' => (int) ($input['until_date'] ?? 0), 'revoke_messages' => (bool) ($input['revoke_messages'] ?? true)]),
            'member-unban' => $this->api->call('unbanChatMember', ['chat_id' => $chatId, 'user_id' => (int) ($input['user_id'] ?? 0), 'only_if_banned' => true]),
            'member-promote' => $this->api->call('promoteChatMember', array_replace(['chat_id' => $chatId, 'user_id' => (int) ($input['user_id'] ?? 0)], (array) ($input['rights'] ?? []))),
            'member-demote' => $this->api->call('promoteChatMember', array_replace(['chat_id' => $chatId, 'user_id' => (int) ($input['user_id'] ?? 0)], self::demotedRights())),
            'invite-create' => $this->api->call('createChatInviteLink', array_filter(['chat_id' => $chatId, 'name' => $input['name'] ?? null, 'expire_date' => $input['expire_date'] ?? null, 'member_limit' => $input['member_limit'] ?? null, 'creates_join_request' => (bool) ($input['creates_join_request'] ?? false)], static fn(mixed $v): bool => $v !== null)),
            'invite-revoke' => $this->api->call('revokeChatInviteLink', ['chat_id' => $chatId, 'invite_link' => (string) ($input['invite_link'] ?? '')]),
            'join-approve' => $this->api->call('approveChatJoinRequest', ['chat_id' => $chatId, 'user_id' => (int) ($input['user_id'] ?? 0)]),
            'join-decline' => $this->api->call('declineChatJoinRequest', ['chat_id' => $chatId, 'user_id' => (int) ($input['user_id'] ?? 0)]),
            'group-title' => $this->api->call('setChatTitle', ['chat_id' => $chatId, 'title' => trim((string) ($input['title'] ?? ''))]),
            'group-description' => $this->api->call('setChatDescription', ['chat_id' => $chatId, 'description' => trim((string) ($input['description'] ?? ''))]),
            default => throw new \InvalidArgumentException('Unknown Telegram action.'),
        };
    }

    private static function memberPermissions(): array
    {
        return ['can_send_messages' => true, 'can_send_audios' => true, 'can_send_documents' => true, 'can_send_photos' => true, 'can_send_videos' => true, 'can_send_video_notes' => true, 'can_send_voice_notes' => true, 'can_send_polls' => true, 'can_send_other_messages' => true, 'can_add_web_page_previews' => true, 'can_change_info' => false, 'can_invite_users' => true, 'can_pin_messages' => false, 'can_manage_topics' => false];
    }

    private static function demotedRights(): array
    {
        return ['is_anonymous' => false, 'can_manage_chat' => false, 'can_delete_messages' => false, 'can_manage_video_chats' => false, 'can_restrict_members' => false, 'can_promote_members' => false, 'can_change_info' => false, 'can_invite_users' => false, 'can_post_stories' => false, 'can_edit_stories' => false, 'can_delete_stories' => false, 'can_post_messages' => false, 'can_edit_messages' => false, 'can_pin_messages' => false, 'can_manage_topics' => false];
    }
}
