<?php

namespace App\Http\Controllers;

use App\Models\AlertBotSetting;
use App\Models\TechnicalTelegramAccount;
use App\Models\TelegramApiCredential;
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
        $this->importLegacyApi($settings);
        $this->importLegacyAccount($settings);

        return view('alerts.settings', [
            'settings' => $settings,
            'telegramApis' => TelegramApiCredential::query()->orderByDesc('is_primary')->orderBy('id')->get(),
            'technicalAccounts' => TechnicalTelegramAccount::query()->with('telegramApiCredential')->orderByDesc('is_primary')->orderBy('id')->get(),
            'telegramApiConfigured' => $telethon->isConfigured(),
            'authorizationPending' => $request->session()->has('telegram_auth.phone_code_hash'),
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

    public function storeApi(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'label' => ['required', 'string', 'max:80'],
            'api_id' => ['required', 'digits_between:4,20'],
            'api_hash' => ['required', 'string', 'size:32'],
        ]);

        DB::transaction(function () use ($request, $validated): void {
            $primary = $request->boolean('is_primary') || ! TelegramApiCredential::query()->exists();
            if ($primary) {
                TelegramApiCredential::query()->update(['is_primary' => false]);
            }
            TelegramApiCredential::query()->create($validated + ['is_primary' => $primary]);
        });

        return back()->with('status', 'Telegram API добавлен.');
    }

    public function updateApi(Request $request, TelegramApiCredential $telegramApi): RedirectResponse
    {
        $validated = $request->validate([
            'label' => ['required', 'string', 'max:80'],
            'api_id' => ['required', 'digits_between:4,20'],
            'api_hash' => ['nullable', 'string', 'size:32'],
        ]);

        DB::transaction(function () use ($request, $validated, $telegramApi): void {
            if ($request->boolean('is_primary')) {
                TelegramApiCredential::query()->whereKeyNot($telegramApi->id)->update(['is_primary' => false]);
            }
            $telegramApi->label = $validated['label'];
            $telegramApi->api_id = $validated['api_id'];
            if (! empty($validated['api_hash'])) {
                $telegramApi->api_hash = $validated['api_hash'];
            }
            $telegramApi->is_primary = $request->boolean('is_primary');
            $telegramApi->save();
        });

        return back()->with('status', 'Telegram API обновлён.');
    }

    public function destroyApi(
        Request $request,
        TelegramApiCredential $telegramApi,
        TelethonAccountService $telethon,
    ): RedirectResponse {
        $wasPrimary = $telegramApi->is_primary;
        $accounts = $telegramApi->technicalAccounts()->get();

        DB::transaction(function () use ($accounts, $telegramApi, $telethon): void {
            foreach ($accounts as $account) {
                $telethon->resetSession($account);
                $account->delete();
            }
            $telegramApi->delete();
        });

        $request->session()->forget('telegram_auth');

        if ($wasPrimary) {
            TelegramApiCredential::query()->orderBy('id')->first()?->update(['is_primary' => true]);
        }
        if (TechnicalTelegramAccount::query()->exists()) {
            TechnicalTelegramAccount::query()
                ->where('is_primary', true)
                ->exists() || TechnicalTelegramAccount::query()->orderBy('id')->first()?->update(['is_primary' => true]);
        }

        $settings = $this->settings();
        if (! TelegramApiCredential::query()->exists()) {
            $settings->telegram_api_id = null;
            $settings->telegram_api_hash = null;
        }
        if (! TechnicalTelegramAccount::query()->exists()) {
            $this->clearLegacyTechnicalAccount($settings);
        }
        $settings->save();

        $suffix = $accounts->isNotEmpty() ? ' Вместе с ним удалены привязанные технические аккаунты.' : '';

        return back()->with('status', 'Telegram API удалён.'.$suffix);
    }

    public function sendCode(Request $request, TelethonAccountService $telethon): RedirectResponse
    {
        $validated = $request->validate([
            'account_id' => ['nullable', 'integer', 'exists:technical_telegram_accounts,id'],
            'telegram_api_credential_id' => ['required', 'integer', 'exists:telegram_api_credentials,id'],
            'label' => ['nullable', 'string', 'max:80'],
            'technical_phone' => ['required', 'string', 'max:32'],
        ]);

        $account = isset($validated['account_id'])
            ? TechnicalTelegramAccount::query()->findOrFail($validated['account_id'])
            : TechnicalTelegramAccount::query()->create([
                'telegram_api_credential_id' => $validated['telegram_api_credential_id'],
                'label' => $validated['label'] ?: 'Технический аккаунт',
                'phone' => $validated['technical_phone'],
                'status' => 'disconnected',
                'is_primary' => ! TechnicalTelegramAccount::query()->exists(),
            ]);

        $account->update(['telegram_api_credential_id' => $validated['telegram_api_credential_id']]);

        try {
            $telethon->resetSession($account);
            $result = $telethon->sendCode($validated['technical_phone'], $account->fresh('telegramApiCredential'));
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
        $account = TechnicalTelegramAccount::query()->with('telegramApiCredential')->findOrFail($auth['account_id']);
        try {
            $result = $telethon->signIn($auth['phone'], $validated['telegram_code'], $auth['phone_code_hash'], $account, $validated['telegram_password'] ?? null);
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

    public function updateAccount(Request $request, TechnicalTelegramAccount $account, TelethonAccountService $telethon): RedirectResponse
    {
        $validated = $request->validate([
            'label' => ['required', 'string', 'max:80'],
            'telegram_api_credential_id' => ['required', 'integer', 'exists:telegram_api_credentials,id'],
        ]);
        $apiChanged = (int) $account->telegram_api_credential_id !== (int) $validated['telegram_api_credential_id'];

        DB::transaction(function () use ($request, $account, $validated): void {
            if ($request->boolean('is_primary')) {
                TechnicalTelegramAccount::query()->whereKeyNot($account->id)->update(['is_primary' => false]);
            }
            $account->update([
                'label' => $validated['label'],
                'telegram_api_credential_id' => $validated['telegram_api_credential_id'],
                'is_primary' => $request->boolean('is_primary'),
            ]);
        });

        if ($apiChanged) {
            $telethon->resetSession($account);
            $account->update(['status' => 'disconnected', 'last_error' => null]);
            return back()->with('status', 'Telegram API изменён. Аккаунт нужно подключить заново.');
        }
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

    public function destroy(
        Request $request,
        TechnicalTelegramAccount $account,
        TelethonAccountService $telethon,
    ): RedirectResponse {
        $wasPrimary = $account->is_primary;
        $telethon->resetSession($account);
        $account->delete();
        $request->session()->forget('telegram_auth');

        if ($wasPrimary) {
            TechnicalTelegramAccount::query()->orderBy('id')->first()?->update(['is_primary' => true]);
        }

        if (! TechnicalTelegramAccount::query()->exists()) {
            $settings = $this->settings();
            $this->clearLegacyTechnicalAccount($settings);
            $settings->save();
        }

        return back()->with('status', 'Технический аккаунт удалён.');
    }

    private function settings(): AlertBotSetting
    {
        return AlertBotSetting::query()->firstOrCreate([], [
            'technical_status' => 'disconnected', 'bot_status' => 'not_configured',
            'source_status' => 'not_checked', 'destination_status' => 'not_checked',
            'service_status' => 'stopped', 'text_processing_enabled' => true,
        ]);
    }

    private function importLegacyApi(AlertBotSetting $settings): void
    {
        if (! TelegramApiCredential::query()->exists() && filled($settings->telegram_api_id) && filled($settings->telegram_api_hash)) {
            TelegramApiCredential::query()->create([
                'label' => 'Основной Telegram API',
                'api_id' => $settings->telegram_api_id,
                'api_hash' => $settings->telegram_api_hash,
                'is_primary' => true,
            ]);
        }
        $primaryApi = TelegramApiCredential::query()->orderByDesc('is_primary')->orderBy('id')->first();
        if ($primaryApi) {
            TechnicalTelegramAccount::query()->whereNull('telegram_api_credential_id')->update(['telegram_api_credential_id' => $primaryApi->id]);
        }
    }

    private function importLegacyAccount(AlertBotSetting $settings): void
    {
        if (TechnicalTelegramAccount::query()->exists() || ! filled($settings->technical_phone)) {
            return;
        }
        $primaryApi = TelegramApiCredential::query()->orderByDesc('is_primary')->orderBy('id')->first();
        TechnicalTelegramAccount::query()->create([
            'telegram_api_credential_id' => $primaryApi?->id,
            'label' => 'Основной аккаунт', 'phone' => $settings->technical_phone,
            'name' => $settings->technical_name, 'username' => $settings->technical_username,
            'telegram_id' => $settings->technical_telegram_id,
            'status' => $settings->technical_status ?: 'disconnected',
            'is_primary' => true, 'last_error' => $settings->last_error,
        ]);
    }

    private function clearLegacyTechnicalAccount(AlertBotSetting $settings): void
    {
        $settings->technical_phone = null;
        $settings->technical_name = null;
        $settings->technical_username = null;
        $settings->technical_telegram_id = null;
        $settings->technical_status = 'disconnected';
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
            'name' => $account['name'] ?? null, 'username' => $account['username'] ?? null,
            'telegram_id' => $account['id'] ?? null, 'status' => 'connected',
            'last_error' => null, 'last_checked_at' => now(),
        ]);
    }
}
