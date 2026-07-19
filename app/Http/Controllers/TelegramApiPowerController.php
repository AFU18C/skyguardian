<?php

namespace App\Http\Controllers;

use App\Models\NewsTelegramApiCredential;
use App\Models\TelegramApiCredential;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TelegramApiPowerController extends Controller
{
    public function alert(Request $request, TelegramApiCredential $telegramApi): JsonResponse
    {
        $enabled = $request->validate([
            'enabled' => ['required', 'boolean'],
        ])['enabled'];

        $telegramApi->update(['is_enabled' => $enabled]);

        return response()->json(['ok' => true, 'enabled' => $enabled]);
    }

    public function news(Request $request, NewsTelegramApiCredential $newsTelegramApi): JsonResponse
    {
        $enabled = $request->validate([
            'enabled' => ['required', 'boolean'],
        ])['enabled'];

        $newsTelegramApi->update(['is_enabled' => $enabled]);

        return response()->json(['ok' => true, 'enabled' => $enabled]);
    }
}
