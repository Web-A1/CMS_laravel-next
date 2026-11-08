<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\AdminImpersonationService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ResolveImpersonationContext
{
    public function __construct(private readonly AdminImpersonationService $impersonation) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->is(
            'api/v1/impersonation',
            'api/v1/impersonation/stop',
            'api/v1/admin/users/*/impersonation',
        )) {
            return $next($request);
        }

        if (! $request->user() instanceof User) {
            return $next($request);
        }

        $resolution = $this->impersonation->resolve($request);
        if ($resolution['status'] === 'expired' && $this->rejectExpiredContext($request)) {
            return response()->json([
                'error' => [
                    'code' => 'IMPERSONATION_CONTEXT_EXPIRED',
                    'message' => 'Контекст просмотра устарел. Вернитесь в админку и запустите просмотр заново.',
                ],
                'return_path' => $resolution['return_path'],
            ], Response::HTTP_GONE)->header('Cache-Control', 'private, no-store');
        }

        return $next($request);
    }

    private function rejectExpiredContext(Request $request): bool
    {
        return ! $request->is(
            'api/v1/auth/logout',
            'api/v1/auth/idle-logout',
        );
    }
}
