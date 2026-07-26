<?php

namespace App\Services;

use App\Models\GroupChannelBot;
use App\Models\GroupChannelMessage;
use App\Models\GroupChannelUserState;
use Illuminate\Support\Arr;
use RuntimeException;
use Throwable;

class GroupChannelWebhookService
{
    public function __construct(private readonly GroupChannelTelegramService $telegram) {}

    public function handle(GroupChannelBot $bot, array $update): void
    {
        if (isset($update['callback_query'])) {
            $this->handleCallback($bot, $update['callback_query']);

            return;
        }

        if (isset($update['chat_join_request'])) {
            $this->handleJoinRequest($bot, $update['chat_join_request']);

            return;
        }

        $message = $update['message'] ?? $update['edited_message'] ?? null;
        if (is_array($message)) {
            $this->handleMessage($bot, $message);
        }
    }

    private function handleMessage(GroupChannelBot $bot, array $message): void
    {
        $messageId = (string) ($message['message_id'] ?? '');
        $from = $message['from'] ?? [];
        $userId = isset($from['id']) ? (string) $from['id'] : null;
        $text = trim((string) ($message['text'] ?? $message['caption'] ?? ''));
        $hasLink = $this->hasLink($text, $message);

        if ($messageId !== '') {
            GroupChannelMessage::query()->updateOrCreate([
                'group_channel_bot_id' => $bot->id,
                'telegram_message_id' => $messageId,
            ], [
                'telegram_user_id' => $userId,
                'username' => $from['username'] ?? null,
                'text' => $text !== '' ? $text : null,
                'has_link' => $hasLink,
                'telegram_created_at' => isset($message['date'])
                    ? now()->setTimestamp((int) $message['date'])
                    : now(),
            ]);
        }

        foreach ($message['new_chat_members'] ?? [] as $member) {
            if (! is_array($member) || ! isset($member['id'])) {
                continue;
            }

            $state = GroupChannelUserState::query()->updateOrCreate([
                'group_channel_bot_id' => $bot->id,
                'telegram_user_id' => (string) $member['id'],
            ], [
                'joined_at' => now(),
                'verified_at' => null,
                'verification_answer' => null,
                'verification_expires_at' => null,
            ]);

            if (! ($member['is_bot'] ?? false)) {
                $this->sendWelcome($bot, $member);
                $this->beginVerification($bot, $member, $state);
            }
        }

        if (! $userId || ($from['is_bot'] ?? false)) {
            return;
        }

        $state = GroupChannelUserState::query()->firstOrCreate([
            'group_channel_bot_id' => $bot->id,
            'telegram_user_id' => $userId,
        ]);

        if ($bot->moduleEnabled('human_verification') && ! $state->verified_at) {
            if ($this->completeVerificationFromMessage($bot, $message, $state, $text)) {
                return;
            }

            if ($state->verification_expires_at?->isPast()) {
                $this->telegram->request($bot, 'banChatMember', [
                    'chat_id' => $bot->chat_id,
                    'user_id' => $userId,
                    'revoke_messages' => true,
                ]);

                return;
            }

            $this->deleteMessage($bot, $messageId, 'human_verification');

            return;
        }

        $rule = $this->matchedRule($bot, $message, $state, $text, $hasLink);
        if ($rule !== null) {
            $this->deleteMessage($bot, $messageId, $rule);
            $this->applyWarning($bot, $userId, $state, $rule);

            return;
        }

        $state->update([
            'last_message_at' => now(),
            'last_text_hash' => $text !== '' ? hash('sha256', mb_strtolower($text)) : null,
        ]);
    }

    private function handleJoinRequest(GroupChannelBot $bot, array $request): void
    {
        if (! $bot->moduleEnabled('join_requests')) {
            return;
        }

        $user = $request['from'] ?? [];
        $userId = isset($user['id']) ? (string) $user['id'] : null;
        if (! $userId) {
            return;
        }

        if (($user['is_bot'] ?? false) && $bot->moduleSetting('join_requests', 'auto_decline_bots', true)) {
            $this->telegram->request($bot, 'declineChatJoinRequest', [
                'chat_id' => $bot->chat_id,
                'user_id' => $userId,
            ]);

            return;
        }

        if ($bot->moduleEnabled('subscription_check') && ! $this->isSubscribed($bot, $userId)) {
            $this->telegram->request($bot, 'declineChatJoinRequest', [
                'chat_id' => $bot->chat_id,
                'user_id' => $userId,
            ]);

            return;
        }

        if ($bot->moduleSetting('join_requests', 'auto_approve', false)) {
            $this->telegram->request($bot, 'approveChatJoinRequest', [
                'chat_id' => $bot->chat_id,
                'user_id' => $userId,
            ]);
        }
    }

