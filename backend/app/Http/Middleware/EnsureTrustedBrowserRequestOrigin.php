<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureTrustedBrowserRequestOrigin
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethodSafe()) {
            return $next($request);
        }

        $requestOrigin = $this->requestOrigin($request);
        $allowedOrigins = collect(config('trusted_browser.allowed_origins', []))
            ->filter(fn (mixed $origin): bool => is_string($origin))
            ->map(fn (string $origin): ?string => $this->normalizeOrigin($origin))
            ->filter()
            ->values()
            ->all();

        if ($requestOrigin === null || ! in_array($requestOrigin, $allowedOrigins, true)) {
            return response()->json([
                'error' => [
                    'code' => 'TRUSTED_BROWSER_ORIGIN_FORBIDDEN',
                    'message' => 'Forbidden',
                ],
            ], 403);
        }

        return $next($request);
    }

    private function requestOrigin(Request $request): ?string
    {
        $origin = $request->headers->get('Origin');
        if (is_string($origin) && trim($origin) !== '') {
            return $this->normalizeOrigin($origin);
        }

        $referer = $request->headers->get('Referer');

        return is_string($referer) && trim($referer) !== ''
            ? $this->normalizeOrigin($referer)
            : null;
    }

    private function normalizeOrigin(string $value): ?string
    {
        $parts = parse_url(trim($value));
        if (! is_array($parts) || isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));
        if (! in_array($scheme, ['http', 'https'], true) || $host === '') {
            return null;
        }

        if (str_contains($host, ':') && ! str_starts_with($host, '[')) {
            $host = '['.$host.']';
        }

        $port = isset($parts['port']) ? (int) $parts['port'] : null;
        $defaultPort = ($scheme === 'https' && $port === 443) || ($scheme === 'http' && $port === 80);

        return $scheme.'://'.$host.($port !== null && ! $defaultPort ? ':'.$port : '');
    }
}
