<?php

namespace App\Services\Payments;

use DomainException;

class RobokassaPaymentProviderAdapter extends AbstractHmacPaymentProviderAdapter
{
    public function startPayment(array $payload): array
    {
        $this->assertConfigured();
        if (($payload['currency'] ?? null) !== 'RUB' || ! is_int($payload['amount'] ?? null) || $payload['amount'] < 1
            || (int) ($payload['attempt_id'] ?? 0) < 1 || (int) ($payload['order_id'] ?? 0) < 1) {
            throw new DomainException('ROBOKASSA_PAYMENT_INVALID');
        }
        $amount = intdiv($payload['amount'], 100).'.'.str_pad((string) ($payload['amount'] % 100), 2, '0', STR_PAD_LEFT);
        $fields = ['MerchantLogin' => config('robokassa.merchant_login'), 'OutSum' => $amount,
            'InvId' => (string) $payload['attempt_id'], 'Description' => 'Заказ '.$payload['order_id'],
            'IsTest' => config('robokassa.test_mode') ? '1' : '0',
            'Shp_order' => (string) $payload['order_id'], 'Shp_test' => config('robokassa.test_mode') ? '1' : '0'];
        $fields['SignatureValue'] = $this->digest($fields['MerchantLogin'].':'.$amount.':'.$fields['InvId'].':'.config('robokassa.password1').$this->customSuffix($fields));

        return ['provider_payment_id' => $fields['InvId'],
            'provider_payment_url' => 'https://auth.robokassa.ru/Merchant/Index.aspx?'.http_build_query($fields, '', '&', PHP_QUERY_RFC3986),
            'provider_substatus' => ['test_mode' => (bool) config('robokassa.test_mode'), 'merchant_login' => config('robokassa.merchant_login'), 'custom' => ['Shp_order' => $fields['Shp_order'], 'Shp_test' => $fields['Shp_test']]],
            'expires_at' => null];
    }

    public function assertConfigured(): void
    {
        if (! config('robokassa.enabled') || ! config('robokassa.merchant_login') || ! config('robokassa.password1')
            || ! config('robokassa.password2') || ! in_array(config('robokassa.hash'), ['md5', 'sha256', 'sha384', 'sha512'], true)) {
            throw new DomainException('ROBOKASSA_NOT_CONFIGURED');
        }
    }

    public function verifyWebhookSignature(string $rawPayload, ?string $signature): bool
    {
        try {
            $this->assertConfigured();
            $fields = $this->decode($rawPayload);
            self::minor($fields['OutSum'] ?? null);
            if (! preg_match('/^[1-9][0-9]{0,17}$/D', $fields['InvId'] ?? '')) {
                return false;
            }
            $signature ??= $fields['SignatureValue'] ?? '';
            $expected = $this->digest($fields['OutSum'].':'.$fields['InvId'].':'.config('robokassa.password2').$this->customSuffix($fields));

            return hash_equals($expected, strtolower($signature));
        } catch (DomainException) {
            return false;
        }
    }

    public function decode(string $raw): array
    {
        $fields = [];
        foreach (explode('&', $raw) as $part) {
            [$key, $value] = array_pad(explode('=', $part, 2), 2, '');
            $key = urldecode($key);
            if ($key === '' || ! preg_match('/^[A-Za-z][A-Za-z0-9_]*$/D', $key) || array_key_exists($key, $fields)) {
                throw new DomainException('ROBOKASSA_BODY_INVALID');
            }
            $fields[$key] = urldecode($value);
        }

        return $fields;
    }

    public function parseWebhookEvent(array $payload): array
    {
        return ['provider_event_id' => 'result:'.($payload['InvId'] ?? ''), 'event_type' => 'robokassa.result', 'provider_status' => 'paid',
            'order_id' => (int) ($payload['Shp_order'] ?? 0), 'attempt_id' => (int) ($payload['InvId'] ?? 0),
            'amount' => self::minor($payload['OutSum'] ?? null), 'currency' => 'RUB',
            'provider_payment_id' => (string) ($payload['InvId'] ?? ''), 'raw_payload' => $payload];
    }

    public function refund(array $payload): array
    {
        $result = app(RobokassaClient::class)->createRefund((string) ($payload['op_key'] ?? ''));

        return ['provider_refund_id' => $result, 'status' => 'submitted', 'provider_substatus' => []];
    }

    public static function minor(mixed $amount): int
    {
        if (! is_string($amount) && ! is_int($amount) && ! is_float($amount)) {
            throw new DomainException('ROBOKASSA_AMOUNT_INVALID');
        }
        if (! preg_match('/^(0|[1-9][0-9]{0,12})(?:\.([0-9]{1,6}))?$/D', (string) $amount, $matches)
            || trim(substr($matches[2] ?? '', 2), '0') !== '') {
            throw new DomainException('ROBOKASSA_AMOUNT_INVALID');
        }

        return (int) $matches[1] * 100 + (int) substr(str_pad($matches[2] ?? '', 2, '0'), 0, 2);
    }

    private function digest(string $base): string
    {
        return hash(config('robokassa.hash'), $base);
    }

    private function customSuffix(array $fields): string
    {
        $custom = array_filter($fields, fn ($key) => str_starts_with($key, 'Shp_'), ARRAY_FILTER_USE_KEY);
        ksort($custom, SORT_STRING);
        $suffix = '';
        foreach ($custom as $key => $value) {
            $suffix .= ':'.$key.'='.$value;
        }

        return $suffix;
    }
}
