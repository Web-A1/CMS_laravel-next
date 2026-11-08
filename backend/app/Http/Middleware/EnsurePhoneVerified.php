<?php

namespace App\Http\Middleware;

use App\Support\ImpersonationContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class EnsurePhoneVerified
{
    public function handle(Request $request, Closure $next): Response
    {
        if (ImpersonationContext::isActive($request)) {
            return $next($request);
        }

        $user = $request->user();

        if (! $user) {
            abort(401);
        }

        if ($user->requiresPhoneVerification()) {
            return response()->json([
                'error' => [
                    'code' => 'VERIFY_PHONE_REQUIRED',
                    'message' => 'Сначала подтвердите телефон.',
                    'request_id' => (string) Str::uuid(),
                ],
            ], 403);
        }

        return $next($request);
    }
}
