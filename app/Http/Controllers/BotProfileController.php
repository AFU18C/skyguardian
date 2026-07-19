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
        $settings->bot_name = $this->nullableTrimmed($validated['bot_name'] ?? null);
        $settings->administrator_telegram_id = $this->nullableTrimmed($validated['administrator_telegram_id'] ?? null);

        if ($request->boolean('remove_bot_token')) {
            $settings->bot_token = null;
            $settings->bot_status = 'not_configured';
        } else {
            if ($request->filled('bot_token')) {
                $settings->bot_token = trim($validated['bot_token']);
            }

            $settings->bot_status = filled($settings->bot_token) && $request->boolean('bot_enabled')
                ? 'active'
                : 'disabled';
        }

        $settings->save();

        return back()->with('status', $request->boolean('remove_bot_token')
            ? 'Токен Telegram-бота удалён.'
            : ($settings->bot_status === 'disabled'
                ? 'Telegram-бот полностью остановлен.'
                : 'Telegram-бот включён.'));
    }

    public function updateNews(Request $request): RedirectResponse
    {
        $validated = $this->validateProfile($request);
        $settings = NewsBotSetting::query()->firstOrCreate([], ['service_status' => 'stopped']);
        $settings->bot_name = $this->nullableTrimmed($validated['bot_name'] ?? null);
        $settings->administrator_telegram_id = $this->nullableTrimmed($validated['administrator_telegram_id'] ?? null);

        if ($request->boolean('remove_bot_token')) {
            $settings->bot_token = null;
            $settings->service_status = 'stopped';
        } else {
            if ($request->filled('bot_token')) {
                $settings->bot_token = trim($validated['bot_token']);
            }

            $settings->service_status = filled($settings->bot_token) && $request->boolean('bot_enabled')
                ? 'running'
                : 'disabled';
        }

        $settings->save();

        return back()->with('status', $request->boolean('remove_bot_token')
            ? 'Токен Telegram-бота удалён.'
            : ($settings->service_status === 'disabled'
                ? 'Telegram-бот полностью остановлен.'
                : 'Telegram-бот включён.'));
    }

    private function validateProfile(Request $request): array
    {
        return $request->validate([
            'bot_name' => ['nullable', 'string', 'max:100'],
            'bot_token' => ['nullable', 'string', 'max:255', 'regex:/^\d{5,15}:[A-Za-z0-9_-]{20,}$/'],
            'administrator_telegram_id' => ['nullable', 'regex:/^-?\d{5,20}$/'],
            'bot_enabled' => ['nullable', 'boolean'],
            'remove_bot_token' => ['nullable', 'boolean'],
        ], [
            'bot_token.regex' => 'Токен Telegram-бота имеет неверный формат.',
            'administrator_telegram_id.regex' => 'Telegram ID должен содержать только цифры и при необходимости начинаться с минуса.',
        ]);
    }

    private function nullableTrimmed(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