    private function handleCallback(GroupChannelBot $bot, array $callback): void
    {
        $data = (string) ($callback['data'] ?? '');
        $callbackId = $callback['id'] ?? null;
        $fromId = isset($callback['from']['id']) ? (string) $callback['from']['id'] : null;

        if (! str_starts_with($data, 'sg_verify:')) {
            return;
        }

        [, $botId, $userId] = array_pad(explode(':', $data, 3), 3, null);
        if ((string) $bot->id !== (string) $botId || $fromId !== (string) $userId) {
            if ($callbackId) {
                $this->telegram->request($bot, 'answerCallbackQuery', [
                    'callback_query_id' => $callbackId,
                    'text' => 'Эта кнопка предназначена другому пользователю.',
                    'show_alert' => true,
                ]);
            }

            return;
        }

        $state = GroupChannelUserState::query()->firstOrCreate([
            'group_channel_bot_id' => $bot->id,
            'telegram_user_id' => $userId,
        ]);
        $this->markVerified($bot, $state);

        if ($callbackId) {
            $this->telegram->request($bot, 'answerCallbackQuery', [
                'callback_query_id' => $callbackId,
                'text' => 'Проверка пройдена.',
            ]);
        }
    }

    private function matchedRule(
        GroupChannelBot $bot,
        array $message,
        GroupChannelUserState $state,
        string $text,
        bool $hasLink,
    ): ?string {
        $hasFile = Arr::hasAny($message, [
            'document', 'photo', 'video', 'audio', 'animation', 'voice', 'video_note', 'sticker',
        ]);
        $newcomerMinutes = (int) $bot->moduleSetting('newcomer_restrictions', 'minutes', 10);
        $isNewcomer = $state->joined_at && $state->joined_at->gt(now()->subMinutes($newcomerMinutes));

        if ($bot->moduleEnabled('newcomer_restrictions') && $isNewcomer) {
            if ($bot->moduleSetting('newcomer_restrictions', 'block_messages', false)) {
                return 'newcomer_messages';
            }
            if ($hasFile && $bot->moduleSetting('newcomer_restrictions', 'block_files', false)) {
                return 'newcomer_files';
            }
            if ($hasLink && $bot->moduleSetting('newcomer_restrictions', 'block_links', true)) {
                return 'newcomer_links';
            }
        }

        if (! $bot->moduleEnabled('antispam')) {
            return $this->rateLimitRule($bot, $state);
        }

        if ($hasLink && $bot->moduleSetting('antispam', 'delete_links', false)) {
            return 'links';
        }

        if ($isNewcomer && $bot->moduleSetting('antispam', 'delete_new_member_messages', false)) {
            $minutes = (int) $bot->moduleSetting('antispam', 'new_member_minutes', 10);
            if ($state->joined_at?->gt(now()->subMinutes($minutes))) {
                return 'new_member_message';
            }
        }

        $normalized = mb_strtolower($text);
        foreach ($bot->moduleSetting('antispam', 'forbidden_words', []) as $word) {
            if ($word !== '' && str_contains($normalized, mb_strtolower((string) $word))) {
                return 'forbidden_word';
            }
        }

        $maxMentions = (int) $bot->moduleSetting('antispam', 'max_mentions', 0);
        if ($maxMentions > 0 && preg_match_all('/@[A-Za-z0-9_]{3,}/u', $text) > $maxMentions) {
            return 'mass_mentions';
        }

        if (
            $bot->moduleSetting('antispam', 'delete_short_messages', false)
            && ! $hasFile
            && mb_strlen(trim($text)) < (int) $bot->moduleSetting('antispam', 'min_length', 2)
        ) {
            return 'short_message';
        }

        if (
            $bot->moduleSetting('antispam', 'suspicious_symbols', false)
            && preg_match('/(.)\1{8,}|[^\pL\pN\s\pP]{6,}/u', $text)
        ) {
            return 'suspicious_symbols';
        }

        $hash = $text !== '' ? hash('sha256', $normalized) : null;
        if (
            $hash
            && $bot->moduleSetting('antispam', 'block_duplicates', false)
            && hash_equals((string) $state->last_text_hash, $hash)
        ) {
            return 'duplicate';
        }

        return $this->rateLimitRule($bot, $state);
    }

