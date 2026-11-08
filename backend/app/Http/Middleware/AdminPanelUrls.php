<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

final class AdminPanelUrls
{
    public function handle(Request $request, Closure $next): Response
    {
        // Route middleware runs after Kernel rebinds the actual request. Only
        // URL generation sees the public origin; auth and signatures do not.
        $generator = URL::getFacadeRoot();
        $original = $generator->getRequest();
        $origin = rtrim((string) config('app.frontend_url'), '/');
        $parts = parse_url($origin);
        abort_unless(is_array($parts)
            && in_array($parts['scheme'] ?? null, ['http', 'https'], true)
            && isset($parts['host'])
            && ! isset($parts['user']) && ! isset($parts['pass']), 500);
        $generator->setRequest(Request::create($origin.$request->getRequestUri()));

        try {
            return $next($request);
        } finally {
            $generator->setRequest($original);
        }
    }
}
