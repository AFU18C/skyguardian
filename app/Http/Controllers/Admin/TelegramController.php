<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\TechnicalAccount;
use App\Models\TelegramApi;
use App\Services\TechnicalAccountService;
use App\Services\TelegramAuthService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Throwable;

class TelegramController extends Controller
{
    public function index(): View
    {
        return view('admin.telegram.index', [
            'accounts' => TechnicalAccount::query()
                ->with(['telegramApi', 'sources'])
                ->latest()
                ->paginate(12, ['*'], 'accounts_page'),
            'apis' => TelegramApi::query()
                ->withCount('technicalAccounts')
                ->orderBy('name')
                ->get(),
            'accountLimitReached' => TechnicalAccount::query()->count() >= config('skyguardian.limits.technical_accounts', 20),
        ]);
    }

    public function storeApi(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'api_id' => ['required', 'integer', 'min:1', 'unique:telegram_apis,api_id'],
            'api_hash' => ['required', 'string', 'min:16', 'max:255'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        try {
            TelegramApi::query()->create([
                ...$validated,
                'is_active' => (bool) ($validated['is_active'] ?? false),
            ]);

            return back()->with('toast', ['type' => 'success', 'title' => 'Telegram API добавлен', 'message' => 'Конфигурация сохранена.']);
        } catch (Throwable $e) {
            report($e);

            return back()->withInput()->with('toast', ['type' => 'error', 'title' => 'Ошибка', 'message' => 'Не удалось сохранить Telegram API.']);
        }
    }

    public function updateApi(Request $request, TelegramApi $telegramApi): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'api_id' => ['nullable', 'integer', 'min:1', Rule::unique('telegram_apis', 'api_id')->ignore($telegramApi->id)],
            'api_hash' => ['nullable', 'string', 'min:16', 'max:255'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        try {
            $telegramApi->fill([
                'name' => $validated['name'],
                'is_active' => (bool) ($validated['is_active'] ?? false),
            ]);

            if (! empty($validated['api_id'])) {
                $telegramApi->api_id = $validated['api_id'];
            }

            if (! empty($validated['api_hash'])) {
                $telegramApi->api_hash = $validated['api_hash'];
            }

            $telegramApi->save();

            return back()->with('toast', ['type' => 'success', 'title' => 'Telegram API обновлён', 'message' => 'Изменения сохранены.']);
        } catch (Throwable $e) {
            report($e);

            return back()->withInput()->with('toast', ['type' => 'error', 'title' => 'Ошибка', 'message' => 'Не удалось обновить Telegram API.']);
        }
    }

    public function destroyApi(TelegramApi $telegramApi): RedirectResponse
    {
        if ($telegramApi->technicalAccounts()->exists()) {
            return back()->with('toast', ['type' => 'warning', 'title' => 'Удаление невозможно', 'message' => 'Сначала перенесите или удалите связанные технические аккаунты.']);
        }

        try {
            $telegramApi->delete();

            return back()->with('toast', ['type' => 'success', 'title' => 'Telegram API удалён', 'message' => 'Конфигурация удалена.']);
        } catch (Throwable $e) {
            report($e);

            return back()->with('toast', ['type' => 'error', 'title' => 'Ошибка', 'message' => 'Не удалось удалить Telegram API.']);
        }
    }

    public function storeAccount(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'auth_method' => ['required', Rule::in(['phone', 'qr'])],
            'phone' => ['nullable', 'required_if:auth_method,phone', 'string', 'max:32'],
            'telegram_api_id' => ['nullable', 'integer', 'exists:telegram_apis,id'],
            'new_api_name' => ['nullable', 'required_without:telegram_api_id', 'string', 'max:255'],
            'new_api_id' => ['nullable', 'required_without:telegram_api_id', 'integer', 'min:1', 'unique:telegram_apis,api_id'],
            'new_api_hash' => ['nullable', 'required_without:telegram_api_id', 'string', 'min:16', 'max:255'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        try {
            DB::transaction(function () use ($validated): void {
                $apiId = $validated['telegram_api_id'] ?? null;

                if (! $apiId) {
                    $api = TelegramApi::query()->create([
                        'name' => $validated['new_api_name'],
                        'api_id' => $validated['new_api_id'],
                        'api_hash' => $validated['new_api_hash'],
                        'is_active' => true,
                    ]);
                    $apiId = $api->id;
                }

                TechnicalAccount::query()->create([
                    'telegram_api_id' => $apiId,
                    'name' => $validated['name'],
                    'auth_method' => $validated['auth_method'],
                    'phone' => $validated['phone'] ?? null,
                    'is_active' => (bool) ($validated['is_active'] ?? false),
                    'status' => 'not_checked',
                ]);
            });

            return back()->with('toast', ['type' => 'success', 'title' => 'Аккаунт добавлен', 'message' => 'Теперь выполните подключение к Telegram.']);
        } catch (Throwable $e) {
            report($e);

            return back()->withInput()->with('toast', ['type' => 'error', 'title' => 'Ошибка', 'message' => 'Не удалось добавить технический аккаунт.']);
        }
    }

    public function updateAccount(Request $request, TechnicalAccount $account): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'auth_method' => ['required', Rule::in(['phone', 'qr'])],
            'phone' => ['nullable', 'string', 'max:32'],
            'telegram_api_id' => ['required', 'integer', 'exists:telegram_apis,id'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $phone = filled($validated['phone'] ?? null) ? $validated['phone'] : $account->phone;

        if ($validated['auth_method'] === 'phone' && ! $phone) {
            throw ValidationException::withMessages(['phone' => 'Укажите номер телефона.']);
        }

        try {
            $account->update([
                'name' => $validated['name'],
                'auth_method' => $validated['auth_method'],
                'phone' => $phone,
                'telegram_api_id' => $validated['telegram_api_id'],
                'is_active' => (bool) ($validated['is_active'] ?? false),
            ]);

            return back()->with('toast', ['type' => 'success', 'title' => 'Аккаунт обновлён', 'message' => 'Изменения сохранены.']);
        } catch (Throwable $e) {
            report($e);

            return back()->withInput()->with('toast', ['type' => 'error', 'title' => 'Ошибка', 'message' => 'Не удалось сохранить аккаунт.']);
        }
    }

    public function destroyAccount(TechnicalAccount $account): RedirectResponse
    {
        try {
            DB::transaction(function () use ($account): void {
                $account->sources()->update([
                    'technical_account_id' => null,
                    'is_active' => false,
                    'status' => 'account_missing',
                    'last_error' => 'Технический аккаунт удалён.',
                    'next_check_at' => null,
                ]);
                $account->delete();
            });

            return back()->with('toast', ['type' => 'success', 'title' => 'Аккаунт удалён', 'message' => 'Связанные источники отключены и сохранены.']);
        } catch (Throwable $e) {
            report($e);

            return back()->with('toast', ['type' => 'error', 'title' => 'Ошибка', 'message' => 'Не удалось удалить технический аккаунт.']);
        }
    }

    public function checkAccount(TechnicalAccount $account, TechnicalAccountService $service): RedirectResponse
    {
        try {
            $service->manualCheck($account->load('telegramApi'));

            return back()->with('toast', ['type' => 'success', 'title' => 'Проверка завершена', 'message' => 'Технический аккаунт подключён и доступен.']);
        } catch (Throwable $e) {
            report($e);

            return back()->with('toast', ['type' => 'error', 'title' => 'Ошибка проверки', 'message' => $this->safeTelegramMessage($e)]);
        }
    }

    public function sendCode(TechnicalAccount $account, TelegramAuthService $service): RedirectResponse
    {
        try {
            $service->sendCode($account->load('telegramApi'));

            return back()->with('toast', ['type' => 'success', 'title' => 'Код отправлен', 'message' => 'Введите код, полученный в Telegram.']);
        } catch (Throwable $e) {
            report($e);

            return back()->with('toast', ['type' => 'error', 'title' => 'Ошибка подключения', 'message' => $this->safeTelegramMessage($e)]);
        }
    }

    public function signIn(Request $request, TechnicalAccount $account, TelegramAuthService $service): RedirectResponse
    {
        $validated = $request->validate(['code' => ['required', 'string', 'max:16']]);

        try {
            $updated = $service->signIn($account->load('telegramApi'), $validated['code']);

            if ($updated->status === 'awaiting_password') {
                return back()->with('toast', ['type' => 'warning', 'title' => 'Требуется пароль', 'message' => 'Введите пароль двухэтапной аутентификации.']);
            }

            return back()->with('toast', ['type' => 'success', 'title' => 'Telegram подключён', 'message' => 'Авторизация успешно завершена.']);
        } catch (Throwable $e) {
            report($e);

            return back()->with('toast', ['type' => 'error', 'title' => 'Ошибка авторизации', 'message' => $this->safeTelegramMessage($e)]);
        }
    }

    public function signInPassword(Request $request, TechnicalAccount $account, TelegramAuthService $service): RedirectResponse
    {
        $validated = $request->validate(['password' => ['required', 'string', 'max:255']]);

        try {
            $service->signInPassword($account->load('telegramApi'), $validated['password']);

            return back()->with('toast', ['type' => 'success', 'title' => 'Telegram подключён', 'message' => 'Двухэтапная авторизация завершена.']);
        } catch (Throwable $e) {
            report($e);

            return back()->with('toast', ['type' => 'error', 'title' => 'Ошибка авторизации', 'message' => $this->safeTelegramMessage($e)]);
        }
    }

    public function startQr(TechnicalAccount $account, TelegramAuthService $service): RedirectResponse
    {
        try {
            $result = $service->startQr($account->load('telegramApi'));

            return back()
                ->with('qr_login', [
                    'account_id' => $account->id,
                    'url' => $result['url'],
                    'expires_at' => $result['expires_at']->toIso8601String(),
                ])
                ->with('toast', ['type' => 'success', 'title' => 'QR-код создан', 'message' => 'Отсканируйте код в приложении Telegram.']);
        } catch (Throwable $e) {
            report($e);

            return back()->with('toast', ['type' => 'error', 'title' => 'Ошибка QR-входа', 'message' => $this->safeTelegramMessage($e)]);
        }
    }

    public function waitQr(TechnicalAccount $account, TelegramAuthService $service): RedirectResponse
    {
        try {
            $result = $service->waitQr($account->load('telegramApi'), 5);

            return match ($result['status']) {
                'connected' => back()->with('toast', ['type' => 'success', 'title' => 'Telegram подключён', 'message' => 'QR-авторизация завершена.']),
                'awaiting_password' => back()->with('toast', ['type' => 'warning', 'title' => 'Требуется пароль', 'message' => 'Введите пароль двухэтапной аутентификации.']),
                'expired' => back()->with('toast', ['type' => 'error', 'title' => 'QR-код истёк', 'message' => 'Создайте новый QR-код.']),
                default => back()->with('toast', ['type' => 'warning', 'title' => 'Ожидание', 'message' => 'QR-код ещё не подтверждён.']),
            };
        } catch (Throwable $e) {
            report($e);

            return back()->with('toast', ['type' => 'error', 'title' => 'Ошибка QR-входа', 'message' => $this->safeTelegramMessage($e)]);
        }
    }

    private function safeTelegramMessage(Throwable $e): string
    {
        $message = $e->getMessage();
        $allowedFragments = [
            'Не указан номер телефона',
            'Код авторизации истёк',
            'Технический аккаунт не авторизован',
            'QR-сессия не найдена',
            'Срок действия QR-кода истёк',
            'Telethon daemon недоступен',
            'Неверный код',
            'пароль',
        ];

        foreach ($allowedFragments as $fragment) {
            if (mb_stripos($message, $fragment) !== false) {
                return mb_substr($message, 0, 240);
            }
        }

        return 'Операция Telegram не выполнена. Подробности записаны в журнал.';
    }
}
