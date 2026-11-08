<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

final class SupportSubmissionGuard
{
    public function handle(Request $request, Closure $next)
    {
        $limits = [
            'support:ip:'.hash('sha256', (string) $request->ip()) => config('support.ip_per_hour'),
            'support:email:'.hash('sha256', mb_strtolower(trim((string) $request->input('email')))) => config('support.email_per_hour'),
        ];
        foreach ($limits as $key => $limit) {
            if (RateLimiter::tooManyAttempts($key, $limit)) {
                $retry = RateLimiter::availableIn($key);

                return response()->json(['error' => ['code' => 'SUPPORT_RATE_LIMIT', 'message' => 'Слишком много обращений. Повторите позже.'], 'retry_after' => $retry], 429, ['Retry-After' => $retry, 'Cache-Control' => 'private, no-store']);
            }
        }
        foreach ($limits as $key => $limit) {
            RateLimiter::hit($key, 3600);
        }

        return $next($request);
    }
}
