<?php

namespace App\Services\Payments;

use DomainException;
use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

class YandexSplitClient
{
    public function configured(): bool
    {
        return config('yandex_split.enabled', false) === true
            && trim((string) config('yandex_split.merchant_id', '')) !== ''
            && (config('yandex_split.sandbox', true) === true || trim((string) config('yandex_split.api_key', '')) !== '');
    }

    public function baseUrl(): string
    {
        return config('yandex_split.sandbox', true) ? 'https://sandbox.pay.yandex.ru' : 'https://pay.yandex.ru';
    }

    public static function orderId(int $orderId, int $attemptId): string
    {
        return "cms-{$orderId}-{$attemptId}";
    }

    public static function decimal(int $minor): string
    {
        if ($minor < 0) {
            throw new DomainException('PAYMENT_YANDEX_AMOUNT_INVALID');
        }

        return intdiv($minor, 100).'.'.str_pad((string) ($minor % 100), 2, '0', STR_PAD_LEFT);
    }

    public static function minor(mixed $amount): int
    {
        if (! is_string($amount) || ! preg_match('/^(0|[1-9][0-9]{0,10})(?:\\.([0-9]{1,2}))?$/D', $amount, $match)) {
            throw new DomainException('PAYMENT_YANDEX_AMOUNT_INVALID');
        }

        return (int) $match[1] * 100 + (int) str_pad($match[2] ?? '', 2, '0');
    }

    public function createOrder(array $payload): array
    {
        return $this->request('post', '/api/merchant/v1/orders', $payload, (string) $payload['orderId']);
    }

    public function getOrder(string $orderId): array
    {
        return $this->request('get', '/api/merchant/v1/orders/'.rawurlencode($orderId));
    }

    public function refund(string $orderId, int $amount, string $key): array
    {
        return $this->request('post', '/api/merchant/v2/orders/'.rawurlencode($orderId).'/refund', [
            'refundAmount' => self::decimal($amount),
            'externalOperationId' => $key,
        ], $key);
    }

    public function getOperation(string $key): array
    {
        return $this->request('get', '/api/merchant/v1/operations/'.rawurlencode($key));
    }

    private function request(string $method, string $path, array $payload = [], ?string $key = null): array
    {
        if (! $this->configured()) {
            throw new DomainException('PAYMENT_PROVIDER_NOT_CONFIGURED');
        }
        try {
            $credential = config('yandex_split.sandbox', true) ? config('yandex_split.merchant_id') : config('yandex_split.api_key');
            $request = Http::acceptJson()->asJson()->withToken((string) $credential, 'Api-Key')
                ->connectTimeout(5)->timeout(12)->withoutRedirecting();
            if ($key !== null) {
                $request = $request->withHeaders(['X-Request-Id' => $key]);
            }
            $response = $method === 'get' ? $request->get($this->baseUrl().$path) : $request->post($this->baseUrl().$path, $payload);
        } catch (ConnectionException) {
            throw new DomainException('PAYMENT_YANDEX_OUTCOME_UNKNOWN');
        }
        $body = $response->json();
        if (! $response->successful() || ! is_array($body) || ($body['status'] ?? null) !== 'success' || ! is_array($body['data'] ?? null)) {
            if (is_array($body) && ($body['reasonCode'] ?? $body['data']['reasonCode'] ?? null) === 'DUPLICATE_EXTERNAL_OPERATION_ID') {
                throw new DomainException('PAYMENT_YANDEX_DUPLICATE_OPERATION');
            }
            // A provider error does not prove that a mutating operation never happened.
            throw new DomainException('PAYMENT_YANDEX_OUTCOME_UNKNOWN');
        }

        return $body['data'];
    }

