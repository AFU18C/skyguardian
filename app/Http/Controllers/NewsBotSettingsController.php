<?php

namespace App\Http\Controllers;

use App\Models\NewsBotSetting;
use App\Models\NewsTechnicalTelegramAccount;
use App\Models\NewsTelegramApiCredential;
use App\Services\Telegram\TelethonAccountService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Throwable;

class NewsBotSettingsController extends Controller
{
    public function edit(Request $request): View
    {
        return view('news.settings', [
            'settings' => $this->settings(),
            'telegramApis' => NewsTelegramApiCredential::query()->orderByDesc('is_primary')->orderBy('id')->get(),
            'technicalAccounts' => NewsTechnicalTelegramAccount::query()->with('telegramApiCredential')->orderByDesc('is_primary')->orderBy('id')->get(),
            'authorizationPending' => $request->session()->has('news_telegram_auth.phone_code_hash'),
            'passwordRequired' => $request->session()->get('news_telegram_auth.password_required', false),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'bot_token' => ['nullable', 'string', 'max:255'],
            'administrator_telegram_id' => ['nullable', 'string', 'max:32'],
        ]);

        $settings = $this->settings();
        $settings->administrator_telegram_id = $validated['administrator_telegram_id'] ?? null;
        if ($request->filled('bot_token')) {
            $settings->bot_token = $validated['bot_token'];
        }
        $settings->save();

        return back()->with('status', 'Настройки новостного бота сохранены.');
    }