    private function rateLimitRule(GroupChannelBot $bot, GroupChannelUserState $state): ?string
    {
        $limits = [];

        if ($bot->moduleEnabled('antispam')) {
            $limit = (int) $bot->moduleSetting('antispam', 'message_limit', 0);
            if ($limit > 0) {
                $limits[] = [$limit, (int) $bot->moduleSetting('antispam', 'message_limit_period_seconds', 60)];
            }
        }

        if ($bot->moduleEnabled('slow_mode')) {
            $limit = (int) $bot->moduleSetting('slow_mode', 'messages', 0);
            if ($limit > 0) {
                $limits[] = [$limit, (int) $bot->moduleSetting('slow_mode', 'period_seconds', 60)];
            }
        }

        if ($limits === []) {
            return null;
        }

        [$limit, $seconds] = collect($limits)->sortBy(fn (array $item): float => $item[0] / $item[1])->first();
        $windowExpired = ! $state->window_started_at
            || $state->window_started_at->lte(now()->subSeconds($seconds));
        $count = $windowExpired ? 1 : $state->window_message_count + 1;

        $state->update([
            'window_started_at' => $windowExpired ? now() : $state->window_started_at,
            'window_message_count' => $count,
        ]);

        return $count > $limit ? 'flood' : null;
    }

    private function sendWelcome(GroupChannelBot $bot, array $member): void
    {
        if (! $bot->moduleEnabled('welcome')) {
            return;
        }

        $name = trim((string) ($member['first_name'] ?? '').' '.(string) ($member['last_name'] ?? ''));
        $text = strtr((string) $bot->moduleSetting('welcome', 'text', ''), [
            '{name}' => $name !== '' ? $name : 'пользователь',
            '{username}' => isset($member['username']) ? '@'.$member['username'] : '',
            '{rules}' => (string) $bot->moduleSetting('welcome', 'rules', ''),
        ]);
        $buttons = $bot->moduleSetting('welcome', 'buttons', []);
        $payload = array_filter([
            'chat_id' => $bot->chat_id,
            'text' => $text !== '' ? $text : 'Добро пожаловать!',
            'reply_markup' => $buttons ? json_encode(['inline_keyboard' => $buttons], JSON_UNESCAPED_UNICODE) : null,
        ], fn (mixed $value): bool => $value !== null);
        $result = $this->telegram->request($bot, 'sendMessage', $payload);
        $deleteAfter = $bot->moduleSetting('welcome', 'delete_after_minutes');

        GroupChannelMessage::query()->updateOrCreate([
            'group_channel_bot_id' => $bot->id,
            'telegram_message_id' => (string) ($result['message_id'] ?? ''),
        ], [
            'text' => $payload['text'],
            'telegram_created_at' => now(),
            'delete_at' => $deleteAfter ? now()->addMinutes((int) $deleteAfter) : null,
        ]);
    }

    private function beginVerification(
        GroupChannelBot $bot,
        array $member,
        GroupChannelUserState $state,
    ): void {
        if (! $bot->moduleEnabled('human_verification')) {
            $state->update(['verified_at' => now()]);

            return;
        }

        $mode = (string) $bot->moduleSetting('human_verification', 'mode', 'button');
        $timeout = (int) $bot->moduleSetting('human_verification', 'timeout_minutes', 5);
        $answer = null;
        $text = 'Подтвердите, что вы человек.';
        $replyMarkup = null;

        if ($mode === 'question') {
            $text = (string) $bot->moduleSetting('human_verification', 'question', 'Ответьте на контрольный вопрос.');
            $answer = (string) $bot->moduleSetting('human_verification', 'answer', '');
        } elseif ($mode === 'captcha') {
            $left = random_int(1, 9);
            $right = random_int(1, 9);
            $text = "Решите пример: {$left} + {$right} = ?";
            $answer = (string) ($left + $right);
        } else {
            $replyMarkup = json_encode(['inline_keyboard' => [[[
                'text' => 'Я человек',
                'callback_data' => 'sg_verify:'.$bot->id.':'.$member['id'],
            ]]]], JSON_UNESCAPED_UNICODE);

            $this->restrict($bot, (string) $member['id'], null, false);
        }

        $state->update([
            'verification_answer' => $answer,
            'verification_expires_at' => now()->addMinutes($timeout),
        ]);

        $this->telegram->request($bot, 'sendMessage', array_filter([
            'chat_id' => $bot->chat_id,
            'text' => $text,
            'reply_markup' => $replyMarkup,
        ], fn (mixed $value): bool => $value !== null));
    }

    private function completeVerificationFromMessage(
        GroupChannelBot $bot,
        array $message,
        GroupChannelUserState $state,
        string $text,
    ): bool {
        $mode = (string) $bot->moduleSetting('human_verification', 'mode', 'button');
        if ($mode === 'button' || ! $state->verification_answer) {
            return false;
        }

        if (mb_strtolower(trim($text)) !== mb_strtolower(trim((string) $state->verification_answer))) {
            return false;
        }

        $this->markVerified($bot, $state);
        $this->deleteMessage($bot, (string) ($message['message_id'] ?? ''), null);
        $this->telegram->request($bot, 'sendMessage', [
            'chat_id' => $bot->chat_id,
            'text' => 'Проверка пройдена.',
        ]);

        return true;
    }

