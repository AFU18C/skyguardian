<?php

namespace App\Http\Controllers;

use App\Services\HealthCheckService;
use Illuminate\Http\JsonResponse;

class HealthController extends Controller
{
    public function __invoke(HealthCheckService $health): JsonResponse
    {
        $snapshot = $health->snapshot();
        $status = $snapshot['healthy'] ? 200 : 503;

        return response()->json([
            'status' => $snapshot['healthy'] ? 'ok' : 'degraded',
            'checks' => collect($snapshot['checks'])
                ->mapWithKeys(fn (array $check, string $name): array => [$name => $check['status']])
                ->all(),
            'checked_at' => $snapshot['checked_at'],
        ], $status, [
            'Cache-Control' => 'no-store, max-age=0',
        ]);
    }
}
