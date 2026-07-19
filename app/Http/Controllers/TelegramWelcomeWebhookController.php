<?php

namespace App\Http\Controllers;

use App\Models\AlertBotSetting;
use App\Models\NewsBotSetting;
use App\Services\Telegram\WelcomeSettingsStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

class TelegramWelcomeWebhookController extends Controller
{
    public function __invoke(Request $request, string $bot, WelcomeSettingsStore $store): JsonResponse
    {
        $settings = $store->get();
        $secret = (string) ($settings['secret'] ?? '');
        $providedSecret = (string) $request->header('X-Telegram-Bot-Api-Secret-Token', '');

        if (blank($secret) || ! hash_equals($secret, $providedSecret)) {
            return response()->json(['ok' => false], 403);
        }

        if (($settings['bot'] ?? null) !== $bot) {
            return response()->json(['ok' => true]);
        }

        $message = $request->input('message');
        if (! is_array($message)) {
            return response()->json(['ok' => true]);
        }

        $configuredChat = trim((string) ($settings['chat'] ?? ''));
        $chatId = (string) data_get($message, 'chat.id', '');
        $chatUsername = (string) data_get($message, 'chat.username', '');

        if (! $this->chatMatches($configuredChat, $chatId, $chatUsername)) {
            return response()->json(['ok' => true]);
        }

        $token = $this->botToken($bot);
        if (blank($token)) {
            return response()->json(['ok' => true]);
        }

        $messageId = (int) ($message['message_id'] ?? 0);
        $members = data_get($message, 'new_chat_members', []);

        if (is_array($members) && $members !== []) {
            $this->rememberNewMembers($chatId, $members, $settings);
            $this->sendWelcomeMessages((string) $token, $chatId, $configuredChat, $message, $members, $settings);

            if ($settings['delete_join_messages'] ?? false) {
                $this->deleteMessage((string) $token, $chatId, $messageId);
            }

            return response()->json(['ok' => true]);
        }

        if (isset($message['left_chat_member']) && ($settings['delete_leave_messages'] ?? false)) {
            $this->deleteMessage((string) $token, $chatId, $messageId);
            return response()->json(['ok' => true]);
        }

        if (isset($message['pinned_message']) && ($settings['delete_pinned_messages'] ?? false)) {
            $this->deleteMessage((string) $token, $chatId, $messageId);
            return response()->json(['ok' => true]);
        }

        if ($this->isGroupChange($message) && ($settings['delete_group_changes'] ?? false)) {
            $this->deleteMessage((string) $token, $chatId, $messageId);
            return response()->json(['ok' => true]);
        }

        $userId = (int) data_get($message, 'from.id', 0);
        $text = trim((string) ($message['text'] ?? $message['caption'] ?? ''));
        if ($userId <= 0 || $text === '') {
            return response()->json(['ok' => true]);
        }

        $violation = $this->filterViolation($text, $settings);
        if ($violation === null) {
            $violation = $this->antispamViolation($chatId, $userId, $text, $settings);
        }

        if ($violation !== null && ! $this->isAdministrator((string) $token, $chatId, $userId)) {
            $action = $violation['type'] === 'filter'
                ? (string) ($settings['filter_action'] ?? 'delete_warn')
                : (string) ($settings['antispam_action'] ?? 'delete_warn');

            $this->applyAction((string) $token, $chatId, $messageId, $userId, $action, $violation['message'], $settings);
        }

        return response()->json(['ok' => true]);
    }

    private function sendWelcomeMessages(string $token, string $chatId, string $configuredChat, array $message, array $members, array $settings): void
    {
        if (! ($settings['enabled'] ?? false)) {
            return;
        }

        $groupName = (string) data_get($message, 'chat.title', $configuredChat);

        foreach ($members as $member) {
            if (! is_array($member) || ($member['is_bot'] ?? false)) {
                continue;
            }

            $name = trim((string) ($member['first_name'] ?? '').' '.(string) ($member['last_name'] ?? '')) ?: 'новый участник';
            $username = filled($member['username'] ?? null) ? '@'.$member['username'] : '';
            $text = strtr((string) $settings['message'], [
                '{name}' => $name,
                '{username}' => $username,
                '{group}' => $groupName,
            ]);

            $this->api($token, 'sendMessage', [
                'chat_id' => $chatId,
                'text' => Str::limit($text, 4096, ''),
                'disable_web_page_preview' => true,
            ]);
        }
    }

    private function rememberNewMembers(string $chatId, array $members, array $settings): void
    {
        $minutes = max(1, (int) ($settings['new_member_minutes'] ?? 30));
        foreach ($members as $member) {
            $userId = (int) ($member['id'] ?? 0);
            if ($userId > 0 && ! ($member['is_bot'] ?? false)) {
                Cache::put("group-new-member:{$chatId}:{$userId}", now()->timestamp, now()->addMinutes($minutes));
            }
        }
    }

