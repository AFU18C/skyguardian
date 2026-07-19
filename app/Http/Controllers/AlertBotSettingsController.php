<?php

namespace App\Http\Controllers;

use App\Models\AlertBotSetting;
use App\Models\TechnicalTelegramAccount;
use App\Services\Telegram\TelethonAccountService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Throwable;

class AlertBotSettingsController extends Controller
{
    public function edit(Request $request, TelethonAccountService $telethon): View
    {
        $settings = $this->settings();
        $this->importLegacyAccount($settings);

        return view('alerts.settings', [
            'settings' => $settings,
            'technicalAccounts' => TechnicalTelegramAccount::query()->orderByDesc('is_primary')->orderBy('id')->get(),
            'telegramApiConfigured' => $telethon->isConfigured(),
            'authorizationPending' => $request->session()->has('telegram_auth.phone_code_hash'),
            'authorizationAccountId' => $request->session()->get('telegram_auth.account_id'),
            'passwordRequired' => $request->session()->get('telegram_auth.password_required', false),
        ]);
    }

    public function sources(): View
    {
        return view('alerts.sources', ['settings' => $this->settings()]);
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'telegram_api_id' => ['nullable', 'digits_between:4,20'],
            'telegram_api_hash' => ['nullable', 'string', 'size:32'],
            'bot_token' => ['nullable', 'string', 'max:255'],
            'administrator_telegram_id' => ['nullable', 'string', 'max:32'],
        ]);

        $settings = $this->settings();

        if ($request->has('administrator_telegram_id')) {
            $settings->administrator_telegram_id = $validated['administrator_telegram_id'] ?? null;
        }

        foreach (['telegram_api_id', 'telegram_api_hash', 'bot_token'] as $field) {
            if ($request->filled($field)) {
                $settings->{$field} = $validated[$field];
            }
        }

        $settings->save();

        return back()->with('status', 'Настройки сохранены.');
    }

    public function updateSources(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'source_chat' => ['nullable', 'string', 'max:255'],
            'destination_chat' => ['nullable', 'string', 'max:255'],
        ]);

        $this->settings()->update([
            'source_chat' => $validated['source_chat'] ?? null,
            'destination_chat' => $validated['destination_chat'] ?? null,
            'autopublish_enabled' => $request->boolean('autopublish_enabled'),
            'text_processing_enabled' => $request->boolean('text_processing_enabled'),
        ]);

        return back()->with('status', 'Настройки источника сохранены.');
    }

    public function sendCode(Request $request, TelethonAccountService $telethon): RedirectResponse
    {
        $validated = $request->validate([
            'account_id' => ['nullable', 'integer', 'exists:technical_telegram_accounts,id'],
            'label' => ['nullable', 'string', 'max:80'],
            'technical_phone' => ['required', 'string', 'max:32'],
        ]);

        $account = isset($validated['account_id'])
            ? TechnicalTelegramAccount::query()->findOrFail($validated['account_id'])
            : TechnicalTelegramAccount::query()->create([
                'label' => $validated['label'] ?: 'Технический аккаунт',
                'phone' => $validated['technical_phone'],
                'status' => 'disconnected',
                'is_primary' => ! TechnicalTelegramAccount::query()->exists(),
            ]);

        try {
            $result = $telethon->sendCode($validated['technical_phone'], $account);
            $account->update([
                'label' => $validated['label'] ?: $account->label,
                'phone' => $validated['technical_phone'],
                'status' => 'code_sent',
                'last_error' => null,
            ]);

            $request->session()->put('telegram_auth', [
                'account_id' => $account->id,
                'phone' => $validated['technical_phone'],
                'phone_code_hash' => $result['phone_code_hash'],
                'password_required' => false,
            ]);

            return back()->with('status', 'Код отправлен в Telegram.');
        } catch (Throwable $exception) {
            $account->update(['status' => 'error', 'last_error' => $exception->getMessage()]);
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
        if (! is_array($auth) || empty($auth['account_id']) || empty($auth['phone']) || empty($auth['phone_code_hash'])) {
            return back()->withErrors(['telegram' => 'Сначала отправьте код на номер телефона.']);
        }

        $account = TechnicalTelegramAccount::query()->findOrFail($auth['account_id']);

        try {
            $result = $telethon->signIn(
                $auth['phone'],
                $validated['telegram_code'],
                $auth['phone_code_hash'],
                $account,
                $validated['telegram_password'] ?? null,
            );

            if (($result['status'] ?? null) === 'password_required') {
                $request->session()->put('telegram_auth.password_required', true);
                return back()->withErrors(['telegram' => 'Введите пароль двухэтапной проверки Telegram.']);
            }

            $this->applyAccountResult($account, $result);
            $request->session()->forget('telegram_auth');

            return back()->with('status', 'Технический Telegram-аккаунт подключён.');
        } catch (Throwable $exception) {
            $account->update(['status' => 'error', 'last_error' => $exception->getMessage()]);
            return back()->withErrors(['telegram' => $exception->getMessage()]);
        }
    }

    public function updateAccount(Request $request, TechnicalTelegramAccount $account): RedirectResponse
    {
        $validated = $request->validate(['label' => ['required', 'string', 'max:80']]);

        DB::transaction(function () use ($request, $account, $validated): void {
            if ($request->boolean('is_primary')) {
                TechnicalTelegramAccount::query()->whereKeyNot($account->id)->update(['is_primary' => false]);
            }
            $account->update(['label' => $validated['label'], 'is_primary' => $request->boolean('is_primary')]);
        });

        return back()->with('status', 'Аккаунт обновлён.');
    }

    public function disconnect(Request $request, TechnicalTelegramAccount $account, TelethonAccountService $telethon): RedirectResponse
    {
        try {
            $telethon->logout($account);
            $account->update(['status' => 'disconnected', 'last_error' => null]);
            $request->session()->forget('telegram_auth');
            return back()->with('status', 'Технический аккаунт отключён.');
        } catch (Throwable $exception) {
            $account->update(['status' => 'error', 'last_error' => $exception->getMessage()]);
            return back()->withErrors(['telegram' => $exception->getMessage()]);
        }
    }

    public function destroy(TechnicalTelegramAccount $account): RedirectResponse
    {
        $wasPrimary = $account->is_primary;
        $account->delete();

        if ($wasPrimary) {
            TechnicalTelegramAccount::query()->orderBy('id')->first()?->update(['is_primary' => true]);
        }

        return back()->with('status', 'Технический аккаунт удалён.');
    }

    private function settings(): AlertBotSetting
    {
        return AlertBotSetting::query()->firstOrCreate([], [
            'technical_status' => 'disconnected',
            'bot_status' => 'not_configured',
            'source_status' => 'not_checked',
            'destination_status' => 'not_checked',
            'service_status' => 'stopped',
            'text_processing_enabled' => true,
        ]);
    }

    private function importLegacyAccount(AlertBotSetting $settings): void
    {
        if (TechnicalTelegramAccount::query()->exists() || ! filled($settings->technical_phone)) {
            return;
        }

        TechnicalTelegramAccount::query()->create([
            'label' => 'Основной аккаунт',
            'phone' => $settings->technical_phone,
            'name' => $settings->technical_name,
            'username' => $settings->technical_username,
            'telegram_id' => $settings->technical_telegram_id,
            'status' => $settings->technical_status ?: 'disconnected',
            'is_primary' => true,
            'last_error' => $settings->last_error,
        ]);
    }

    private function applyAccountResult(TechnicalTelegramAccount $technicalAccount, array $result): void
    {
        if (($result['status'] ?? null) !== 'connected') {
            $technicalAccount->update(['status' => 'disconnected', 'last_checked_at' => now()]);
            return;
        }

        $account = $result['account'] ?? [];
        $technicalAccount->update([
            'phone' => $account['phone'] ?? $technicalAccount->phone,
            'name' => $account['name'] ?? null,
            'username' => $account['username'] ?? null,
            'telegram_id' => $account['id'] ?? null,
            'status' => 'connected',
            'last_error' => null,
            'last_checked_at' => now(),
        ]);
    }
}
