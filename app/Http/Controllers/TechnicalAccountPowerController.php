<?php

namespace App\Http\Controllers;

use App\Models\AlertSource;
use App\Models\NewsSource;
use App\Models\NewsTechnicalTelegramAccount;
use App\Models\TechnicalTelegramAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TechnicalAccountPowerController extends Controller
{
    public function alert(Request $request, TechnicalTelegramAccount $account): JsonResponse
    {
        $enabled = $request->validate(['enabled' => ['required', 'boolean']])['enabled'];

        if ($enabled && (! $account->telegram_api_credential_id || ! filled($account->telegram_id))) {
            return response()->json([
                'ok' => false,
                'message' => 'Сначала выберите Telegram API и переподключите технический аккаунт.',
            ], 422);
        }

        $account->update([
            'status' => $enabled ? 'connected' : 'disabled',
            'last_error' => $enabled ? null : $account->last_error,
        ]);

        if (! $enabled) {
            AlertSource::query()
                ->where(function ($query) use ($account): void {
                    $query->where('reader_account_id', $account->getKey())
                        ->orWhere('publisher_account_id', $account->getKey());
                })
                ->update(['autopublish_enabled' => false]);
        }

        return response()->json(['ok' => true, 'enabled' => $enabled]);
    }

    public function news(Request $request, NewsTechnicalTelegramAccount $account): JsonResponse
    {
        $enabled = $request->validate(['enabled' => ['required', 'boolean']])['enabled'];

        if ($enabled && (! $account->news_telegram_api_credential_id || ! filled($account->telegram_id))) {
            return response()->json([
                'ok' => false,
                'message' => 'Сначала выберите Telegram API и переподключите технический аккаунт.',
            ], 422);
        }

        $account->update([
            'status' => $enabled ? 'connected' : 'disabled',
            'last_error' => $enabled ? null : $account->last_error,
        ]);

        if (! $enabled) {
            NewsSource::query()
                ->where(function ($query) use ($account): void {
                    $query->where('reader_account_id', $account->getKey())
                        ->orWhere('publisher_account_id', $account->getKey());
                })
                ->update(['autopublish_enabled' => false]);
        }

        return response()->json(['ok' => true, 'enabled' => $enabled]);
    }
}
