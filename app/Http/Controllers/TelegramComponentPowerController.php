<?php

namespace App\Http\Controllers;

use App\Models\AlertBotSetting;
use App\Models\NewsBotSetting;
use App\Models\NewsTechnicalTelegramAccount;
use App\Models\NewsTelegramApiCredential;
use App\Models\TechnicalTelegramAccount;
use App\Models\TelegramApiCredential;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TelegramComponentPowerController extends Controller
{
    public function __invoke(Request $request, string $section, string $type, int $id): JsonResponse
    {
        abort_unless(in_array($section, ['alerts', 'news'], true), 404);
        abort_unless(in_array($type, ['account', 'api'], true), 404);

        $validated = $request->validate(['enabled' => ['required', 'boolean']]);
        $enabled = (bool) $validated['enabled'];

        $settings = $section === 'news'
            ? NewsBotSetting::query()->firstOrCreate([], ['service_status' => 'stopped'])
            : AlertBotSetting::query()->firstOrCreate([]);

        $exists = match ([$section, $type]) {
            ['alerts', 'account'] => TechnicalTelegramAccount::query()->whereKey($id)->exists(),
            ['alerts', 'api'] => TelegramApiCredential::query()->whereKey($id)->exists(),
            ['news', 'account'] => NewsTechnicalTelegramAccount::query()->whereKey($id)->exists(),
            ['news', 'api'] => NewsTelegramApiCredential::query()->whereKey($id)->exists(),
        };
        abort_unless($exists, 404);

        $key = $type === 'account' ? 'disabled_account_ids' : 'disabled_api_ids';
        $extra = is_array($settings->extra_settings) ? $settings->extra_settings : [];
        $ids = array_values(array_unique(array_map('intval', (array) ($extra[$key] ?? []))));

        if ($enabled) {
            $ids = array_values(array_filter($ids, fn (int $storedId): bool => $storedId !== $id));
        } elseif (! in_array($id, $ids, true)) {
            $ids[] = $id;
        }

        $extra[$key] = $ids;
        $settings->extra_settings = $extra;
        $settings->save();

        return response()->json([
            'ok' => true,
            'enabled' => $enabled,
            'message' => $enabled ? 'Компонент включён.' : 'Компонент полностью остановлен.',
        ]);
    }
}
