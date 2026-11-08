<?php

namespace App\Services\TrustedBrowser;

use App\Models\TrustedBrowserAccount;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;

final class TrustedBrowserCookie
{
    private const VERSION = 'td_v1';

    /**
     * @return array{selector: string, secret: string, secret_hash: string, value: string}
     */
    public function createCredential(): array
    {
        $selector = $this->encode(random_bytes((int) config('trusted_browser.cookie.selector_bytes', 16)));
        $secret = $this->encode(random_bytes((int) config('trusted_browser.cookie.secret_bytes', 32)));

        return [
            'selector' => $selector,
            'secret' => $secret,
            'secret_hash' => hash('sha256', $secret),
            'value' => self::VERSION.'.'.$selector.'.'.$secret,
        ];
    }

    /**
     * @return array{selector: string, secret: string, value: string}|null
     */
    public function read(Request $request, TrustedBrowserAccount $account): ?array
    {
        $value = $request->cookie($this->name($request, $account));

        return is_string($value) ? $this->parse($value) : null;
    }

    /**
     * @return array{selector: string, secret: string, value: string}|null
     */
    public function parse(string $value): ?array
    {
        if (! preg_match('/\Atd_v1\.([A-Za-z0-9_-]{22})\.([A-Za-z0-9_-]{43})\z/D', $value, $matches)) {
            return null;
        }

        if ($this->decode($matches[1], 16) === null || $this->decode($matches[2], 32) === null) {
            return null;
        }

        return [
            'selector' => $matches[1],
            'secret' => $matches[2],
            'value' => $value,
        ];
    }

    public function name(Request $request, TrustedBrowserAccount $account): string
    {
        $prefix = $this->isLocalHttp($request)
            ? (string) config('trusted_browser.cookie.local_prefix', 'cms-tb-v1-')
            : (string) config('trusted_browser.cookie.production_prefix', '__Host-cms-tb-v1-');

        return $prefix.$account->cookie_key;
    }

    public function queue(Request $request, TrustedBrowserAccount $account, string $value): void
    {
        Cookie::queue(Cookie::make(
            $this->name($request, $account),
            $value,
            (int) config('trusted_browser.credential.rolling_expiry_days', 400) * 24 * 60,
            '/',
            null,
            ! $this->isLocalHttp($request),
            true,
            false,
            (string) config('trusted_browser.cookie.same_site', 'lax'),
        ));
    }

    public function forget(Request $request, TrustedBrowserAccount $account): void
    {
        Cookie::queue(Cookie::make(
            $this->name($request, $account),
            '',
            -2628000,
            '/',
            null,
            ! $this->isLocalHttp($request),
            true,
            false,
            (string) config('trusted_browser.cookie.same_site', 'lax'),
        ));
    }

    private function isLocalHttp(Request $request): bool
    {
        return ! $request->isSecure()
            && in_array((string) config('app.env'), ['local', 'testing'], true);
    }

    private function encode(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    private function decode(string $value, int $expectedBytes): ?string
    {
        $padding = (4 - (strlen($value) % 4)) % 4;
        $decoded = base64_decode(strtr($value, '-_', '+/').str_repeat('=', $padding), true);

        return is_string($decoded) && strlen($decoded) === $expectedBytes ? $decoded : null;
    }
}
