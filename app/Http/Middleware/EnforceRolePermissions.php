<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnforceRolePermissions
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $viewerSelfServiceRoutes = [
            'admin.logout',
            'admin.security.mfa.begin',
            'admin.security.mfa.enable',
            'admin.security.mfa.disable',
        ];

        if ($user?->role === User::ROLE_VIEWER
            && ! $request->isMethodSafe()
            && ! in_array($request->route()?->getName(), $viewerSelfServiceRoutes, true)) {
            abort(403, 'Пользователь с ролью «Наблюдатель» имеет доступ только для чтения.');
        }

        return $next($request);
    }
}
