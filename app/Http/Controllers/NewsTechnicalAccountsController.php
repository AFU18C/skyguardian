<?php

namespace App\Http\Controllers;

use App\Models\TelegramAccount;
use App\Models\TelegramApp;
use App\Services\TelegramSessionService;
use App\Services\TelegramFloodWait;
use danog\MadelineProto\API;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Throwable;

class NewsTechnicalAccountsController extends Controller
{
    public function __construct(private readonly TelegramSessionService $sessions)
    {
    }

    public function create(TelegramApp $telegramApp): View
    {
        $this->ensureNewsApp($telegramApp);

        return view('admin.news-technical-account-form', [
            'telegramApp' => $telegramApp,
            'account' => null,
            'editing' => false,
        ]);
    }

    public function store(Request $request, TelegramApp $telegramApp): RedirectResponse
    {
        $this->ensureNewsApp($telegramApp);
        abort_if($telegramApp->accounts()->count() >= 10, 422, 'Можно добавить не более 10 техаккаунтов для одного Telegram App.');

        $data = $this->validated($request, $telegramApp);
        $data['telegram_app_id'] = $telegramApp->id;
        $data['purpose'] = 'news';
        $data['status'] = 'not_connected';
        $data['is_active'] = true;

        $account = TelegramAccount::query()->create($data);

        return redirect()
            ->route('news.accounts.edit', [$telegramApp, $account])
            ->with('status', 'Техаккаунт сохранён. Выполните авторизацию Telegram.');
    }

    public function edit(TelegramApp $telegramApp, TelegramAccount $account): View
    {
        $this->ensureAccount($telegramApp, $account);

        return view('admin.news-technical-account-form', [
            'telegramApp' => $telegramApp,
            'account' => $account,
            'editing' => true,
        ]);
    }

    public function update(
        Request $request,
        TelegramApp $telegramApp,
        TelegramAccount $account,
    ): RedirectResponse {
        $this->ensureAccount($telegramApp, $account);
        $data = $this->validated($request, $telegramApp, $account);
        $requiresReconnect = $account->login_method !== $data['login_method']
            || (string) $account->phone !== (string) ($data['phone'] ?? null);

        if ($requiresReconnect) {
            return $this->withAccountLock($account, function () use ($account, $data): RedirectResponse {
                $this->sessions->purge($account);
                $account->update(array_merge($data, [
                    'status' => 'not_connected',
                    'connected_at' => null,
                    'telegram_name' => null,
                    'telegram_username' => null,
                    'last_error' => null,
                ]));
                $account->sources()
                    ->where('purpose', 'news')
                    ->update([
                        'resume_from_latest' => true,
                        'next_check_at' => null,
                    ]);

                return back()->with('status', 'Техаккаунт сохранён. Подключите его заново.');
            });
        }

        $account->update($data);

        return back()->with('status', 'Техаккаунт сохранён.');
    }

    public function startPhone(TelegramApp $telegramApp, TelegramAccount $account): RedirectResponse
    {
        $this->ensureAccount($telegramApp, $account);
        abort_unless($account->login_method === 'phone', 422);

        return $this->withAccountLock($account, function () use ($account): RedirectResponse {
            try {
                $api = $this->sessions->api($account);
                $api->phoneLogin((string) $account->phone);
                $this->sessions->checkpoint($api);
                $account->update([
                    'status' => 'waiting_code',
                    'last_attempt_at' => now(),
                    'last_error' => null,
                ]);

                return back()->with('status', 'Код отправлен в Telegram. Введите его ниже.');
            } catch (Throwable $exception) {
                $this->sessions->markError($account, $exception);

                return back()->withErrors(['telegram' => $exception->getMessage()]);
            }
        });
    }

    public function completePhone(
        Request $request,
        TelegramApp $telegramApp,
        TelegramAccount $account,
    ): RedirectResponse {
        $this->ensureAccount($telegramApp, $account);
        $data = $request->validate(['code' => ['required', 'string', 'max:20']]);

        return $this->withAccountLock($account, function () use ($account, $data): RedirectResponse {
            try {
                $api = $this->sessions->api($account);
                $authorization = $api->completePhoneLogin($data['code']);

                if (($authorization['_'] ?? null) === 'account.password') {
                    $this->sessions->checkpoint($api);
                    $account->update(['status' => 'waiting_password', 'last_error' => null]);

                    return back()->with('status', 'Включена двухэтапная защита. Введите пароль Telegram.');
                }

                if (($authorization['_'] ?? null) === 'account.needSignup') {
                    throw new \RuntimeException('Этот номер ещё не зарегистрирован в Telegram.');
                }

                $this->sessions->markConnected($account, $api);

                return back()->with('status', 'Технический аккаунт успешно подключён.');
            } catch (Throwable $exception) {
                if (TelegramFloodWait::seconds($exception) !== null) {
                    $this->sessions->markError($account, $exception);
                } else {
                    $account->update([
                        'status' => 'waiting_code',
                        'last_error' => mb_substr($exception->getMessage(), 0, 1000),
                    ]);
                }

                return back()->withErrors(['telegram' => $exception->getMessage()]);
            }
        });
    }

