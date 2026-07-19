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
use Illuminate\View\View;
use Throwable;

class GroupManagementController extends Controller
{
    public function index(WelcomeSettingsStore $welcomeStore): View
    {
        $alertAccounts = TechnicalTelegramAccount::query()
            ->where('status', 'connected')
            ->orderByDesc('is_primary')
            ->orderBy('label')
            ->get();

        $newsAccounts = NewsTechnicalTelegramAccount::query()
            ->where('status', 'connected')
            ->orderByDesc('is_primary')
            ->orderBy('label')
            ->get();

        $welcomeSettings = $welcomeStore->get();
        $newsBotConfigured = filled(NewsBotSetting::query()->first()?->bot_token);
        $alertBotConfigured = filled(AlertBotSetting::query()->first()?->bot_token);

        return view('group-management', compact(
            'alertAccounts',
            'newsAccounts',
            'welcomeSettings',
            'newsBotConfigured',
            'alertBotConfigured',
        ));
    }

    public function deleteMessages(Request $request, TelethonAccountService $telegram): JsonResponse
    {
        $data = $request->validate([
            'chat' => ['required', 'string', 'max:255'],
            'account' => ['required', 'string', 'regex:/^(alerts|news):[1-9][0-9]*$/'],
            'period' => ['required', 'in:1,10,all'],
        ]);

        try {
            [$section, $accountId] = explode(':', $data['account'], 2);

            $account = $section === 'news'
                ? NewsTechnicalTelegramAccount::query()->findOrFail((int) $accountId)
                : TechnicalTelegramAccount::query()->findOrFail((int) $accountId);

            if ($account->status !== 'connected') {
                return response()->json([
                    'ok' => false,
                    'message' => 'Выбранный технический аккаунт не подключён к Telegram.',
                ], 422);
            }

            $result = $telegram->deleteMessages($account, trim($data['chat']), $data['period']);

            return response()->json([
                'ok' => true,
                'deleted' => (int) ($result['deleted'] ?? 0),
                'message' => (string) ($result['message'] ?? 'Удаление завершено.'),
            ]);
        } catch (ModelNotFoundException) {
            return response()->json([
                'ok' => false,
                'message' => 'Выбранный технический аккаунт не найден. Обновите страницу и выберите его заново.',
            ], 404);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'ok' => false,
                'message' => $exception->getMessage() ?: 'Не удалось удалить сообщения Telegram.',
            ], 422);
        }
    }
}
