<?php

namespace App\Services\Payments;

use DomainException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use JsonException;

class PodeliClient
{
    public function configured(): bool
    {
        return config('podeli.enabled', false) === true
            && in_array(config('podeli.capture_mode'), ['single_stage', 'two_stage'], true)
            && trim((string) config('podeli.login', '')) !== ''
            && trim((string) config('podeli.password', '')) !== ''
            && is_file((string) config('podeli.certificate_path', ''))
            && is_readable((string) config('podeli.certificate_path', ''))
            && is_file((string) config('podeli.private_key_path', ''))
            && is_readable((string) config('podeli.private_key_path', ''))
            && $this->baseUrl() !== '';
    }

    public function baseUrl(): string
    {
        if (config('podeli.sandbox', true)) {
            return 'https://api-sand.podeli.ru/partners/v1';
        }
        $base = rtrim((string) config('podeli.production_base_url', ''), '/');
        $url = parse_url($base);

        return is_array($url) && ($url['scheme'] ?? '') === 'https' && ! empty($url['host'])
            && ($url['path'] ?? '') === '/partners/v1' && ! isset($url['user']) && ! isset($url['pass'])
            && ! isset($url['query']) && ! isset($url['fragment']) && ! isset($url['port']) ? $base : '';
    }

    public static function accountFingerprint(): string
    {
        return hash('sha256', app(self::class)->baseUrl().'|'.(string) config('podeli.login', ''));
    }

    public static function orderId(int $order, int $attempt): string
    {
        return "cms-{$order}-{$attempt}";
    }

    public static function itemId(string $sku): string
    {
        if ($sku === '') {
            throw new DomainException('PAYMENT_PODELI_SNAPSHOT_INVALID');
        }

        return 'service-'.substr(hash('sha256', $sku), 0, 24);
    }

    public static function decimal(int $minor): string
    {
        if ($minor < 0) {
            throw new DomainException('PAYMENT_PODELI_AMOUNT_INVALID');
        }

        return intdiv($minor, 100).'.'.str_pad((string) ($minor % 100), 2, '0', STR_PAD_LEFT);
    }

    public static function minor(mixed $value): int
    {
        if (! is_string($value) || ! preg_match('/^(0|[1-9][0-9]{0,10})(?:\.([0-9]{1,2}))?$/D', $value, $m)) {
            throw new DomainException('PAYMENT_PODELI_AMOUNT_INVALID');
        }

        return (int) $m[1] * 100 + (int) str_pad($m[2] ?? '', 2, '0');
    }

