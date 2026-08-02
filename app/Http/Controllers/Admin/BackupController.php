<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\BackupService;
use Illuminate\Http\JsonResponse;
use RuntimeException;

class BackupController extends Controller
{
    public function show(BackupService $service): JsonResponse
    {
        return response()
            ->json($service->status())
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
    }

    public function store(BackupService $service): JsonResponse
    {
        try {
            $started = $service->start();
        } catch (RuntimeException $exception) {
            report($exception);

            return response()->json([
                'message' => 'Не удалось запустить резервное копирование.',
            ], 503);
        }

        return response()->json([
            'state' => 'running',
            'message' => $started
                ? 'Создание резервной копии запущено.'
                : 'Резервная копия уже создаётся.',
        ], 202);
    }
}
