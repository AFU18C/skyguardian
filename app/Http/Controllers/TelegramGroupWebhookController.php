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

class TelegramGroupWebhookController extends Controller
{
    public function __invoke(Request $request, string $bot, WelcomeSettingsStore $store): JsonResponse
    {
        $provided = (string) $request->header('X-Telegram-Bot-Api-Secret-Token', '');
        if (! hash_equals($store->secret(), $provided)) return response()->json(['ok' => false], 403);

        $message = $request->input('message');
        if (! is_array($message)) return response()->json(['ok' => true]);

        $chatId = (string) data_get($message, 'chat.id', '');
        $username = ltrim(mb_strtolower((string) data_get($message, 'chat.username', '')), '@');
        $settings = collect($store->groups())->first(function (array $group) use ($bot, $chatId, $username): bool {
            if (($group['bot'] ?? null) !== $bot) return false;
            if ((string) ($group['chat_id'] ?? '') === $chatId) return true;
            return ltrim(mb_strtolower((string) ($group['chat'] ?? '')), '@') === $username && $username !== '';
        });
        if (! is_array($settings)) return response()->json(['ok' => true]);

        $token = $bot === 'news' ? NewsBotSetting::query()->first()?->bot_token : AlertBotSetting::query()->first()?->bot_token;
        if (blank($token)) return response()->json(['ok' => true]);

        $messageId = (int) ($message['message_id'] ?? 0);
        $members = data_get($message, 'new_chat_members', []);
        if (is_array($members) && $members !== []) {
            foreach ($members as $member) {
                if (! is_array($member) || ($member['is_bot'] ?? false)) continue;
                Cache::put("group-new-member:{$chatId}:".(int) $member['id'], now()->timestamp, now()->addMinutes(max(1, (int) $settings['new_member_minutes'])));
                if ($settings['enabled'] ?? false) {
                    $name = trim(($member['first_name'] ?? '').' '.($member['last_name'] ?? '')) ?: 'новый участник';
                    $text = strtr((string) $settings['message'], ['{name}' => $name, '{username}' => filled($member['username'] ?? null) ? '@'.$member['username'] : '', '{group}' => (string) data_get($message, 'chat.title', $settings['title'])]);
                    $this->api($token, 'sendMessage', ['chat_id' => $chatId, 'text' => Str::limit($text, 4096, '')]);
                }
            }
            if ($settings['delete_join_messages'] ?? false) $this->delete($token, $chatId, $messageId);
            return response()->json(['ok' => true]);
        }

        if (isset($message['left_chat_member']) && ($settings['delete_leave_messages'] ?? false)) $this->delete($token, $chatId, $messageId);
        if (isset($message['pinned_message']) && ($settings['delete_pinned_messages'] ?? false)) $this->delete($token, $chatId, $messageId);
        if ($this->groupChange($message) && ($settings['delete_group_changes'] ?? false)) $this->delete($token, $chatId, $messageId);

        $userId = (int) data_get($message, 'from.id', 0);
        $text = trim((string) ($message['text'] ?? $message['caption'] ?? ''));
        if ($userId <= 0 || $text === '' || $this->admin($token, $chatId, $userId)) return response()->json(['ok' => true]);

        $violation = $this->filter($text, $settings) ?: $this->spam($chatId, $userId, $text, $settings);
        if ($violation) {
            $action = $violation['type'] === 'filter' ? $settings['filter_action'] : $settings['antispam_action'];
            $this->delete($token, $chatId, $messageId);
            if (in_array($action, ['delete_warn', 'mute'], true)) $this->api($token, 'sendMessage', ['chat_id' => $chatId, 'text' => $violation['message'], 'disable_notification' => true]);
            if ($action === 'mute') $this->api($token, 'restrictChatMember', ['chat_id' => $chatId, 'user_id' => $userId, 'permissions' => json_encode(['can_send_messages' => false]), 'until_date' => now()->addMinutes(max(1, (int) $settings['mute_minutes']))->timestamp]);
        }

        return response()->json(['ok' => true]);
    }

    private function filter(string $text, array $s): ?array
    {
        if (! ($s['filter_enabled'] ?? false)) return null;
        $lower = mb_strtolower($text);
        foreach ($this->items((string) $s['forbidden_words']) as $item) if (str_contains($lower, mb_strtolower($item))) return ['type' => 'filter', 'message' => 'Сообщение удалено: запрещённое слово.'];
        foreach ($this->items((string) $s['forbidden_links']) as $item) if (str_contains($lower, mb_strtolower(preg_replace('#^https?://#i', '', $item) ?? $item))) return ['type' => 'filter', 'message' => 'Сообщение удалено: запрещённая ссылка.'];
        return null;
    }

    private function spam(string $chatId, int $userId, string $text, array $s): ?array
    {
        if (! ($s['antispam_enabled'] ?? false) || ! Cache::has("group-new-member:{$chatId}:{$userId}")) return null;
        if (($s['block_links_for_new'] ?? false) && preg_match('#(?:https?://|www\.|t\.me/)#iu', $text)) return ['type' => 'spam', 'message' => 'Новым участникам временно запрещено отправлять ссылки.'];
        $key = "group-rate:{$chatId}:{$userId}";
        Cache::add($key, 0, now()->addSeconds(max(5, (int) $s['message_window_seconds'])));
        return Cache::increment($key) > max(2, (int) $s['message_limit']) ? ['type' => 'spam', 'message' => 'Слишком много сообщений за короткое время.'] : null;
    }

    private function admin(string $token, string $chatId, int $userId): bool
    {
        try { return in_array((string) Http::asForm()->timeout(10)->post("https://api.telegram.org/bot{$token}/getChatMember", ['chat_id' => $chatId, 'user_id' => $userId])->json('result.status'), ['creator', 'administrator'], true); } catch (Throwable) { return false; }
    }

    private function delete(string $token, string $chatId, int $messageId): void { if ($messageId > 0) $this->api($token, 'deleteMessage', ['chat_id' => $chatId, 'message_id' => $messageId]); }
    private function api(string $token, string $method, array $data): void { try { Http::asForm()->timeout(15)->post("https://api.telegram.org/bot{$token}/{$method}", $data)->throw(); } catch (Throwable $e) { report($e); } }
    private function items(string $value): array { return array_values(array_filter(array_map('trim', preg_split('/[\r\n,]+/u', $value) ?: []))); }
    private function groupChange(array $m): bool { return isset($m['new_chat_title']) || isset($m['new_chat_photo']) || array_key_exists('delete_chat_photo', $m); }
}