    public function verifyToken(string $token): array
    {
        if (! $this->configured() || strlen($token) > 65536) {
            throw new DomainException('PAYMENT_YANDEX_UNAUTHORIZED');
        }
        $parts = explode('.', $token);
        if (count($parts) !== 3 || ! preg_match('/^[A-Za-z0-9_-]+$/D', $parts[0])) {
            throw new DomainException('PAYMENT_YANDEX_UNAUTHORIZED');
        }
        try {
            $header = json_decode(JWT::urlsafeB64Decode($parts[0]), true, 16, JSON_THROW_ON_ERROR);
            $now = time();
            if (! is_array($header) || ($header['alg'] ?? null) !== 'ES256' || ($header['typ'] ?? null) !== 'JWT'
                || ! is_string($header['kid'] ?? null) || strlen($header['kid']) > 128
                || (! is_int($header['iat'] ?? null) && ! is_string($header['iat'] ?? null))
                || (! is_int($header['exp'] ?? null) && ! is_string($header['exp'] ?? null))
                || ! preg_match('/^[0-9]{1,12}$/D', (string) ($header['iat'] ?? ''))
                || ! preg_match('/^[0-9]{1,12}$/D', (string) ($header['exp'] ?? ''))
                || (int) $header['iat'] > $now + 30 || (int) $header['exp'] <= $now
                || (int) $header['exp'] <= (int) $header['iat']
                || isset($header['jku']) || isset($header['x5u']) || isset($header['crit'])) {
                throw new DomainException('PAYMENT_YANDEX_UNAUTHORIZED');
            }
            $cacheKey = 'payments:yandex:jwks:'.hash('sha256', $this->baseUrl());
            $jwks = Cache::get($cacheKey);
            if (! is_array($jwks) || ! $this->hasKey($jwks, $header['kid'])) {
                $response = Http::acceptJson()->connectTimeout(5)->timeout(8)->withoutRedirecting()->get($this->baseUrl().'/api/jwks');
                $jwks = $response->successful() ? $response->json() : null;
                if (! is_array($jwks) || ! $this->hasKey($jwks, $header['kid'])) {
                    throw new DomainException('PAYMENT_YANDEX_UNAUTHORIZED');
                }
                Cache::put($cacheKey, $jwks, 600);
            }
            $keys = array_values(array_filter($jwks['keys'], static fn ($key): bool => is_array($key)
                && ($key['kid'] ?? null) === $header['kid'] && ($key['kty'] ?? null) === 'EC'
                && ($key['crv'] ?? null) === 'P-256' && ($key['alg'] ?? 'ES256') === 'ES256'
                && ($key['use'] ?? 'sig') === 'sig'));
            if (count($keys) !== 1) {
                throw new DomainException('PAYMENT_YANDEX_UNAUTHORIZED');
            }
            $claims = (array) JWT::decode($token, JWK::parseKeySet(['keys' => $keys], 'ES256'));
            if (($claims['merchantId'] ?? null) !== (string) config('yandex_split.merchant_id')) {
                throw new DomainException('PAYMENT_YANDEX_UNAUTHORIZED');
            }

            return json_decode(json_encode($claims, JSON_THROW_ON_ERROR), true, 32, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            throw new DomainException('PAYMENT_YANDEX_UNAUTHORIZED');
        }
    }

    private function hasKey(array $jwks, string $kid): bool
    {
        if (! is_array($jwks['keys'] ?? null) || count($jwks['keys']) > 50) {
            return false;
        }
        foreach ($jwks['keys'] as $key) {
            if (is_array($key) && ($key['kid'] ?? null) === $kid) {
                return true;
            }
        }

        return false;
    }

    public function assertOrder(array $data, string $orderId, int $amount, string $currency): array
    {
        $order = $data['order'] ?? null;
        if (! is_array($order) || ($order['orderId'] ?? null) !== $orderId
            || ($order['merchantId'] ?? null) !== config('yandex_split.merchant_id')
            || ($order['currencyCode'] ?? null) !== $currency
            || self::minor($order['orderAmount'] ?? null) !== $amount) {
            throw new DomainException('PAYMENT_YANDEX_ORDER_MISMATCH');
        }

        return $order;
    }

    public function paymentUrl(mixed $value): string
    {
        $parts = is_string($value) ? parse_url($value) : false;
        if (! is_array($parts) || ($parts['scheme'] ?? '') !== 'https'
            || ($parts['host'] ?? '') !== parse_url($this->baseUrl(), PHP_URL_HOST)
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])) {
            throw new DomainException('PAYMENT_YANDEX_PAYMENT_URL_INVALID');
        }

        return $value;
    }
}