    public function storeApi(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'label' => ['required', 'string', 'max:80'],
            'api_id' => ['required', 'digits_between:4,20'],
            'api_hash' => ['required', 'string', 'size:32'],
        ]);

        DB::transaction(function () use ($request, $validated): void {
            $primary = $request->boolean('is_primary') || ! NewsTelegramApiCredential::query()->exists();
            if ($primary) {
                NewsTelegramApiCredential::query()->update(['is_primary' => false]);
            }
            NewsTelegramApiCredential::query()->create($validated + ['is_primary' => $primary]);
        });

        return back()->with('status', 'Telegram API новостей добавлен.');
    }

    public function updateApi(Request $request, NewsTelegramApiCredential $newsTelegramApi): RedirectResponse
    {
        $validated = $request->validate([
            'label' => ['required', 'string', 'max:80'],
            'api_id' => ['required', 'digits_between:4,20'],
            'api_hash' => ['nullable', 'string', 'size:32'],
        ]);

        DB::transaction(function () use ($request, $validated, $newsTelegramApi): void {
            $makePrimary = $request->boolean('is_primary');
            $hasAnotherPrimary = NewsTelegramApiCredential::query()
                ->whereKeyNot($newsTelegramApi->id)
                ->where('is_primary', true)
                ->exists();

            if ($makePrimary) {
                NewsTelegramApiCredential::query()->whereKeyNot($newsTelegramApi->id)->update(['is_primary' => false]);
            }

            $newsTelegramApi->label = $validated['label'];
            $newsTelegramApi->api_id = $validated['api_id'];
            if (! empty($validated['api_hash'])) {
                $newsTelegramApi->api_hash = $validated['api_hash'];
            }
            $newsTelegramApi->is_primary = $makePrimary || ! $hasAnotherPrimary;
            $newsTelegramApi->save();
        });

        return back()->with('status', 'Telegram API новостей обновлён.');
    }

    public function destroyApi(Request $request, NewsTelegramApiCredential $newsTelegramApi, TelethonAccountService $telethon): RedirectResponse
    {
        $wasPrimary = $newsTelegramApi->is_primary;
        $accounts = $newsTelegramApi->technicalAccounts()->get();

        DB::transaction(function () use ($accounts, $newsTelegramApi, $telethon): void {
            foreach ($accounts as $account) {
                $telethon->resetSession($account);
                $account->delete();
            }
            $newsTelegramApi->delete();
        });

        $request->session()->forget('news_telegram_auth');

        if ($wasPrimary) {
            NewsTelegramApiCredential::query()->orderBy('id')->first()?->update(['is_primary' => true]);
        }
        if (NewsTechnicalTelegramAccount::query()->exists()
            && ! NewsTechnicalTelegramAccount::query()->where('is_primary', true)->exists()) {
            NewsTechnicalTelegramAccount::query()->orderBy('id')->first()?->update(['is_primary' => true]);
        }

        return back()->with('status', 'Telegram API новостей удалён.');
    }

    public function sendCode(Request $request, TelethonAccountService $telethon): RedirectResponse
    {
        $validated = $request->validate([
            'account_id' => ['nullable', 'integer', 'exists:news_technical_telegram_accounts,id'],
            'news_telegram_api_credential_id' => ['required', 'integer', 'exists:news_telegram_api_credentials,id'],
            'label' => ['nullable', 'string', 'max:80'],
            'technical_phone' => ['required', 'string', 'max:32'],
        ]);

        $account = isset($validated['account_id'])
            ? NewsTechnicalTelegramAccount::query()->findOrFail($validated['account_id'])
            : NewsTechnicalTelegramAccount::query()->create([
                'news_telegram_api_credential_id' => $validated['news_telegram_api_credential_id'],
                'label' => $validated['label'] ?: 'Технический аккаунт новостей',
                'phone' => $validated['technical_phone'],
                'status' => 'disconnected',
                'is_primary' => ! NewsTechnicalTelegramAccount::query()->exists(),
            ]);

        $account->update(['news_telegram_api_credential_id' => $validated['news_telegram_api_credential_id']]);

        try {
            $result = $telethon->sendCode($validated['technical_phone'], $account->fresh('telegramApiCredential'));
            $account->update([
                'label' => $validated['label'] ?: $account->label,
                'phone' => $validated['technical_phone'],
                'status' => 'code_sent',
                'last_error' => null,
            ]);
            $request->session()->put('news_telegram_auth', [
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
        $auth = $request->session()->get('news_telegram_auth');
        if (! is_array($auth) || empty($auth['account_id'])) {
            return back()->withErrors(['telegram' => 'Сначала отправьте код.']);
        }

        $account = NewsTechnicalTelegramAccount::query()->with('telegramApiCredential')->findOrFail($auth['account_id']);
        try {
            $result = $telethon->signIn($auth['phone'], $validated['telegram_code'], $auth['phone_code_hash'], $account, $validated['telegram_password'] ?? null);
            if (($result['status'] ?? null) === 'password_required') {
                $request->session()->put('news_telegram_auth.password_required', true);
                return back()->withErrors(['telegram' => 'Введите пароль двухэтапной проверки Telegram.']);
            }
            $account->update([
                'name' => $result['name'] ?? null,
                'username' => $result['username'] ?? null,
                'telegram_id' => isset($result['telegram_id']) ? (string) $result['telegram_id'] : null,
                'status' => 'connected',
                'last_error' => null,
                'last_checked_at' => now(),
            ]);
            $request->session()->forget('news_telegram_auth');
            return back()->with('status', 'Технический аккаунт новостей подключён.');
        } catch (Throwable $exception) {
            $account->update(['status' => 'error', 'last_error' => $exception->getMessage()]);
            return back()->withErrors(['telegram' => $exception->getMessage()]);
        }
    }

    public function updateAccount(Request $request, NewsTechnicalTelegramAccount $newsAccount, TelethonAccountService $telethon): RedirectResponse
    {
        $validated = $request->validate([
            'label' => ['required', 'string', 'max:80'],
            'news_telegram_api_credential_id' => ['required', 'integer', 'exists:news_telegram_api_credentials,id'],
        ]);
        $apiChanged = (int) $newsAccount->news_telegram_api_credential_id !== (int) $validated['news_telegram_api_credential_id'];

        DB::transaction(function () use ($request, $newsAccount, $validated): void {
            $makePrimary = $request->boolean('is_primary');
            $hasAnotherPrimary = NewsTechnicalTelegramAccount::query()
                ->whereKeyNot($newsAccount->id)
                ->where('is_primary', true)
                ->exists();

            if ($makePrimary) {
                NewsTechnicalTelegramAccount::query()->whereKeyNot($newsAccount->id)->update(['is_primary' => false]);
            }

            $newsAccount->update([
                'label' => $validated['label'],
                'news_telegram_api_credential_id' => $validated['news_telegram_api_credential_id'],
                'is_primary' => $makePrimary || ! $hasAnotherPrimary,
            ]);
        });

        if ($apiChanged) {
            $telethon->resetSession($newsAccount);
            $newsAccount->update(['status' => 'disconnected', 'last_error' => null]);
        }
        return back()->with('status', $apiChanged ? 'API изменён. Аккаунт нужно подключить заново.' : 'Аккаунт новостей обновлён.');
    }

    public function disconnect(Request $request, NewsTechnicalTelegramAccount $newsAccount, TelethonAccountService $telethon): RedirectResponse
    {
        try {
            $telethon->logout($newsAccount);
        } catch (Throwable) {
            $telethon->resetSession($newsAccount);
        }
        $newsAccount->update(['status' => 'disconnected', 'last_error' => null]);
        $request->session()->forget('news_telegram_auth');
        return back()->with('status', 'Аккаунт новостей отключён.');
    }

    public function destroy(Request $request, NewsTechnicalTelegramAccount $newsAccount, TelethonAccountService $telethon): RedirectResponse
    {
        $wasPrimary = $newsAccount->is_primary;
        $telethon->resetSession($newsAccount);
        $newsAccount->delete();
        $request->session()->forget('news_telegram_auth');

        if ($wasPrimary) {
            NewsTechnicalTelegramAccount::query()->orderBy('id')->first()?->update(['is_primary' => true]);
        }

        return back()->with('status', 'Аккаунт новостей удалён.');
    }

    private function settings(): NewsBotSetting
    {
        return NewsBotSetting::query()->firstOrCreate([], ['service_status' => 'stopped']);
    }
}
