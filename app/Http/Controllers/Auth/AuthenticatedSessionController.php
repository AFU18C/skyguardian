<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\AdminAuditLog;
use App\Models\User;
use App\Services\SiteContentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AuthenticatedSessionController extends Controller
{
    public function create(SiteContentService $siteContent): View
    {
        return view('auth.login', [
            'siteSettings' => $siteContent->settings(),
            'isPreview' => false,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $email = Str::lower($request->string('email')->value());
        $ipKey = 'login:ip|'.$request->ip();
        $accountKey = 'login:account|'.hash('sha256', $email);

        if (RateLimiter::tooManyAttempts($ipKey, 10) || RateLimiter::tooManyAttempts($accountKey, 5)) {
            $seconds = max(RateLimiter::availableIn($ipKey), RateLimiter::availableIn($accountKey));

            throw ValidationException::withMessages([
                'email' => "Слишком много попыток. Повторите через {$seconds} сек.",
            ]);
        }

        $user = User::query()->whereRaw('LOWER(email) = ?', [$email])->first();
        if (! $user || ! Hash::check($credentials['password'], (string) $user->password)) {
            RateLimiter::hit($ipKey, 300);
            RateLimiter::hit($accountKey, 300);
            $this->recordLogin($request, null, false);

            throw ValidationException::withMessages([
                'email' => 'Неверный Email или пароль.',
            ]);
        }

        RateLimiter::clear($ipKey);
        RateLimiter::clear($accountKey);
        $request->session()->regenerate();

        if ($user->mfaEnabled()) {
            $request->session()->put('auth.mfa_user_id', $user->id);
            $request->session()->put('auth.mfa_remember', $request->boolean('remember'));
            $this->recordLogin($request, $user, true, 'mfa_required');

            return redirect()->route('admin.mfa.challenge');
        }

        Auth::login($user, $request->boolean('remember'));
        $this->recordLogin($request, $user, true);

        return redirect()->intended(route('admin.dashboard'))
            ->with('toast', ['type' => 'success', 'title' => 'Вход выполнен', 'message' => 'Добро пожаловать в SkyGuardian.']);
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('admin.login')
            ->with('toast', ['type' => 'success', 'title' => 'Выход выполнен', 'message' => 'Сеанс администратора завершён.']);
    }

    private function recordLogin(
        Request $request,
        ?User $user,
        bool $success,
        ?string $result = null,
    ): void {
        try {
            AdminAuditLog::query()->create([
                'user_id' => $user?->id,
                'event' => $result === 'mfa_required'
                    ? 'auth.login.password_accepted'
                    : ($success ? 'auth.login.accepted' : 'auth.login.failed'),
                'route_name' => 'admin.login.store',
                'method' => 'POST',
                'path' => '/admin/login',
                'ip_address' => $request->ip(),
                'user_agent' => mb_substr((string) $request->userAgent(), 0, 500),
                'response_status' => $success ? 302 : 422,
                'metadata' => ['result' => $result ?? ($success ? 'success' : 'invalid_credentials')],
            ]);
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
