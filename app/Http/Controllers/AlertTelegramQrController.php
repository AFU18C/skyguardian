<?php

namespace App\Http\Controllers;

use App\Models\TechnicalTelegramAccount;
use App\Models\TelegramApiCredential;
use App\Services\Telegram\TelethonAccountService;
use App\Services\Telegram\TelethonQrLoginService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Throwable;

class AlertTelegramQrController extends Controller
{
    public function index(Request $request, TelethonQrLoginService $qr): View|RedirectResponse
    {
        $token = $request->session()->get('alerts_qr.token');
        $accountId = $request->session()->get('alerts_qr.account_id');
        $state = $token ? $qr->state($token) : null;

        if ($token && $accountId && ($state['status'] ?? null) === 'connected') {
            $account = TechnicalTelegramAccount::query()->find($accountId);
            if ($account) {
                $data = $state['account'] ?? [];
                $account->update([
                    'phone' => $data['phone'] ?? $account->phone,
                    'name' => $data['name'] ?? null,
                    'username' => $data['username'] ?? null,
                    'telegram_id' => $data['id'] ?? null,
                    'status' => 'connected',
                    'last_error' => null,
                    'last_checked_at' => now(),
                ]);
            }

            $qr->forget($token);
            $request->session()->forget('alerts_qr');

            return redirect()->route('alerts.settings')->with('status', 'Технический Telegram-аккаунт подключён по QR-коду.');
        }

        if ($accountId && in_array($state['status'] ?? null, ['error', 'expired', 'password_required'], true)) {
            TechnicalTelegramAccount::query()->whereKey($accountId)->update([
                'status' => 'error',
                'last_error' => $state['message'] ?? 'Ошибка QR-авторизации Telegram.',
            ]);
        }

        return view('alerts.telegram-qr', [
            'telegramApis' => TelegramApiCredential::query()->orderByDesc('is_primary')->orderBy('id')->get(),
            'state' => $state,
            'token' => $token,
        ]);
    }

    public function start(
        Request $request,
        TelethonAccountService $telethon,
        TelethonQrLoginService $qr,
    ): RedirectResponse {
        $validated = $request->validate([
            'telegram_api_credential_id' => ['required', 'integer', 'exists:telegram_api_credentials,id'],
            'label' => ['nullable', 'string', 'max:80'],
            'telegram_password' => ['nullable', 'string', 'max:255'],
        ]);

        $account = TechnicalTelegramAccount::query()->create([
            'telegram_api_credential_id' => $validated['telegram_api_credential_id'],
            'label' => $validated['label'] ?: 'Технический аккаунт',
            'status' => 'qr_pending',
            'is_primary' => ! TechnicalTelegramAccount::query()->exists(),
        ]);

        try {
            $telethon->resetSession($account);
            $token = $qr->start($account->fresh('telegramApiCredential'), $validated['telegram_password'] ?? null);
            $request->session()->put('alerts_qr', [
                'token' => $token,
                'account_id' => $account->id,
            ]);

            return redirect()->route('alerts.telegram.qr');
        } catch (Throwable $exception) {
            $account->update(['status' => 'error', 'last_error' => $exception->getMessage()]);

            return back()->withErrors(['telegram' => $exception->getMessage()]);
        }
    }

    public function cancel(
        Request $request,
        TelethonAccountService $telethon,
        TelethonQrLoginService $qr,
    ): RedirectResponse {
        $token = $request->session()->get('alerts_qr.token');
        $accountId = $request->session()->get('alerts_qr.account_id');

        if ($token) {
            $qr->forget($token);
        }

        if ($accountId && ($account = TechnicalTelegramAccount::query()->find($accountId))) {
            $telethon->resetSession($account);
            $account->delete();
        }

        $request->session()->forget('alerts_qr');

        return redirect()->route('alerts.telegram.qr')->with('status', 'QR-вход отменён.');
    }
}
