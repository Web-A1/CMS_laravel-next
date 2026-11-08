<?php

namespace App\Http\Middleware;

use App\Support\ImpersonationContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureImpersonationReadOnly
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! ImpersonationContext::isActive($request) || in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true)) {
            return $next($request);
        }

        if ($this->allowedRequest($request)) {
            return $next($request);
        }

        return response()->json([
            'error' => [
                'code' => 'IMPERSONATION_READ_ONLY',
                'message' => 'В режиме просмотра пользователя доступны только чтение и возврат в админку.',
            ],
        ], Response::HTTP_FORBIDDEN)->header('Cache-Control', 'private, no-store');
    }

    private function allowedRequest(Request $request): bool
    {
        return $request->is(
            'api/v1/access/check',
            'api/v1/impersonation/stop',
            'api/v1/auth/logout',
            'api/v1/auth/idle-logout',
            'api/v1/admin/users/*/impersonation',
        );
    }
}
