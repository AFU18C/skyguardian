<?php

namespace App\Http\Controllers;

use App\Models\AlertBotSetting;
use App\Models\NewsBotSetting;
use App\Services\Telegram\WelcomeSettingsStore;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

class TelegramWelcomeController extends Controller
{
    public function update(Request $request, WelcomeSettingsStore $store): RedirectResponse
    {
        $data = $request->validate([
            'chat' => ['required', 'string', 'max:255'],
            'bot' => ['required', 'in:news,alerts'],
            'message' => ['required', 'string', 'max:2000'],
            'forbidden_words' => ['nullable', 'string', 'max:10000'],
            'forbidden_links' => ['nullable', 'string', 'max:10000'],
            'filter_action' => ['required', 'in:delete,delete_warn,mute'],
            'new_member_minutes' => ['required', 'integer', 'min:1', 'max:1440'],
            'message_limit' => ['required', 'integer', 'min:2', 'max:100'],
            'message_window_seconds' => ['required', 'integer', 'min:5', 'max:3600'],
            'antispam_action' => ['required', 'in:delete,delete_warn,mute'],
            'mute_minutes' => ['required', 'integer', 'min:1', 'max:10080'],
        ]);

        $previous = $store->get();
        $token = $this->botToken($data['bot']);
        $webhookRequired = $this->webhookRequired($request);

        if ($webhookRequired && blank($token)) {
            return back()->withErrors(['welcome' => 'У выбранного бота не сохранён токен Telegram.'])->withInput();
        }

        $settings = $store->save([
            'enabled' => $request->boolean('enabled'),
            'chat' => trim($data['chat']),
            'bot' => $data['bot'],
            'message' => trim($data['message']),
            'delete_join_messages' => $request->boolean('delete_join_messages'),
            'delete_leave_messages' => $request->boolean('delete_leave_messages'),
            'delete_pinned_messages' => $request->boolean('delete_pinned_messages'),
            'delete_group_changes' => $request->boolean('delete_group_changes'),
            'filter_enabled' => $request->boolean('filter_enabled'),
            'forbidden_words' => trim((string) ($data['forbidden_words'] ?? '')),
            'forbidden_links' => trim((string) ($data['forbidden_links'] ?? '')),
            'filter_action' => $data['filter_action'],
            'antispam_enabled' => $request->boolean('antispam_enabled'),
            'new_member_minutes' => (int) $data['new_member_minutes'],
            'block_links_for_new' => $request->boolean('block_links_for_new'),
            'message_limit' => (int) $data['message_limit'],
            'message_window_seconds' => (int) $data['message_window_seconds'],
            'antispam_action' => $data['antispam_action'],
            'mute_minutes' => (int) $data['mute_minutes'],
        ]);

        try {
            if (($previous['bot'] ?? null) !== $settings['bot']) {
                $previousToken = $this->botToken((string) ($previous['bot'] ?? ''));
                if (filled($previousToken)) {
                    $this->deleteWebhook((string) $previousToken);
                }
            }

            if ($webhookRequired) {
                $this->setWebhook((string) $token, $settings['bot'], (string) $settings['secret']);
            } elseif (filled($token)) {
                $this->deleteWebhook((string) $token);
            }
        } catch (Throwable $exception) {
            report($exception);

            return back()->withErrors([
                'welcome' => $exception->getMessage() ?: 'Не удалось настроить Telegram webhook управления группой.',
            ])->withInput();
        }

        return back()->with('status', 'Настройки управления группой сохранены.');
    }

    private function webhookRequired(Request $request): bool
    {
        return $request->boolean('enabled')
            || $request->boolean('delete_join_messages')
            || $request->boolean('delete_leave_messages')
            || $request->boolean('delete_pinned_messages')
            || $request->boolean('delete_group_changes')
            || $request->boolean('filter_enabled')
            || $request->boolean('antispam_enabled');
    }

    private function botToken(string $bot): ?string
    {
        return match ($bot) {
            'news' => NewsBotSetting::query()->first()?->bot_token,
            'alerts' => AlertBotSetting::query()->first()?->bot_token,
            default => null,
        };
    }

    private function setWebhook(string $token, string $bot, string $secret): void
    {
        $response = Http::asForm()->timeout(15)->post("https://api.telegram.org/bot{$token}/setWebhook", [
            'url' => route('telegram.welcome.webhook', ['bot' => $bot]),
            'secret_token' => $secret,
            'allowed_updates' => json_encode(['message']),
            'drop_pending_updates' => false,
        ]);

        if (! $response->successful() || ! $response->json('ok')) {
            throw new RuntimeException((string) ($response->json('description') ?: 'Telegram не принял webhook управления группой.'));
        }
    }

    private function deleteWebhook(string $token): void
    {
        $response = Http::asForm()->timeout(15)->post("https://api.telegram.org/bot{$token}/deleteWebhook", [
            'drop_pending_updates' => false,
        ]);

        if (! $response->successful() || ! $response->json('ok')) {
            throw new RuntimeException((string) ($response->json('description') ?: 'Telegram не отключил предыдущий webhook.'));
        }
    }
}
