<?php

namespace App\Http\Controllers;

use App\Models\AlertSource;
use App\Models\NewsSource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SourcePowerController extends Controller
{
    public function alert(Request $request, AlertSource $alertSource): JsonResponse
    {
        return $this->updatePower($request, $alertSource);
    }

    public function news(Request $request, NewsSource $newsSource): JsonResponse
    {
        return $this->updatePower($request, $newsSource);
    }

    private function updatePower(Request $request, AlertSource|NewsSource $source): JsonResponse
    {
        $validated = $request->validate([
            'enabled' => ['required', 'boolean'],
        ]);

        $enabled = (bool) $validated['enabled'];

        if ($enabled
            && ($source->source_status !== 'available' || $source->destination_status !== 'available')) {
            return response()->json([
                'ok' => false,
                'enabled' => false,
                'message' => 'Сначала успешно проверьте источник и группу назначения.',
            ], 422);
        }

        $source->forceFill([
            'autopublish_enabled' => $enabled,
        ])->save();

        return response()->json([
            'ok' => true,
            'enabled' => $source->fresh()->autopublish_enabled,
            'message' => $enabled
                ? 'Источник включён.'
                : 'Источник полностью остановлен.',
        ]);
    }
}
