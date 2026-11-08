<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class DemoReadOnly
{
    public function handle(Request $request, Closure $next): Response
    {
        if (config('demo.enabled') && ! in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true)) {
            return response()->json(['message' => 'Демонстрация доступна только для просмотра.'], 403);
        }

        return $next($request);
    }
}

