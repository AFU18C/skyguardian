<?php

namespace App\Http\Controllers;

use App\Models\AlertBotSetting;
use App\Models\NewsBotSetting;
use App\Services\Telegram\WelcomeSettingsStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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

        if (! ($settings['enabled'] ?? false) || ($settings['bot'] ?? null) !== $bot) {
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

        $members = data_get($message, 'new_chat_members', []);
        if (! is_array($members) || $members === []) {
            return response()->json(['ok' => true]);
        }

        $token = $bot === 'news'
            ? NewsBotSetting::query()->first()?->bot_token
            : AlertBotSetting::query()->first()?->bot_token;

        if (blank($token)) {
            return response()->json(['ok' => true]);
        }

        $groupName = (string) data_get($message, 'chat.title', $configuredChat);

        foreach ($members as $member) {
            if (! is_array($member) || ($member['is_bot'] ?? false)) {
                continue;
            }

            $firstName = trim((string) ($member['first_name'] ?? ''));
            $lastName = trim((string) ($member['last_name'] ?? ''));
            $name = trim($firstName.' '.$lastName) ?: 'новый участник';
            $username = filled($member['username'] ?? null) ? '@'.$member['username'] : '';
            $text = strtr((string) $settings['message'], [
                '{name}' => $name,
                '{username}' => $username,
                '{group}' => $groupName,
            ]);

            try {
                Http::asForm()->timeout(15)->post("https://api.telegram.org/bot{$token}/sendMessage", [
                    'chat_id' => $chatId,
                    'text' => Str::limit($text, 4096, ''),
                    'disable_web_page_preview' => true,
                ])->throw();
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        return response()->json(['ok' => true]);
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
