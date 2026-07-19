<?php

namespace App\Http\Controllers;

use App\Models\NewsBotSetting;
use App\Models\TechnicalTelegramAccount;
use App\Models\TelegramApiCredential;
use App\Services\Telegram\TelethonAccountService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class NewsBotSettingsController extends Controller
{
    public function edit(Request $request, TelethonAccountService $telethon): View
    {
        return view('news.settings', [
            'settings' => $this->settings(),
            'telegramApis' => TelegramApiCredential::query()->orderByDesc('is_primary')->orderBy('id')->get(),
            'technicalAccounts' => TechnicalTelegramAccount::query()
                ->with('telegramApiCredential')
                ->orderByDesc('is_primary')
                ->orderBy('id')
                ->get(),
            'telegramApiConfigured' => $telethon->isConfigured(),
            'authorizationPending' => $request->session()->has('telegram_auth.phone_code_hash'),
            'passwordRequired' => $request->session()->get('telegram_auth.password_required', false),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'bot_token' => ['nullable', 'string', 'max:255'],
            'administrator_telegram_id' => ['nullable', 'string', 'max:32'],
        ]);

        $settings = $this->settings();

        if ($request->has('administrator_telegram_id')) {
            $settings->administrator_telegram_id = $validated['administrator_telegram_id'] ?? null;
        }

        if ($request->filled('bot_token')) {
            $settings->bot_token = $validated['bot_token'];
        }

        $settings->save();

        return back()->with('status', 'Настройки новостного бота сохранены.');
    }

    private function settings(): NewsBotSetting
    {
        return NewsBotSetting::query()->firstOrCreate([], [
            'service_status' => 'stopped',
        ]);
    }
}
