<?php

namespace App\Http\Controllers;

use App\Models\AlertSource;
use App\Models\NewsSource;
use App\Models\NewsTelegramApiCredential;
use App\Models\TelegramApiCredential;
use App\Services\Telegram\SourceResumeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class TelegramApiPowerController extends Controller
{
    public function alert(
        Request $request,
        TelegramApiCredential $telegramApi,
        SourceResumeService $resume,
    ): JsonResponse {
        $enabled = (bool) $request->validate([
            'enabled' => ['required', 'boolean'],
        ])['enabled'];

        if ($enabled && ! $telegramApi->is_enabled) {
            $sources = AlertSource::query()
                ->where('autopublish_enabled', true)
                ->where(function ($query) use ($telegramApi): void {
                    $query->whereHas('readerAccount', function ($accountQuery) use ($telegramApi): void {
                        $accountQuery->where('telegram_api_credential_id', $telegramApi->getKey());
                    })->orWhereHas('publisherAccount', function ($accountQuery) use ($telegramApi): void {
                        $accountQuery->where('telegram_api_credential_id', $telegramApi->getKey());
                    });
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

        $telegramApi->update(['is_enabled' => $enabled]);

        return response()->json(['ok' => true, 'enabled' => $enabled]);
    }

    public function news(
        Request $request,
        NewsTelegramApiCredential $newsTelegramApi,
        SourceResumeService $resume,
    ): JsonResponse {
        $enabled = (bool) $request->validate([
            'enabled' => ['required', 'boolean'],
        ])['enabled'];

        if ($enabled && ! $newsTelegramApi->is_enabled) {
            $sources = NewsSource::query()
                ->where('autopublish_enabled', true)
                ->where(function ($query) use ($newsTelegramApi): void {
                    $query->whereHas('readerAccount', function ($accountQuery) use ($newsTelegramApi): void {
                        $accountQuery->where('news_telegram_api_credential_id', $newsTelegramApi->getKey());
                    })->orWhereHas('publisherAccount', function ($accountQuery) use ($newsTelegramApi): void {
                        $accountQuery->where('news_telegram_api_credential_id', $newsTelegramApi->getKey());
                    });
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

        $newsTelegramApi->update(['is_enabled' => $enabled]);

        return response()->json(['ok' => true, 'enabled' => $enabled]);
    }
}
