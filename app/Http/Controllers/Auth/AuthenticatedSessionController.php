<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\SiteContentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
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

        $ip = (string) $request->ip();
        $identityKey = 'admin-login:identity:'.hash('sha256', Str::lower($request->string('email')).'|'.$ip);
        $ipKey = 'admin-login:ip:'.hash('sha256', $ip);

        if (RateLimiter::tooManyAttempts($ipKey, 15)) {
            $seconds = RateLimiter::availableIn($ipKey);

            throw ValidationException::withMessages([
                'email' => "Слишком много попыток входа с этого адреса. Повторите через {$seconds} сек.",
            ]);
        }

        if (RateLimiter::tooManyAttempts($identityKey, 5)) {
            $seconds = RateLimiter::availableIn($identityKey);

            throw ValidationException::withMessages([
                'email' => "Слишком много попыток. Повторите через {$seconds} сек.",
            ]);
        }

        if (! Auth::attempt($credentials, $request->boolean('remember'))) {
            RateLimiter::hit($identityKey, 60);
            RateLimiter::hit($ipKey, 300);

            throw ValidationException::withMessages([
                'email' => 'Неверный Email или пароль.',
            ]);
        }

        RateLimiter::clear($identityKey);
        RateLimiter::clear($ipKey);
        $request->session()->regenerate();

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
}
