<?php

namespace App\Http\Controllers;

use App\Models\AlertBotSetting;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Throwable;

class AlertBotSettingsController extends Controller
{
    public function edit(): View
    {
        $settings = AlertBotSetting::query()->first();
        $token = trim((string) ($settings?->telegram_bot_token ?? ''));

        return view('pages.alerts.settings', [
            'settings' => $settings,
            'maskedToken' => $token !== '' ? $this->maskToken($token) : null,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'telegram_bot_token' => ['nullable', 'string', 'max:255'],
            'is_enabled' => ['nullable', 'boolean'],
        ]);

        $settings = AlertBotSetting::query()->firstOrNew();
        $token = trim((string) ($validated['telegram_bot_token'] ?? ''));
        $isEnabled = $request->boolean('is_enabled');
        $storedToken = trim((string) ($settings->telegram_bot_token ?? ''));

        if ($isEnabled && $token === '' && $storedToken === '') {
            throw ValidationException::withMessages([
                'telegram_bot_token' => 'Чтобы включить бота, сначала укажите Telegram Bot Token.',
            ]);
        }

        if ($token !== '') {
            $settings->telegram_bot_token = $token;
        }

        $settings->is_enabled = $isEnabled;
        $settings->save();

        return to_route('alerts.settings')->with('success', 'Настройки бота сохранены.');
    }

    public function destroyToken(): RedirectResponse
    {
        $settings = AlertBotSetting::query()->first();

        if (! $settings || trim((string) $settings->telegram_bot_token) === '') {
            return to_route('alerts.settings')->with('error', 'Сохранённый токен не найден.');
        }

        $settings->telegram_bot_token = null;
        $settings->is_enabled = false;
        $settings->save();

        return to_route('alerts.settings')->with('success', 'Токен удалён. Бот автоматически выключен.');
    }

    public function test(Request $request): RedirectResponse
    {
        $request->validate([
            'telegram_bot_token' => ['nullable', 'string', 'max:255'],
        ]);

        $settings = AlertBotSetting::query()->first();
        $token = trim((string) $request->input('telegram_bot_token'));

        if ($token === '') {
            $token = trim((string) ($settings?->telegram_bot_token ?? ''));
        }

        if ($token === '') {
            return to_route('alerts.settings')->with('error', 'Сначала укажите Telegram Bot Token.');
        }

        try {
            $response = Http::timeout(10)
                ->acceptJson()
                ->get("https://api.telegram.org/bot{$token}/getMe");

            if (! $response->successful() || ! $response->json('ok')) {
                return to_route('alerts.settings')->with('error', 'Telegram не подтвердил токен. Проверьте его и повторите попытку.');
            }

            $username = $response->json('result.username');
            $message = $username
                ? "Подключение успешно. Бот: @{$username}."
                : 'Подключение к Telegram успешно.';

            return to_route('alerts.settings')->with('success', $message);
        } catch (ConnectionException) {
            return to_route('alerts.settings')->with('error', 'Не удалось подключиться к Telegram. Проверьте интернет-соединение сервера.');
        } catch (Throwable $exception) {
            report($exception);

            return to_route('alerts.settings')->with('error', 'Ошибка проверки Telegram-бота. Повторите попытку позже.');
        }
    }

    private function maskToken(string $token): string
    {
        $length = mb_strlen($token);

        if ($length <= 10) {
            return str_repeat('•', $length);
        }

        return mb_substr($token, 0, 6)
            . str_repeat('•', max(8, $length - 10))
            . mb_substr($token, -4);
    }
}
