<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\View\View;

class TelegramAuthController extends Controller
{
    public function show(): View|RedirectResponse
    {
        if (Auth::check()) {
            return redirect()->intended('/');
        }

        $botToken = (string) config('telegram-auth.bot_token');
        $botId = Str::before($botToken, ':');

        return view('auth.telegram-login', [
            'telegramBotId' => ctype_digit($botId) ? $botId : null,
            'telegramBotUsername' => config('telegram-auth.bot_username'),
        ]);
    }

    public function callback(Request $request): RedirectResponse
    {
        $payload = $request->only([
            'id',
            'first_name',
            'last_name',
            'username',
            'photo_url',
            'auth_date',
            'hash',
        ]);

        if (! $this->isValidTelegramPayload($payload)) {
            return redirect()->route('login')
                ->withErrors(['telegram' => 'Не удалось подтвердить вход через Telegram.']);
        }

        $telegramId = (string) $payload['id'];
        $allowedIds = collect(config('telegram-auth.allowed_ids', []))
            ->map(static fn ($id) => trim((string) $id))
            ->filter();

        if ($allowedIds->isNotEmpty() && ! $allowedIds->contains($telegramId)) {
            return redirect()->route('login')
                ->withErrors(['telegram' => 'У этого Telegram-аккаунта нет доступа к SkyGuardian.']);
        }

        $adminEmail = (string) config('telegram-auth.admin_email');
        $user = User::query()->where('email', $adminEmail)->first();

        if (! $user) {
            return redirect()->route('login')
                ->withErrors(['telegram' => 'Администратор для входа через Telegram не найден.']);
        }

        Auth::login($user, true);
        $request->session()->regenerate();

        $request->session()->put('telegram_user', [
            'id' => $telegramId,
            'username' => $payload['username'] ?? null,
            'first_name' => $payload['first_name'] ?? null,
            'last_name' => $payload['last_name'] ?? null,
            'photo_url' => $payload['photo_url'] ?? null,
        ]);

        return redirect()->intended('/');
    }

    private function isValidTelegramPayload(array $payload): bool
    {
        $botToken = (string) config('telegram-auth.bot_token');
        $hash = (string) ($payload['hash'] ?? '');
        $authDate = (int) ($payload['auth_date'] ?? 0);

        if ($botToken === '' || $hash === '' || $authDate === 0) {
            return false;
        }

        if (abs(now()->timestamp - $authDate) > (int) config('telegram-auth.max_auth_age', 300)) {
            return false;
        }

        unset($payload['hash']);
        ksort($payload);

        $dataCheckString = collect($payload)
            ->filter(static fn ($value) => $value !== null && $value !== '')
            ->map(static fn ($value, $key) => $key.'='.$value)
            ->implode("\n");

        $secretKey = hash('sha256', $botToken, true);
        $calculatedHash = hash_hmac('sha256', $dataCheckString, $secretKey);

        return hash_equals($calculatedHash, $hash);
    }
}
