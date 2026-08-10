<?php

namespace App\Http\Middleware;

use App\Models\AdminAuditLog;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class RecordAdminAudit
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $routeName = $request->route()?->getName();
        if (! $request->user()
            || ! is_string($routeName)
            || ! str_starts_with($routeName, 'admin.')
            || ! in_array($request->method(), ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            return $response;
        }

        try {
            AdminAuditLog::query()->create([
                'user_id' => $request->user()->getAuthIdentifier(),
                'route_name' => $routeName,
                'method' => $request->method(),
                'path' => mb_substr('/'.ltrim($request->path(), '/'), 0, 500),
                'status_code' => $response->getStatusCode(),
                'ip_address' => $request->ip(),
                'user_agent' => mb_substr((string) $request->userAgent(), 0, 512) ?: null,
                'created_at' => now(),
            ]);
        } catch (Throwable $e) {
            // Audit logging must never turn a successful administrator action into
            // an application failure, but the logging failure remains observable.
            report($e);
        }

        return $response;
    }
}
