<?php

namespace App\Http\Controllers;

use App\Models\NewsTechnicalTelegramAccount;
use App\Models\TechnicalTelegramAccount;
use App\Services\Telegram\TelethonAccountService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;

class GroupManagementController extends Controller
{
    public function index(): View
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

        return view('group-management', compact('alertAccounts', 'newsAccounts'));
    }

    public function deleteMessages(Request $request, TelethonAccountService $telegram): JsonResponse
    {
        $data = $request->validate([
            'chat' => ['required', 'string', 'max:255'],
            'account' => ['required', 'string', 'regex:/^(alerts|news):[1-9][0-9]*$/'],
            'period' => ['required', 'in:1,10,all'],
        ]);

        [$section, $accountId] = explode(':', $data['account'], 2);

        $account = $section === 'news'
            ? NewsTechnicalTelegramAccount::query()->findOrFail((int) $accountId)
            : TechnicalTelegramAccount::query()->findOrFail((int) $accountId);

        if ($account->status !== 'connected') {
            throw new RuntimeException('Выбранный технический аккаунт не подключён к Telegram.');
        }

        $result = $telegram->deleteMessages($account, trim($data['chat']), $data['period']);

        return response()->json([
            'ok' => true,
            'deleted' => (int) ($result['deleted'] ?? 0),
            'message' => (string) ($result['message'] ?? 'Удаление завершено.'),
        ]);
    }
}
