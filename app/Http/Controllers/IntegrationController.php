<?php

namespace App\Http\Controllers;

use App\Models\TelegramAccount;
use App\Services\TelegramSessionService;
use danog\MadelineProto\API;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class IntegrationController extends Controller
{
    public function __construct(private readonly TelegramSessionService $telegramSessions)
    {
    }

    public function index(): View
    {
        $requiredExtensions = ['mbstring', 'xml', 'json', 'fileinfo', 'gmp', 'openssl', 'iconv', 'gd'];
        $extensions = collect($requiredExtensions)
            ->mapWithKeys(fn (string $extension): array => [$extension => extension_loaded($extension)]);

        return view('integrations.index', [
            'madelineInstalled' => class_exists(API::class),
            'extensions' => $extensions,
            'requirementsReady' => $extensions->every(fn (bool $loaded): bool => $loaded),
            'accounts' => TelegramAccount::query()
                ->whereNull('telegram_app_id')
                ->latest()
                ->get(),
            'accountsLimit' => 10,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_if(
            TelegramAccount::query()->whereNull('telegram_app_id')->count() >= 10,
            422,
            'Можно добавить не более 10 Telegram API.',
        );

        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'api_id' => ['required', 'digits_between:4,12'],
            'api_hash' => ['required', 'string', 'size:32'],
            'login_method' => ['required', Rule::in(['phone', 'qr'])],
            'phone' => ['nullable', 'string', 'max:30', 'required_if:login_method,phone'],
        ]);

        $data['status'] = 'not_connected';
        TelegramAccount::query()->create($data);

        return back()->with('status', 'Telegram API добавлен. Нажмите «Подключить».');
    }

    public function update(Request $request, TelegramAccount $telegramAccount): RedirectResponse
    {
        $this->ensureLegacyAccount($telegramAccount);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'api_id' => ['required', 'digits_between:4,12'],
            'api_hash' => ['nullable', 'string', 'size:32'],
            'login_method' => ['required', Rule::in(['phone', 'qr'])],
            'phone' => ['nullable', 'string', 'max:30', 'required_if:login_method,phone'],
        ]);

        if (blank($data['api_hash'] ?? null)) {
            unset($data['api_hash']);
        }

        $telegramAccount->update($data);

        return back()->with('status', 'Настройки Telegram API обновлены.');
    }

    public function startPhone(TelegramAccount $telegramAccount): RedirectResponse
    {
        $this->ensureLegacyAccount($telegramAccount);
        abort_unless($telegramAccount->login_method === 'phone', 422);

        try {
            $api = $this->telegramSessions->api($telegramAccount);
            $api->phoneLogin((string) $telegramAccount->phone);
            $telegramAccount->update(['status' => 'waiting_code', 'last_error' => null]);

            return back()->with('status', 'Код отправлен в Telegram. Введите его ниже.');
        } catch (\Throwable $exception) {
            $this->telegramSessions->markError($telegramAccount, $exception);
            return back()->withErrors(['telegram' => $exception->getMessage()]);
        }
    }

    public function completePhone(Request $request, TelegramAccount $telegramAccount): RedirectResponse
    {
        $this->ensureLegacyAccount($telegramAccount);
        $data = $request->validate(['code' => ['required', 'string', 'max:20']]);

        try {
            $api = $this->telegramSessions->api($telegramAccount);
            $authorization = $api->completePhoneLogin($data['code']);

            if (($authorization['_'] ?? null) === 'account.password') {
                $telegramAccount->update(['status' => 'waiting_password', 'last_error' => null]);
                return back()->with('status', 'Включена двухэтапная защита. Введите пароль Telegram.');
            }

            if (($authorization['_'] ?? null) === 'account.needSignup') {
                throw new \RuntimeException('Этот номер ещё не зарегистрирован в Telegram.');
            }

            $this->telegramSessions->markConnected($telegramAccount, $api);
            return back()->with('status', 'Telegram-аккаунт успешно подключён.');
        } catch (\Throwable $exception) {
            $this->telegramSessions->markError($telegramAccount, $exception);
            return back()->withErrors(['telegram' => $exception->getMessage()]);
        }
    }

    public function completePassword(Request $request, TelegramAccount $telegramAccount): RedirectResponse
    {
        $this->ensureLegacyAccount($telegramAccount);
        $data = $request->validate(['password' => ['required', 'string', 'max:255']]);

        try {
            $api = $this->telegramSessions->api($telegramAccount);
            $api->complete2faLogin($data['password']);
            $this->telegramSessions->markConnected($telegramAccount, $api);

            return back()->with('status', 'Telegram-аккаунт успешно подключён.');
        } catch (\Throwable $exception) {
            $this->telegramSessions->markError($telegramAccount, $exception);
            return back()->withErrors(['telegram' => $exception->getMessage()]);
        }
    }

    public function qr(TelegramAccount $telegramAccount): JsonResponse
    {
        $this->ensureLegacyAccount($telegramAccount);
        abort_unless($telegramAccount->login_method === 'qr', 422);

        try {
            $api = $this->telegramSessions->api($telegramAccount);
            $qr = $api->qrLogin();

            if ($qr) {
                $telegramAccount->update(['status' => 'waiting_qr', 'last_error' => null]);
                return response()->json(['connected' => false, 'needs_password' => false, 'svg' => $qr->getQRSvg(320, 2), 'expires_in' => $qr->expiresIn()]);
            }

            if ($api->getAuthorization() === API::WAITING_PASSWORD) {
                $telegramAccount->update(['status' => 'waiting_password', 'last_error' => null]);
                return response()->json(['connected' => false, 'needs_password' => true]);
            }

            $this->telegramSessions->markConnected($telegramAccount, $api);
            return response()->json(['connected' => true, 'needs_password' => false]);
        } catch (\Throwable $exception) {
            $this->telegramSessions->markError($telegramAccount, $exception);
            return response()->json(['message' => $exception->getMessage()], 422);
        }
    }

    public function disconnect(TelegramAccount $telegramAccount): RedirectResponse
    {
        $this->ensureLegacyAccount($telegramAccount);
        $this->telegramSessions->purge($telegramAccount);
        $telegramAccount->update([
            'status' => 'not_connected',
            'telegram_name' => null,
            'telegram_username' => null,
            'connected_at' => null,
            'last_error' => null,
        ]);

        return back()->with('status', 'Telegram-аккаунт отключён, локальная сессия удалена.');
    }

    public function destroy(TelegramAccount $telegramAccount): RedirectResponse
    {
        $this->ensureLegacyAccount($telegramAccount);
        $this->telegramSessions->purge($telegramAccount);
        $telegramAccount->delete();

        return back()->with('status', 'Telegram API и локальная сессия удалены.');
    }

    private function ensureLegacyAccount(TelegramAccount $telegramAccount): void
    {
        abort_if($telegramAccount->telegram_app_id !== null, 404);
    }
}
