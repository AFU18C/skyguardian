<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminAuditLog;
use App\Models\User;
use App\Services\TotpService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class MfaSettingsController extends Controller
{
    public function show(Request $request, TotpService $totp): View
    {
        $secret = $request->session()->get('mfa.setup.secret');

        return view('admin.security', [
            'setupSecret' => $secret,
            'setupUri' => is_string($secret)
                ? $totp->provisioningUri($secret, (string) $request->user()->email)
                : null,
            'users' => $request->user()->role === User::ROLE_ADMINISTRATOR
                ? User::query()->orderBy('name')->get()
                : collect(),
            'auditLogs' => $request->user()->role === User::ROLE_ADMINISTRATOR
                ? AdminAuditLog::query()->with('user')->latest()->paginate(50)
                : null,
        ]);
    }

    public function begin(Request $request, TotpService $totp): RedirectResponse
    {
        $this->confirmPassword($request);
        $request->session()->put('mfa.setup.secret', $totp->generateSecret());
        $request->session()->put('mfa.setup.recovery_codes', $totp->recoveryCodes());

        return back()->with('toast', ['type' => 'success', 'title' => 'Настройка начата', 'message' => 'Отсканируйте QR-код и подтвердите код из приложения.']);
    }

    public function enable(Request $request, TotpService $totp): RedirectResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:16']]);
        $secret = $request->session()->get('mfa.setup.secret');
        $codes = $request->session()->get('mfa.setup.recovery_codes');
        if (! is_string($secret) || ! is_array($codes) || ! $totp->verify($secret, $data['code'])) {
            throw ValidationException::withMessages(['code' => 'Неверный код. Проверьте время на телефоне и повторите.']);
        }

        $request->user()->update([
            'mfa_secret' => $secret,
            'mfa_recovery_codes' => array_map(
                fn (string $code): string => Hash::make(mb_strtoupper($code)),
                $codes,
            ),
            'mfa_enabled_at' => now(),
        ]);
        $request->session()->forget(['mfa.setup.secret', 'mfa.setup.recovery_codes']);

        return back()
            ->with('mfa_recovery_codes', $codes)
            ->with('toast', ['type' => 'success', 'title' => 'MFA включена', 'message' => 'Сохраните резервные коды в безопасном месте.']);
    }

    public function disable(Request $request, TotpService $totp): RedirectResponse
    {
        $this->confirmPassword($request);
        $data = $request->validate(['code' => ['required', 'string', 'max:16']]);
        if (! $request->user()->mfaEnabled() || ! $totp->verify((string) $request->user()->mfa_secret, $data['code'])) {
            throw ValidationException::withMessages(['code' => 'Неверный код приложения.']);
        }

        $request->user()->update([
            'mfa_secret' => null,
            'mfa_recovery_codes' => null,
            'mfa_enabled_at' => null,
        ]);

        return back()->with('toast', ['type' => 'success', 'title' => 'MFA отключена', 'message' => 'Вход снова защищён только паролем.']);
    }

    public function updateRole(Request $request, User $user): RedirectResponse
    {
        $data = $request->validate(['role' => ['required', Rule::in(User::ROLES)]]);
        DB::transaction(function () use ($user, $data): void {
            $administrators = User::query()
                ->where('role', User::ROLE_ADMINISTRATOR)
                ->orderBy('id')
                ->lockForUpdate()
                ->get(['id']);
            $lockedUser = User::query()->lockForUpdate()->findOrFail($user->id);
            if ($lockedUser->role === User::ROLE_ADMINISTRATOR
                && $data['role'] !== User::ROLE_ADMINISTRATOR
                && $administrators->count() <= 1) {
                throw ValidationException::withMessages(['role' => 'Нельзя убрать роль у последнего администратора.']);
            }

            $lockedUser->update(['role' => $data['role']]);
        });

        return back()->with('toast', ['type' => 'success', 'title' => 'Роль обновлена', 'message' => 'Права пользователя сохранены.']);
    }

    private function confirmPassword(Request $request): void
    {
        $data = $request->validate(['password' => ['required', 'string']]);
        if (! Hash::check($data['password'], (string) $request->user()->password)) {
            throw ValidationException::withMessages(['password' => 'Неверный текущий пароль.']);
        }
    }
}