    private function filterViolation(string $text, array $settings): ?array
    {
        if (! ($settings['filter_enabled'] ?? false)) {
            return null;
        }

        $lower = mb_strtolower($text);
        foreach ($this->items((string) ($settings['forbidden_words'] ?? '')) as $word) {
            if ($word !== '' && str_contains($lower, mb_strtolower($word))) {
                return ['type' => 'filter', 'message' => 'Сообщение удалено: запрещённое слово.'];
            }
        }

        foreach ($this->items((string) ($settings['forbidden_links'] ?? '')) as $link) {
            $normalized = mb_strtolower(preg_replace('#^https?://#i', '', $link) ?? $link);
            if ($normalized !== '' && str_contains($lower, $normalized)) {
                return ['type' => 'filter', 'message' => 'Сообщение удалено: запрещённая ссылка.'];
            }
        }

        return null;
    }

    private function antispamViolation(string $chatId, int $userId, string $text, array $settings): ?array
    {
        if (! ($settings['antispam_enabled'] ?? false)
            || ! Cache::has("group-new-member:{$chatId}:{$userId}")) {
            return null;
        }

        if (($settings['block_links_for_new'] ?? false) && preg_match('#(?:https?://|www\.|t\.me/|telegram\.me/)#iu', $text)) {
            return ['type' => 'antispam', 'message' => 'Новым участникам временно запрещено отправлять ссылки.'];
        }

        $window = max(5, (int) ($settings['message_window_seconds'] ?? 30));
        $limit = max(2, (int) ($settings['message_limit'] ?? 6));
        $key = "group-rate:{$chatId}:{$userId}";
        Cache::add($key, 0, now()->addSeconds($window));
        $count = (int) Cache::increment($key);

        return $count > $limit
            ? ['type' => 'antispam', 'message' => 'Слишком много сообщений за короткое время.']
            : null;
    }

    private function applyAction(string $token, string $chatId, int $messageId, int $userId, string $action, string $warning, array $settings): void
    {
        $this->deleteMessage($token, $chatId, $messageId);

        if ($action === 'delete_warn' || $action === 'mute') {
            $this->api($token, 'sendMessage', [
                'chat_id' => $chatId,
                'text' => $warning,
                'disable_notification' => true,
            ]);
        }

        if ($action === 'mute') {
            $minutes = max(1, (int) ($settings['mute_minutes'] ?? 10));
            $this->api($token, 'restrictChatMember', [
                'chat_id' => $chatId,
                'user_id' => $userId,
                'permissions' => json_encode([
                    'can_send_messages' => false,
                    'can_send_audios' => false,
                    'can_send_documents' => false,
                    'can_send_photos' => false,
                    'can_send_videos' => false,
                    'can_send_video_notes' => false,
                    'can_send_voice_notes' => false,
                    'can_send_polls' => false,
                    'can_send_other_messages' => false,
                    'can_add_web_page_previews' => false,
                ]),
                'until_date' => now()->addMinutes($minutes)->timestamp,
            ]);
        }
    }

    private function isAdministrator(string $token, string $chatId, int $userId): bool
    {
        try {
            $response = Http::asForm()->timeout(10)->post("https://api.telegram.org/bot{$token}/getChatMember", [
                'chat_id' => $chatId,
                'user_id' => $userId,
            ]);
            return in_array((string) $response->json('result.status'), ['creator', 'administrator'], true);
        } catch (Throwable) {
            return false;
        }
    }

    private function deleteMessage(string $token, string $chatId, int $messageId): void
    {
        if ($messageId > 0) {
            $this->api($token, 'deleteMessage', ['chat_id' => $chatId, 'message_id' => $messageId]);
        }
    }

    private function api(string $token, string $method, array $payload): void
    {
        try {
            Http::asForm()->timeout(15)->post("https://api.telegram.org/bot{$token}/{$method}", $payload)->throw();
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    private function isGroupChange(array $message): bool
    {
        return isset($message['new_chat_title'])
            || isset($message['new_chat_photo'])
            || array_key_exists('delete_chat_photo', $message)
            || isset($message['group_chat_created'])
            || isset($message['supergroup_chat_created']);
    }

    private function items(string $value): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/[\r\n,]+/u', $value) ?: [])));
    }

    private function botToken(string $bot): ?string
    {
        return $bot === 'news'
            ? NewsBotSetting::query()->first()?->bot_token
            : AlertBotSetting::query()->first()?->bot_token;
    }

    private function chatMatches(string $configuredChat, string $chatId, string $chatUsername): bool
    {
        if ($configuredChat === $chatId) {
            return true;
        }

        $normalizedConfigured = ltrim(mb_strtolower($configuredChat), '@');
        $normalizedUsername = ltrim(mb_strtolower($chatUsername), '@');

        return $normalizedConfigured !== '' && $normalizedConfigured === $normalizedUsername;
    }
}
