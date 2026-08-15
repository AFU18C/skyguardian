<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\AdminAuditLog;
use App\Models\User;
use App\Services\SiteContentService;
use App\Services\TotpService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class MfaChallengeController extends Controller
{
    public function create(Request $request, SiteContentService $siteContent): View|RedirectResponse
    {
        if (! $request->session()->has('auth.mfa_user_id')) {
            return redirect()->route('admin.login');
        }

        return view('auth.mfa-challenge', ['siteSettings' => $siteContent->settings()]);
    }

    public function store(Request $request, TotpService $totp): RedirectResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:32']]);
        $userId = (int) $request->session()->get('auth.mfa_user_id');
        $user = User::query()->find($userId);
        if (! $user || ! $user->mfaEnabled()) {
            $request->session()->forget(['auth.mfa_user_id', 'auth.mfa_remember']);

            return redirect()->route('admin.login');
        }

        $ipKey = 'mfa:ip|'.$request->ip();
        $accountKey = 'mfa:account|'.$user->id;
        if (RateLimiter::tooManyAttempts($ipKey, 10) || RateLimiter::tooManyAttempts($accountKey, 5)) {
            $seconds = max(RateLimiter::availableIn($ipKey), RateLimiter::availableIn($accountKey));

            throw ValidationException::withMessages([
                'code' => "Слишком много попыток. Повторите через {$seconds} сек.",
            ]);
        }

        $code = trim($data['code']);
        $valid = $totp->verify((string) $user->mfa_secret, $code);
        if (! $valid) {
            $recoveryCodes = $user->mfa_recovery_codes ?? [];
            $normalizedCode = mb_strtoupper($code);
            $index = collect($recoveryCodes)->search(function (mixed $hash) use ($normalizedCode): bool {
                if (! is_string($hash) || $hash === '') {
                    return false;
                }

                // Accept the original SHA-256 format during a rolling upgrade;
                // newly generated codes use the deliberately slow password hasher.
                return strlen($hash) === 64 && ctype_xdigit($hash)
                    ? hash_equals($hash, hash('sha256', $normalizedCode))
                    : Hash::check($normalizedCode, $hash);
            });
            if ($index !== false) {
                unset($recoveryCodes[$index]);
                $user->update(['mfa_recovery_codes' => array_values($recoveryCodes)]);
                $valid = true;
            }
        }

        if (! $valid) {
            RateLimiter::hit($ipKey, 300);
            RateLimiter::hit($accountKey, 300);
            $this->record($request, $user, false);
            throw ValidationException::withMessages(['code' => 'Неверный код приложения или резервный код.']);
        }

        RateLimiter::clear($ipKey);
        RateLimiter::clear($accountKey);
        $remember = (bool) $request->session()->pull('auth.mfa_remember', false);
        $request->session()->forget('auth.mfa_user_id');
        Auth::login($user, $remember);
        $request->session()->regenerate();
        $this->record($request, $user, true);

        return redirect()->intended(route('admin.dashboard'))
            ->with('toast', ['type' => 'success', 'title' => 'Вход выполнен', 'message' => 'Двухфакторная проверка пройдена.']);
    }

    private function record(Request $request, User $user, bool $success): void
    {
        try {
            AdminAuditLog::query()->create([
                'user_id' => $user->id,
                'event' => $success ? 'auth.mfa.accepted' : 'auth.mfa.failed',
                'route_name' => 'admin.mfa.challenge.store',
                'method' => 'POST',
                'path' => '/admin/mfa-challenge',
                'ip_address' => $request->ip(),
                'user_agent' => mb_substr((string) $request->userAgent(), 0, 500),
                'response_status' => $success ? 302 : 422,
                'metadata' => ['result' => $success ? 'success' : 'invalid_code'],
            ]);
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
