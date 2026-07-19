<?php

namespace App\Http\Controllers;

use App\Models\AlertSource;
use App\Models\NewsSource;
use App\Services\Telegram\SourceResumeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class SourcePowerController extends Controller
{
    public function alert(Request $request, AlertSource $alertSource, SourceResumeService $resume): JsonResponse
    {
        return $this->updatePower($request, $alertSource, $resume);
    }

    public function news(Request $request, NewsSource $newsSource, SourceResumeService $resume): JsonResponse
    {
        return $this->updatePower($request, $newsSource, $resume);
    }

    private function updatePower(
        Request $request,
        AlertSource|NewsSource $source,
        SourceResumeService $resume,
    ): JsonResponse {
        $enabled = (bool) $request->validate([
            'enabled' => ['required', 'boolean'],
        ])['enabled'];

        if ($enabled
            && ($source->source_status !== 'available' || $source->destination_status !== 'available')) {
            return response()->json([
                'ok' => false,
                'enabled' => false,
                'message' => 'Сначала успешно проверьте источник и группу назначения.',
            ], 422);
        }

        if ($enabled && ! $source->autopublish_enabled) {
            try {
                $resume->checkpoint($source);
            } catch (Throwable $exception) {
                return response()->json([
                    'ok' => false,
                    'enabled' => false,
                    'message' => 'Не удалось пропустить сообщения за время отключения: '.$exception->getMessage(),
                ], 422);
            }
        }

        $source->forceFill([
            'autopublish_enabled' => $enabled,
        ])->save();

        return response()->json([
            'ok' => true,
            'enabled' => $source->fresh()->autopublish_enabled,
            'message' => $enabled
                ? 'Источник включён. Сообщения за время отключения пропущены.'
                : 'Источник полностью остановлен.',
        ]);
    }
}
