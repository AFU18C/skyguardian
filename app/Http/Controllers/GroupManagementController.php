<?php

namespace App\Http\Controllers;

use App\Models\AlertBotSetting;
use App\Models\NewsBotSetting;
use App\Models\NewsTechnicalTelegramAccount;
use App\Models\TechnicalTelegramAccount;
use App\Services\Telegram\TelethonAccountService;
use App\Services\Telegram\WelcomeSettingsStore;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\View\View;
use RuntimeException;
use Throwable;

class GroupManagementController extends Controller
{
    public function index(WelcomeSettingsStore $store): View
    {
        $alertAccounts = TechnicalTelegramAccount::query()->where('status', 'connected')->orderByDesc('is_primary')->orderBy('label')->get();
        $newsAccounts = NewsTechnicalTelegramAccount::query()->where('status', 'connected')->orderByDesc('is_primary')->orderBy('label')->get();
        $managedGroups = $store->groups();
        $newsBotConfigured = filled(NewsBotSetting::query()->first()?->bot_token);
        $alertBotConfigured = filled(AlertBotSetting::query()->first()?->bot_token);

        return view('group-management', compact('alertAccounts', 'newsAccounts', 'managedGroups', 'newsBotConfigured', 'alertBotConfigured'));
    }

    public function deleteMessages(Request $request, TelethonAccountService $telegram): JsonResponse
    {
        $data = $request->validate([
            'chat' => ['required', 'string', 'max:255'],
            'account' => ['required', 'string', 'regex:/^(alerts|news):[1-9][0-9]*$/'],
            'period' => ['required', 'in:1,10,all'],
        ]);

        $chat = trim($data['chat']);
        $rateLimitKey = 'telegram-delete-messages:'
            .($request->user()?->getAuthIdentifier() ?? $request->ip())
            .':'.hash('sha256', $chat);

        if (RateLimiter::tooManyAttempts($rateLimitKey, 3)) {
            $seconds = max(1, RateLimiter::availableIn($rateLimitKey));

            return response()->json([
                'ok' => false,
                'message' => 'Слишком много попыток. Повторите через '.$seconds.' сек.',
            ], 429);
        }

        RateLimiter::hit($rateLimitKey, 60);

        $lockDirectory = storage_path('app/private/telegram');
        $lockHandle = null;

        try {
            if (! is_dir($lockDirectory)
                && ! mkdir($lockDirectory, 0770, true)
                && ! is_dir($lockDirectory)) {
                throw new RuntimeException('Не удалось создать каталог блокировки удаления сообщений.');
            }

            $lockKey = hash('sha256', $data['account'].'|'.$chat);
            $lockHandle = fopen($lockDirectory.'/delete-messages-'.$lockKey.'.lock', 'c+');

            if ($lockHandle === false) {
                throw new RuntimeException('Не удалось создать блокировку удаления сообщений.');
            }

            if (! flock($lockHandle, LOCK_EX | LOCK_NB)) {
                return response()->json([
                    'ok' => false,
                    'message' => 'Удаление сообщений для этой группы уже выполняется. Дождитесь завершения.',
                ], 409);
            }

            [$section, $accountId] = explode(':', $data['account'], 2);
            $account = $section === 'news'
                ? NewsTechnicalTelegramAccount::query()->findOrFail((int) $accountId)
                : TechnicalTelegramAccount::query()->findOrFail((int) $accountId);

            if ($account->status !== 'connected') {
                return response()->json(['ok' => false, 'message' => 'Выбранный технический аккаунт не подключён к Telegram.'], 422);
            }

            $result = $telegram->deleteMessages($account, $chat, $data['period']);

            return response()->json([
                'ok' => true,
                'deleted' => (int) ($result['deleted'] ?? 0),
                'message' => (string) ($result['message'] ?? 'Удаление завершено.'),
            ]);
        } catch (ModelNotFoundException) {
            return response()->json(['ok' => false, 'message' => 'Выбранный технический аккаунт не найден.'], 404);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'ok' => false,
                'message' => $exception->getMessage() ?: 'Не удалось удалить сообщения Telegram.',
            ], 422);
        } finally {
            if (is_resource($lockHandle)) {
                flock($lockHandle, LOCK_UN);
                fclose($lockHandle);
            }
        }
    }
}