    private function markVerified(GroupChannelBot $bot, GroupChannelUserState $state): void
    {
        $state->update([
            'verified_at' => now(),
            'verification_answer' => null,
            'verification_expires_at' => null,
        ]);
        $this->restrict($bot, $state->telegram_user_id, null, true);
    }

    private function applyWarning(
        GroupChannelBot $bot,
        string $userId,
        GroupChannelUserState $state,
        string $rule,
    ): void {
        if (! $bot->moduleEnabled('warnings')) {
            return;
        }

        $warnings = $state->warnings + 1;
        $state->update(['warnings' => $warnings]);
        $banAfter = (int) $bot->moduleSetting('warnings', 'ban_after', 3);
        $muteAfter = (int) $bot->moduleSetting('warnings', 'mute_after', 2);

        if ($warnings >= $banAfter) {
            $this->telegram->request($bot, 'banChatMember', [
                'chat_id' => $bot->chat_id,
                'user_id' => $userId,
                'revoke_messages' => true,
            ]);
            $action = 'бан';
        } elseif ($warnings >= $muteAfter) {
            $minutes = (int) $bot->moduleSetting('warnings', 'mute_minutes', 60);
            $until = now()->addMinutes($minutes);
            $this->restrict($bot, $userId, $until->timestamp, false);
            $state->update(['muted_until' => $until]);
            $action = 'мут на '.$minutes.' мин.';
        } else {
            $action = 'предупреждение '.$warnings;
        }

        $this->telegram->request($bot, 'sendMessage', [
            'chat_id' => $bot->chat_id,
            'text' => "Пользователь {$userId}: {$action}. Причина: {$rule}.",
        ]);
    }

    private function isSubscribed(GroupChannelBot $bot, string $userId): bool
    {
        foreach ($bot->moduleSetting('subscription_check', 'channels', []) as $channel) {
            $reference = trim((string) $channel);
            if ($reference === '') {
                continue;
            }
            if (str_starts_with($reference, 'https://t.me/')) {
                $reference = '@'.trim(substr($reference, strlen('https://t.me/')), '/');
            } elseif (! str_starts_with($reference, '@') && ! str_starts_with($reference, '-100')) {
                $reference = '@'.$reference;
            }

            try {
                $member = $this->telegram->request($bot, 'getChatMember', [
                    'chat_id' => $reference,
                    'user_id' => $userId,
                ]);
            } catch (Throwable) {
                return false;
            }

            if (! in_array($member['status'] ?? '', ['member', 'administrator', 'creator'], true)) {
                return false;
            }
        }

        return true;
    }

    private function deleteMessage(GroupChannelBot $bot, string $messageId, ?string $rule): void
    {
        if ($messageId === '') {
            return;
        }

        $this->telegram->request($bot, 'deleteMessage', [
            'chat_id' => $bot->chat_id,
            'message_id' => $messageId,
        ]);

        GroupChannelMessage::query()
            ->where('group_channel_bot_id', $bot->id)
            ->where('telegram_message_id', $messageId)
            ->update([
                'matched_rule' => $rule,
                'deleted_at_telegram' => now(),
            ]);
    }

    private function restrict(
        GroupChannelBot $bot,
        string $userId,
        ?int $untilDate,
        bool $allow,
    ): void {
        $permissions = [
            'can_send_messages' => $allow,
            'can_send_audios' => $allow,
            'can_send_documents' => $allow,
            'can_send_photos' => $allow,
            'can_send_videos' => $allow,
            'can_send_video_notes' => $allow,
            'can_send_voice_notes' => $allow,
            'can_send_polls' => $allow,
            'can_send_other_messages' => $allow,
            'can_add_web_page_previews' => $allow,
            'can_change_info' => false,
            'can_invite_users' => $allow,
            'can_pin_messages' => false,
            'can_manage_topics' => false,
        ];

        $this->telegram->request($bot, 'restrictChatMember', array_filter([
            'chat_id' => $bot->chat_id,
            'user_id' => $userId,
            'permissions' => json_encode($permissions),
            'until_date' => $untilDate,
        ], fn (mixed $value): bool => $value !== null));
    }

    private function hasLink(string $text, array $message): bool
    {
        if (preg_match('~(?:https?://|www\.|t\.me/|telegram\.me/)~iu', $text)) {
            return true;
        }

        return collect(array_merge($message['entities'] ?? [], $message['caption_entities'] ?? []))
            ->contains(fn (mixed $entity): bool => in_array($entity['type'] ?? '', ['url', 'text_link'], true));
    }
}
