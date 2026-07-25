<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\SystemMetricsService;
use Illuminate\Http\JsonResponse;

class SystemMetricsController extends Controller
{
    public function __invoke(SystemMetricsService $service): JsonResponse
    {
        return response()
            ->json($service->snapshot())
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
    }
}
