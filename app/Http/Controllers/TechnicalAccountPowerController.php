<?php

namespace App\Http\Controllers;

use App\Models\AlertSource;
use App\Models\NewsSource;
use App\Models\NewsTechnicalTelegramAccount;
use App\Models\TechnicalTelegramAccount;
use App\Services\Telegram\SourceResumeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class TechnicalAccountPowerController extends Controller
{
    public function alert(
        Request $request,
        TechnicalTelegramAccount $account,
        SourceResumeService $resume,
    ): JsonResponse {
        $enabled = (bool) $request->validate([
            'enabled' => ['required', 'boolean'],
        ])['enabled'];

        if ($enabled && (! $account->telegram_api_credential_id || ! filled($account->telegram_id))) {
            return response()->json([
                'ok' => false,
                'message' => 'Сначала выберите Telegram API и переподключите технический аккаунт.',
            ], 422);
        }

        if ($enabled && $account->status === 'disabled') {
            $sources = AlertSource::query()
                ->where('autopublish_enabled', true)
                ->where(function ($query) use ($account): void {
                    $query->where('reader_account_id', $account->getKey())
                        ->orWhere('publisher_account_id', $account->getKey());
                })
                ->with('readerAccount.telegramApiCredential')
                ->get();

            try {
                $resume->checkpointMany($sources);
            } catch (Throwable $exception) {
                return response()->json([
                    'ok' => false,
                    'enabled' => false,
                    'message' => 'Не удалось пропустить сообщения за время отключения: '.$exception->getMessage(),
                ], 422);
            }
        }

        $account->update([
            'status' => $enabled ? 'connected' : 'disabled',
        ]);

        return response()->json([
            'ok' => true,
            'enabled' => $enabled,
        ]);
    }

    public function news(
        Request $request,
        NewsTechnicalTelegramAccount $account,
        SourceResumeService $resume,
    ): JsonResponse {
        $enabled = (bool) $request->validate([
            'enabled' => ['required', 'boolean'],
        ])['enabled'];

        if ($enabled && (! $account->news_telegram_api_credential_id || ! filled($account->telegram_id))) {
            return response()->json([
                'ok' => false,
                'message' => 'Сначала выберите Telegram API и переподключите технический аккаунт.',
            ], 422);
        }

        if ($enabled && $account->status === 'disabled') {
            $sources = NewsSource::query()
                ->where('autopublish_enabled', true)
                ->where(function ($query) use ($account): void {
                    $query->where('reader_account_id', $account->getKey())
                        ->orWhere('publisher_account_id', $account->getKey());
                })
                ->with('readerAccount.telegramApiCredential')
                ->get();

            try {
                $resume->checkpointMany($sources);
            } catch (Throwable $exception) {
                return response()->json([
                    'ok' => false,
                    'enabled' => false,
                    'message' => 'Не удалось пропустить сообщения за время отключения: '.$exception->getMessage(),
                ], 422);
            }
        }

        $account->update([
            'status' => $enabled ? 'connected' : 'disabled',
        ]);

        return response()->json([
            'ok' => true,
            'enabled' => $enabled,
        ]);
    }
}