    /** Preserve JSON number lexemes before any monetary value can become a float. */
    public static function decode(string $json): array
    {
        if (strlen($json) > 1048576) {
            throw new DomainException('PAYMENT_PODELI_RESPONSE_INVALID');
        }
        try {
            $quoted = preg_replace_callback(
                '/"(?:[^"\\\\]|\\\\.)*"|-?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?(?:[eE][+-]?[0-9]+)?/',
                static fn (array $m): string => $m[0][0] === '"' ? $m[0] : '"'.$m[0].'"',
                $json,
            );
            $result = json_decode($quoted ?? '', true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new DomainException('PAYMENT_PODELI_RESPONSE_INVALID');
        }
        if (! is_array($result)) {
            throw new DomainException('PAYMENT_PODELI_RESPONSE_INVALID');
        }

        return $result;
    }

    /** Encode validated decimal fields as JSON numbers without float conversion. */
    public static function encode(array $payload): string
    {
        $encode = static function (mixed $value, ?string $key = null) use (&$encode): string {
            if (in_array($key, ['amount', 'prepaidAmount', 'quantity', 'refundedQuantity'], true)) {
                if (! is_string($value) || ! preg_match('/^(0|[1-9][0-9]*)(?:\.[0-9]{1,2})?$/D', $value)) {
                    throw new DomainException('PAYMENT_PODELI_AMOUNT_INVALID');
                }

                return $value;
            }
            if (! is_array($value)) {
                return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
            if (array_is_list($value)) {
                return '['.implode(',', array_map(static fn ($v): string => $encode($v), $value)).']';
            }
            $fields = [];
            foreach ($value as $field => $v) {
                $fields[] = json_encode((string) $field, JSON_THROW_ON_ERROR).':'.$encode($v, (string) $field);
            }

            return '{'.implode(',', $fields).'}';
        };

        return $encode($payload);
    }

    public function createOrder(array $payload): array
    {
        return $this->request('post', '/orders/create', $payload);
    }

    public function getOrder(string $orderId): array
    {
        return $this->request('get', '/orders/'.rawurlencode($orderId).'/info');
    }

    public function commit(string $orderId, int $amount): array
    {
        return $this->request('post', '/orders/'.rawurlencode($orderId).'/commit', [
            'order' => ['amount' => self::decimal($amount), 'prepaidAmount' => '0.00'],
        ]);
    }

    public function refund(string $orderId, string $key, array $items): array
    {
        return $this->request('post', '/orders/'.rawurlencode($orderId).'/refund', [
            'order' => ['refund' => ['id' => $key, 'initiator' => 'shop', 'items' => $items]],
        ]);
    }

    private function request(string $method, string $path, array $payload = []): array
    {
        if (DB::transactionLevel() !== 0) {
            throw new DomainException('PAYMENT_REMOTE_TRANSACTION_BOUNDARY_INVALID');
        }
        if (! $this->configured()) {
            throw new DomainException('PAYMENT_PROVIDER_NOT_CONFIGURED');
        }
        try {
            $request = Http::acceptJson()->withBasicAuth((string) config('podeli.login'), (string) config('podeli.password'))
                ->withHeaders(['X-Correlation-ID' => (string) Str::uuid()])
                ->withOptions(['cert' => config('podeli.certificate_path'), 'ssl_key' => config('podeli.private_key_path'), 'verify' => true])
                ->withoutRedirecting()->connectTimeout(5)->timeout(15);
            $response = $method === 'get' ? $request->get($this->baseUrl().$path)
                : $request->withBody(self::encode($payload), 'application/json')->post($this->baseUrl().$path);
        } catch (ConnectionException) {
            throw new DomainException('PAYMENT_PODELI_OUTCOME_UNKNOWN');
        }
        if (! $response->successful()) {
            throw new DomainException('PAYMENT_PODELI_OUTCOME_UNKNOWN');
        }
        $body = self::decode($response->body());
        if (isset($body['error'])) {
            throw new DomainException('PAYMENT_PODELI_OUTCOME_UNKNOWN');
        }

        return $body;
    }

    public function paymentUrl(mixed $value): string
    {
        $url = is_string($value) ? parse_url($value) : false;
        $hosts = config('podeli.sandbox', true) ? ['pokupka-sand.podeli.ru'] : config('podeli.production_redirect_hosts', []);
        if (! is_array($url) || ($url['scheme'] ?? '') !== 'https' || ! in_array($url['host'] ?? '', $hosts, true)
            || isset($url['user']) || isset($url['pass']) || isset($url['port'])) {
            throw new DomainException('PAYMENT_PODELI_PAYMENT_URL_INVALID');
        }

        return $value;
    }

    public function assertOrder(array $data, string $id, int $amount, string $itemId): array
    {
        $order = $data['order'] ?? null;
        if (! is_array($order) || ($order['id'] ?? null) !== $id || self::minor($order['amount'] ?? null) !== $amount
            || self::minor($order['prepaidAmount'] ?? null) !== 0
            || ! is_array($order['items'] ?? null) || count($order['items']) !== 1) {
            throw new DomainException('PAYMENT_PODELI_ORDER_MISMATCH');
        }
        $item = $order['items'][0];
        if (($item['id'] ?? null) !== $itemId || ! in_array($item['quantity'] ?? null, ['1', '1.0', '1.00', '1.000'], true)
            || self::minor($item['amount'] ?? null) !== $amount
            || (isset($item['prepaidAmount']) && self::minor($item['prepaidAmount']) !== 0)) {
            throw new DomainException('PAYMENT_PODELI_ORDER_MISMATCH');
        }

        return $order;
    }
}
