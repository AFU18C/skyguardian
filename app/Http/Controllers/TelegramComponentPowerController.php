<?php

namespace App\Http\Controllers;

use App\Models\NewsTechnicalTelegramAccount;
use App\Models\NewsTelegramApiCredential;
use App\Models\TechnicalTelegramAccount;
use App\Models\TelegramApiCredential;
use App\Services\Telegram\TelegramComponentPowerStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TelegramComponentPowerController extends Controller
{
    public function __invoke(
        Request $request,
        string $section,
        string $type,
        int $id,
        TelegramComponentPowerStore $power,
    ): JsonResponse {
        abort_unless(in_array($section, ['alerts', 'news'], true), 404);
        abort_unless(in_array($type, ['account', 'api'], true), 404);

        $validated = $request->validate(['enabled' => ['required', 'boolean']]);
        $enabled = (bool) $validated['enabled'];

        $exists = match ([$section, $type]) {
            ['alerts', 'account'] => TechnicalTelegramAccount::query()->whereKey($id)->exists(),
            ['alerts', 'api'] => TelegramApiCredential::query()->whereKey($id)->exists(),
            ['news', 'account'] => NewsTechnicalTelegramAccount::query()->whereKey($id)->exists(),
            ['news', 'api'] => NewsTelegramApiCredential::query()->whereKey($id)->exists(),
        };
        abort_unless($exists, 404);

        $power->setComponentEnabled($section, $type, $id, $enabled);

        return response()->json([
            'ok' => true,
            'enabled' => $enabled,
            'message' => $enabled ? 'Компонент включён.' : 'Компонент полностью остановлен.',
        ]);
    }
}
