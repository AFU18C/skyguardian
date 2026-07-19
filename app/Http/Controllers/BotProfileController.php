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
            $this->setBotEnabled($settings, false);
        } else {
            if ($request->filled('bot_token')) {
                $settings->bot_token = trim($validated['bot_token']);
            }

            $enabled = filled($settings->bot_token) && $request->boolean('bot_enabled');
            $settings->bot_status = $enabled ? 'active' : 'stopped';
            $this->setBotEnabled($settings, $enabled);
        }

        $settings->save();

        return back()->with('status', $request->boolean('remove_bot_token')
            ? 'Токен Telegram-бота удалён.'
            : ($this->botEnabled($settings) ? 'Telegram-бот включён.' : 'Telegram-бот полностью остановлен.'));
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
            $this->setBotEnabled($settings, false);
        } else {
            if ($request->filled('bot_token')) {
                $settings->bot_token = trim($validated['bot_token']);
            }

            $enabled = filled($settings->bot_token) && $request->boolean('bot_enabled');
            $settings->service_status = $enabled ? 'running' : 'stopped';
            $this->setBotEnabled($settings, $enabled);
        }

        $settings->save();

        return back()->with('status', $request->boolean('remove_bot_token')
            ? 'Токен Telegram-бота удалён.'
            : ($this->botEnabled($settings) ? 'Telegram-бот включён.' : 'Telegram-бот полностью остановлен.'));
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

    private function setBotEnabled(AlertBotSetting|NewsBotSetting $settings, bool $enabled): void
    {
        $extra = is_array($settings->extra_settings) ? $settings->extra_settings : [];
        $extra['bot_enabled'] = $enabled;
        $settings->extra_settings = $extra;
    }

    private function botEnabled(AlertBotSetting|NewsBotSetting $settings): bool
    {
        $extra = is_array($settings->extra_settings) ? $settings->extra_settings : [];

        return filled($settings->bot_token) && (bool) ($extra['bot_enabled'] ?? true);
    }

    private function nullableTrimmed(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
