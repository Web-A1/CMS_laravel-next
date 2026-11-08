<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class AdminPanelResponse
{
    public static function applies(Request $request): bool
    {
        return $request->is('admin', 'admin/*')
            || preg_match('~^livewire-[a-f0-9]{8}/update$~D', $request->path()) === 1;
    }

    public static function headers(Response $response): Response
    {
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }

    public function handle(Request $request, Closure $next): Response
    {
        if (! self::applies($request)) {
            return $next($request);
        }

        return self::headers($next($request));
    }
}
