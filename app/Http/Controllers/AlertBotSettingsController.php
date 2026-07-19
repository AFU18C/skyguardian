<?php

namespace App\Http\Controllers;

use App\Models\AlertBotSetting;
use App\Services\Telegram\TelethonAccountService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Throwable;

class AlertBotSettingsController extends Controller
{
    public function edit(Request $request, TelethonAccountService $telethon): View
    {
        $settings = AlertBotSetting::query()->firstOrCreate([], [
            'technical_status' => 'disconnected',
            'bot_status' => 'not_configured',
            'source_status' => 'not_checked',
            'destination_status' => 'not_checked',
            'service_status' => 'stopped',
            'text_processing_enabled' => true,
        ]);

        if ($telethon->isConfigured()) {
            try {
                $this->applyAccountResult($settings, $telethon->status());
            } catch (Throwable $exception) {
                $settings->last_error = $exception->getMessage();
                $settings->save();
            }
        }

        return view('alerts.settings', [
            'settings' => $settings,
            'telegramApiConfigured' => $telethon->isConfigured(),
            'authorizationPending' => $request->session()->has('telegram_auth.phone_code_hash'),
            'passwordRequired' => $request->session()->get('telegram_auth.password_required', false),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'technical_phone' => ['nullable', 'string', 'max:32'],
            'telegram_api_id' => ['nullable', 'digits_between:4,20'],
            'telegram_api_hash' => ['nullable', 'string', 'size:32'],
            'bot_token' => ['nullable', 'string', 'max:255'],
            'administrator_telegram_id' => ['nullable', 'string', 'max:32'],
            'source_chat' => ['nullable', 'string', 'max:255'],
            'destination_chat' => ['nullable', 'string', 'max:255'],
        ]);

        $settings = AlertBotSetting::query()->firstOrCreate();
        $settings->fill([
            'technical_phone' => $validated['technical_phone'] ?? $settings->technical_phone,
            'administrator_telegram_id' => $validated['administrator_telegram_id'] ?? null,
            'source_chat' => $validated['source_chat'] ?? null,
            'destination_chat' => $validated['destination_chat'] ?? null,
            'autopublish_enabled' => $request->boolean('autopublish_enabled'),
            'text_processing_enabled' => $request->boolean('text_processing_enabled'),
        ]);

        if ($request->filled('telegram_api_id')) {
            $settings->telegram_api_id = $validated['telegram_api_id'];
        }

        if ($request->filled('telegram_api_hash')) {
            $settings->telegram_api_hash = $validated['telegram_api_hash'];
        }

        if ($request->filled('bot_token')) {
            $settings->bot_token = $validated['bot_token'];
        }

        $settings->save();

        return back()->with('status', 'Налаштування збережено.');
    }

    public function sendCode(Request $request, TelethonAccountService $telethon): RedirectResponse
    {
        $validated = $request->validate(['technical_phone' => ['required', 'string', 'max:32']]);

        try {
            $result = $telethon->sendCode($validated['technical_phone']);
            $request->session()->put('telegram_auth', [
                'phone' => $validated['technical_phone'],
                'phone_code_hash' => $result['phone_code_hash'],
                'password_required' => false,
            ]);

            AlertBotSetting::query()->firstOrCreate()->update([
                'technical_phone' => $validated['technical_phone'],
                'technical_status' => 'code_sent',
                'last_error' => null,
            ]);

            return back()->with('status', 'Код надіслано в Telegram.');
        } catch (Throwable $exception) {
            return back()->withErrors(['telegram' => $exception->getMessage()]);
        }
    }

    public function confirmCode(Request $request, TelethonAccountService $telethon): RedirectResponse
    {
        $validated = $request->validate([
            'telegram_code' => ['required', 'string', 'max:16'],
            'telegram_password' => ['nullable', 'string', 'max:255'],
        ]);

        $auth = $request->session()->get('telegram_auth');
        if (! is_array($auth) || empty($auth['phone']) || empty($auth['phone_code_hash'])) {
            return back()->withErrors(['telegram' => 'Спочатку надішліть код на номер телефону.']);
        }

        try {
            $result = $telethon->signIn(
                $auth['phone'],
                $validated['telegram_code'],
                $auth['phone_code_hash'],
                $validated['telegram_password'] ?? null,
            );

            if (($result['status'] ?? null) === 'password_required') {
                $request->session()->put('telegram_auth.password_required', true);
                return back()->withErrors(['telegram' => 'Введіть пароль двоетапної перевірки Telegram.']);
            }

            $settings = AlertBotSetting::query()->firstOrCreate();
            $this->applyAccountResult($settings, $result);
            $request->session()->forget('telegram_auth');

            return back()->with('status', 'Технічний Telegram-акаунт підключено.');
        } catch (Throwable $exception) {
            return back()->withErrors(['telegram' => $exception->getMessage()]);
        }
    }

    public function disconnect(Request $request, TelethonAccountService $telethon): RedirectResponse
    {
        try {
            $telethon->logout();
            $settings = AlertBotSetting::query()->firstOrCreate();
            $settings->update([
                'technical_status' => 'disconnected',
                'technical_name' => null,
                'technical_username' => null,
                'technical_telegram_id' => null,
            ]);
            $request->session()->forget('telegram_auth');

            return back()->with('status', 'Технічний акаунт відключено.');
        } catch (Throwable $exception) {
            return back()->withErrors(['telegram' => $exception->getMessage()]);
        }
    }

    private function applyAccountResult(AlertBotSetting $settings, array $result): void
    {
        if (($result['status'] ?? null) !== 'connected') {
            $settings->technical_status = 'disconnected';
            $settings->save();
            return;
        }

        $account = $result['account'] ?? [];
        $settings->fill([
            'technical_phone' => $account['phone'] ?? $settings->technical_phone,
            'technical_name' => $account['name'] ?? null,
            'technical_username' => $account['username'] ?? null,
            'technical_telegram_id' => $account['id'] ?? null,
            'technical_status' => 'connected',
            'last_error' => null,
        ])->save();
    }
}