    public function completePassword(
        Request $request,
        TelegramApp $telegramApp,
        TelegramAccount $account,
    ): RedirectResponse {
        $this->ensureAccount($telegramApp, $account);
        $data = $request->validate(['password' => ['required', 'string', 'max:255']]);

        return $this->withAccountLock($account, function () use ($account, $data): RedirectResponse {
            try {
                $api = $this->sessions->api($account);
                $api->complete2faLogin($data['password']);
                $this->sessions->markConnected($account, $api);

                return back()->with('status', 'Технический аккаунт успешно подключён.');
            } catch (Throwable $exception) {
                if (TelegramFloodWait::seconds($exception) !== null) {
                    $this->sessions->markError($account, $exception);
                } else {
                    $account->update([
                        'status' => 'waiting_password',
                        'last_error' => mb_substr($exception->getMessage(), 0, 1000),
                    ]);
                }

                return back()->withErrors(['telegram' => $exception->getMessage()]);
            }
        });
    }

    public function qr(TelegramApp $telegramApp, TelegramAccount $account): JsonResponse
    {
        $this->ensureAccount($telegramApp, $account);
        abort_unless($account->login_method === 'qr', 422);

        $lock = Cache::lock('news:telegram-account:'.$account->id, 60);

        if (! $lock->get()) {
            return response()->json(['message' => 'Техаккаунт уже используется другой проверкой.'], 409);
        }

        try {
            $api = $this->sessions->api($account);
            $qr = $api->qrLogin();

            if ($qr) {
                $this->sessions->checkpoint($api);
                $account->update(['status' => 'waiting_qr', 'last_error' => null]);

                return response()->json([
                    'connected' => false,
                    'needs_password' => false,
                    'svg' => $qr->getQRSvg(280, 2),
                    'expires_in' => max(1, $qr->expiresIn()),
                ]);
            }

            if ($api->getAuthorization() === API::WAITING_PASSWORD) {
                $account->update(['status' => 'waiting_password', 'last_error' => null]);

                return response()->json([
                    'connected' => false,
                    'needs_password' => true,
                ]);
            }

            $this->sessions->markConnected($account, $api);

            return response()->json(['connected' => true, 'needs_password' => false]);
        } catch (Throwable $exception) {
            $this->sessions->markError($account, $exception);

            return response()->json(['message' => $exception->getMessage()], 422);
        } finally {
            $lock->release();
        }
    }

    public function toggle(TelegramApp $telegramApp, TelegramAccount $account): RedirectResponse
    {
        $this->ensureAccount($telegramApp, $account);
        $enabling = ! $account->is_active;
        $account->update(['is_active' => $enabling]);
        $account->sources()
            ->where('purpose', 'news')
            ->where('is_active', true)
            ->update([
                'resume_from_latest' => $enabling,
                'next_check_at' => $enabling ? now() : null,
            ]);

        return redirect()->route('news.settings')->with('status', 'Статус техаккаунта изменён.');
    }

    public function disconnect(TelegramApp $telegramApp, TelegramAccount $account): RedirectResponse
    {
        $this->ensureAccount($telegramApp, $account);

        return $this->withAccountLock($account, function () use ($account): RedirectResponse {
            $this->sessions->purge($account);
            $account->update([
                'status' => 'not_connected',
                'telegram_name' => null,
                'telegram_username' => null,
                'connected_at' => null,
                'last_error' => null,
            ]);
            $account->sources()
                ->where('purpose', 'news')
                ->update([
                    'resume_from_latest' => true,
                    'next_check_at' => null,
                ]);

            return back()->with('status', 'Техаккаунт отключён. Зашифрованная сессия удалена.');
        });
    }

    public function destroy(TelegramApp $telegramApp, TelegramAccount $account): RedirectResponse
    {
        $this->ensureAccount($telegramApp, $account);

        return $this->withAccountLock($account, function () use ($telegramApp, $account): RedirectResponse {
            $account->sources()
                ->where('purpose', 'news')
                ->update([
                    'telegram_account_id' => null,
                    'is_active' => false,
                    'resume_from_latest' => true,
                    'next_check_at' => null,
                    'last_error' => 'Технический аккаунт удалён. Выберите другой аккаунт.',
                ]);

            $this->sessions->purge($account);
            $account->delete();

            return redirect()
                ->route('news.settings.edit', $telegramApp)
                ->with('status', 'Техаккаунт удалён. Связанные каналы данных сохранены и отключены.');
        });
    }

    private function validated(
        Request $request,
        TelegramApp $telegramApp,
        ?TelegramAccount $account = null,
    ): array
    {
        return $request->validate([
            'name' => [
                'required',
                'string',
                'max:100',
                Rule::unique('telegram_accounts', 'name')
                    ->where(fn ($query) => $query->where('telegram_app_id', $telegramApp->id))
                    ->ignore($account?->id),
            ],
            'login_method' => ['required', Rule::in(['phone', 'qr'])],
            'phone' => ['nullable', 'string', 'max:30', 'required_if:login_method,phone'],
        ]);
    }

    private function ensureNewsApp(TelegramApp $telegramApp): void
    {
        abort_unless($telegramApp->purpose === 'news', 404);
    }

    private function ensureAccount(TelegramApp $telegramApp, TelegramAccount $account): void
    {
        $this->ensureNewsApp($telegramApp);
        abort_unless($account->telegram_app_id === $telegramApp->id && $account->purpose === 'news', 404);
        $account->setRelation('telegramApp', $telegramApp);
    }

    /**
     * @template T of RedirectResponse
     * @param callable(): T $callback
     * @return T
     */
    private function withAccountLock(TelegramAccount $account, callable $callback): RedirectResponse
    {
        $lock = Cache::lock('news:telegram-account:'.$account->id, 120);

        if (! $lock->get()) {
            return back()->withErrors(['telegram' => 'Техаккаунт уже используется другой проверкой. Повторите через несколько секунд.']);
        }

        try {
            return $callback();
        } finally {
            $lock->release();
        }
    }
}
