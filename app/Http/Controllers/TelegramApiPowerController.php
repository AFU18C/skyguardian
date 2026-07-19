<?php

namespace App\Http\Controllers;

use App\Models\NewsTelegramApiCredential;
use App\Models\TelegramApiCredential;
use App\Services\Telegram\TelegramApiPowerStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TelegramApiPowerController extends Controller
{
    public function alert(Request $request, TelegramApiCredential $telegramApi, TelegramApiPowerStore $store): JsonResponse
    {
        $enabled = $request->validate(['enabled' => ['required', 'boolean']])['enabled'];
        $store->set('alerts', $telegramApi->getKey(), $enabled);

        return response()->json(['ok' => true, 'enabled' => $enabled]);
    }

    public function news(Request $request, NewsTelegramApiCredential $newsTelegramApi, TelegramApiPowerStore $store): JsonResponse
    {
        $enabled = $request->validate(['enabled' => ['required', 'boolean']])['enabled'];
        $store->set('news', $newsTelegramApi->getKey(), $enabled);

        return response()->json(['ok' => true, 'enabled' => $enabled]);
    }
}
