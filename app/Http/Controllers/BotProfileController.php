<?php

namespace App\Http\Controllers;

use App\Models\AlertBotSetting;
use App\Models\NewsBotSetting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class BotProfileController extends Controller
{
    public function updateAlert(Request $request): RedirectResponse
    {
        $validated = $this->validateProfile($request);
        $settings = AlertBotSetting::query()->firstOrCreate([]);
        $settings->bot_name = $validated['bot_name'] ?? null;
        $settings->administrator_telegram_id = $validated['administrator_telegram_id'] ?? null;

        if ($request->filled('bot_token')) {
            $settings->bot_token = $validated['bot_token'];
        }

        $settings->save();

        return back()->with('status', 'Настройки Telegram-бота сохранены.');
    }

    public function updateNews(Request $request): RedirectResponse
    {
        $validated = $this->validateProfile($request);
        $settings = NewsBotSetting::query()->firstOrCreate([], ['service_status' => 'stopped']);
        $settings->bot_name = $validated['bot_name'] ?? null;
        $settings->administrator_telegram_id = $validated['administrator_telegram_id'] ?? null;

        if ($request->filled('bot_token')) {
            $settings->bot_token = $validated['bot_token'];
        }

        $settings->save();

        return back()->with('status', 'Настройки Telegram-бота сохранены.');
    }

    private function validateProfile(Request $request): array
    {
        return $request->validate([
            'bot_name' => ['nullable', 'string', 'max:100'],
            'bot_token' => ['nullable', 'string', 'max:255'],
            'administrator_telegram_id' => ['nullable', 'string', 'max:32'],
        ]);
    }
}
