<?php

namespace App\Http\Controllers;

use App\Models\AlertBotSetting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AlertBotSettingsController extends Controller
{
    public function edit(): View
    {
        return view('alerts.settings', [
            'settings' => AlertBotSetting::query()->firstOrCreate([], [
                'technical_status' => 'disconnected',
                'bot_status' => 'not_configured',
                'source_status' => 'not_checked',
                'destination_status' => 'not_checked',
                'service_status' => 'stopped',
                'text_processing_enabled' => true,
            ]),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'technical_phone' => ['nullable', 'string', 'max:32'],
            'bot_token' => ['nullable', 'string', 'max:255'],
            'administrator_telegram_id' => ['nullable', 'string', 'max:32'],
            'source_chat' => ['nullable', 'string', 'max:255'],
            'destination_chat' => ['nullable', 'string', 'max:255'],
            'autopublish_enabled' => ['nullable', 'boolean'],
            'text_processing_enabled' => ['nullable', 'boolean'],
        ]);

        $settings = AlertBotSetting::query()->firstOrCreate();
        $settings->fill([
            'technical_phone' => $validated['technical_phone'] ?? null,
            'administrator_telegram_id' => $validated['administrator_telegram_id'] ?? null,
            'source_chat' => $validated['source_chat'] ?? null,
            'destination_chat' => $validated['destination_chat'] ?? null,
            'autopublish_enabled' => $request->boolean('autopublish_enabled'),
            'text_processing_enabled' => $request->boolean('text_processing_enabled'),
        ]);

        if ($request->filled('bot_token')) {
            $settings->bot_token = $validated['bot_token'];
        }

        $settings->save();

        return back()->with('status', 'Налаштування збережено.');
    }
}
