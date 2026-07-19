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
        ]);

        $previous = $store->get();
        $enabled = $request->boolean('enabled');
        $token = $this->botToken($data['bot']);

        if ($enabled && blank($token)) {
            return back()->withErrors(['welcome' => 'У выбранного бота не сохранён токен Telegram.'])->withInput();
        }

        $settings = $store->save([
            'enabled' => false,
            'chat' => trim($data['chat']),
            'bot' => $data['bot'],
            'message' => trim($data['message']),
        ]);

        try {
            if (($previous['bot'] ?? null) !== $settings['bot']) {
                $previousToken = $this->botToken((string) ($previous['bot'] ?? ''));
                if (filled($previousToken)) {
                    $this->deleteWebhook((string) $previousToken);
                }
            }

            if ($enabled) {
                $this->setWebhook((string) $token, $settings['bot'], (string) $settings['secret']);
                $store->save(['enabled' => true]);
            } elseif (filled($token)) {
                $this->deleteWebhook((string) $token);
            }
        } catch (Throwable $exception) {
            report($exception);
            $store->save(['enabled' => false]);

            return back()->withErrors([
                'welcome' => $exception->getMessage() ?: 'Не удалось настроить Telegram webhook приветствия.',
            ])->withInput();
        }

        return back()->with('status', $enabled
            ? 'Приветствие новых пользователей включено.'
            : 'Приветствие новых пользователей выключено.');
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
            throw new RuntimeException((string) ($response->json('description') ?: 'Telegram не принял webhook приветствия.'));
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
