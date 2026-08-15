<?php

namespace App\Http\Middleware;

use App\Models\AdminAuditLog;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class AuditAdminAction
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethodSafe()) {
            return $next($request);
        }

        $userId = $request->user()?->id;

        try {
            $response = $next($request);
            $this->record($request, $response->getStatusCode(), $userId);

            return $response;
        } catch (Throwable $e) {
            $this->record(
                $request,
                method_exists($e, 'getStatusCode') ? $e->getStatusCode() : 500,
                $userId,
                $e,
            );
            throw $e;
        }
    }

    private function record(Request $request, int $status, ?int $userId, ?Throwable $error = null): void
    {
        try {
            [$targetType, $targetId] = $this->target($request);
            AdminAuditLog::query()->create([
                'user_id' => $userId,
                'event' => (string) ($request->route()?->getName() ?: 'admin.request'),
                'route_name' => $request->route()?->getName(),
                'method' => $request->method(),
                'path' => '/'.ltrim($request->path(), '/'),
                'target_type' => $targetType,
                'target_id' => $targetId,
                'ip_address' => $request->ip(),
                'user_agent' => mb_substr((string) $request->userAgent(), 0, 500),
                'response_status' => $status,
                'metadata' => array_filter([
                    'result' => $status < 400 ? 'success' : 'error',
                    'error_type' => $error ? $error::class : null,
                ]),
            ]);
        } catch (Throwable $auditError) {
            report($auditError);
        }
    }

    /** @return array{0:?string,1:?string} */
    private function target(Request $request): array
    {
        foreach ($request->route()?->parameters() ?? [] as $value) {
            if ($value instanceof Model) {
                return [$value::class, (string) $value->getKey()];
            }
        }

        return [null, null];
    }
}
