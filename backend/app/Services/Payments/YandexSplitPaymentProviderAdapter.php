<?php

namespace App\Services\Payments;

use DomainException;

class YandexSplitPaymentProviderAdapter extends AbstractHmacPaymentProviderAdapter
{
    public function validateStartPayload(array $payload): void
    {
        $client = app(YandexSplitClient::class);
        if (! $client->configured()) {
            throw new DomainException('PAYMENT_PROVIDER_NOT_CONFIGURED');
        }
        $items = $payload['items'] ?? [];
        if (count($items) !== 1 || ($payload['currency'] ?? null) !== 'RUB' || (int) ($payload['amount'] ?? 0) < 1) {
            throw new DomainException('PAYMENT_YANDEX_SNAPSHOT_INVALID');
        }
        $item = $items[0];
        if (! is_array($item) || trim((string) ($item['display_title'] ?? '')) === ''
            || (int) ($item['quantity'] ?? 0) !== 1 || (int) ($item['final_amount'] ?? -1) !== (int) $payload['amount']) {
            throw new DomainException('PAYMENT_YANDEX_SNAPSHOT_INVALID');
        }
        foreach (['return_url', 'cancel_url'] as $field) {
            $url = parse_url((string) ($payload[$field] ?? ''));
            if (! is_array($url) || ($url['scheme'] ?? '') !== 'https' || isset($url['user']) || isset($url['pass'])
                || ! in_array($url['host'] ?? '', config('yandex_split.return_url_hosts', []), true)) {
                throw new DomainException('PAYMENT_YANDEX_RETURN_URL_INVALID');
            }
        }
    }

    public function startPayment(array $payload): array
    {
        $this->validateStartPayload($payload);
        $client = app(YandexSplitClient::class);
        $item = $payload['items'][0];
        $id = YandexSplitClient::orderId((int) $payload['order_id'], (int) $payload['attempt_id']);
        $result = $client->createOrder([
            'orderId' => $id,
            'currencyCode' => 'RUB',
            'availablePaymentMethods' => ['SPLIT'],
            'preferredPaymentMethod' => 'SPLIT',
            'cart' => [
                'items' => [[
                    'productId' => (string) ($item['sellable_sku'] ?? $item['id'] ?? ''),
                    'title' => (string) $item['display_title'],
                    'quantity' => ['count' => '1'],
                    'total' => YandexSplitClient::decimal((int) $payload['amount']),
                ]],
                'total' => ['amount' => YandexSplitClient::decimal((int) $payload['amount'])],
            ],
            'redirectUrls' => ['onSuccess' => $payload['return_url'], 'onError' => $payload['cancel_url'], 'onAbort' => $payload['cancel_url']],
        ]);
        $url = $client->paymentUrl($result['paymentUrl'] ?? null);

        return [
            'provider_payment_id' => $id, 'provider_payment_url' => $url,
            'provider_substatus' => ['provider_status' => 'PENDING', 'sandbox' => (bool) config('yandex_split.sandbox', true),
                'merchant_id' => (string) config('yandex_split.merchant_id')],
            'expires_at' => now()->addMinutes(30)->toIso8601String(),
        ];
    }

    public function refund(array $payload): array
    {
        if (($payload['refund_type'] ?? null) !== 'full') {
            throw new DomainException('PAYMENT_REFUND_WHOLE_SERVICE_REQUIRED');
        }
        $result = app(YandexSplitClient::class)->refund(
            (string) $payload['provider_payment_id'], (int) $payload['amount'], (string) $payload['request_key'],
        );
        $operation = $result['operation'] ?? [];
        if (! is_string($operation['operationId'] ?? null)) {
            throw new DomainException('PAYMENT_YANDEX_OUTCOME_UNKNOWN');
        }

        return ['provider_refund_id' => $operation['operationId'], 'status' => 'submitted', 'provider_substatus' => $operation];
    }

    public function verifyWebhookSignature(string $rawPayload, ?string $signature): bool
    {
        try {
            app(YandexSplitClient::class)->verifyToken($rawPayload);

            return true;
        } catch (DomainException) {
            return false;
        }
    }

    public function parseWebhookEvent(array $payload): array
    {
        throw new DomainException('PAYMENT_YANDEX_TYPED_WEBHOOK_REQUIRED');
    }

    public function mapProviderStatus(array $event): array
    {
        return match ($event['provider_status'] ?? null) {
            'CAPTURED' => ['target_payment_status' => 'paid', 'target_attempt_status' => 'completed', 'is_terminal' => true, 'diagnostic_code' => 'OK_CAPTURED'],
            'FAILED' => ['target_payment_status' => 'cancelled', 'target_attempt_status' => 'cancelled', 'is_terminal' => true, 'diagnostic_code' => 'OK_FAILED'],
            default => ['target_payment_status' => null, 'target_attempt_status' => 'pending', 'is_terminal' => false, 'diagnostic_code' => 'NO_TRANSITION'],
        };
    }
}
