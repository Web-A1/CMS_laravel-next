<?php

namespace App\Services\Payments;

use DomainException;

class PodeliPaymentProviderAdapter extends AbstractHmacPaymentProviderAdapter
{
    public function validateStartPayload(array $payload): void
    {
        $client = app(PodeliClient::class);
        if (! $client->configured()) {
            throw new DomainException('PAYMENT_PROVIDER_NOT_CONFIGURED');
        }
        $items = $payload['items'] ?? [];
        $customer = $payload['client_info'] ?? [];
        if (! is_array($items) || count($items) !== 1 || ! is_array($items[0] ?? null)
            || ! is_string($items[0]['sellable_sku'] ?? null) || $items[0]['sellable_sku'] === ''
            || ($payload['currency'] ?? null) !== 'RUB' || (int) ($payload['amount'] ?? 0) < 1
            || ($items[0]['quantity'] ?? null) != 1 || (int) ($items[0]['final_amount'] ?? -1) !== (int) $payload['amount']
            || trim((string) ($items[0]['display_title'] ?? '')) === '' || mb_strlen((string) $items[0]['display_title']) > 250) {
            throw new DomainException('PAYMENT_PODELI_SNAPSHOT_INVALID');
        }
        foreach (['first_name', 'last_name'] as $field) {
            if (! is_string($customer[$field] ?? null) || trim($customer[$field]) === '' || mb_strlen($customer[$field]) > 60) {
                throw new DomainException('PAYMENT_PODELI_CUSTOMER_REQUIRED');
            }
        }
        if (! is_string($customer['phone'] ?? null) || ! preg_match('/^7[0-9]{10}$/D', $customer['phone'])
            || ! is_string($customer['email'] ?? null) || strlen($customer['email']) > 250 || ! filter_var($customer['email'], FILTER_VALIDATE_EMAIL)) {
            throw new DomainException('PAYMENT_PODELI_CUSTOMER_REQUIRED');
        }
        foreach ([$payload['return_url'] ?? '', $payload['cancel_url'] ?? '', config('podeli.notification_url', '')] as $value) {
            $url = is_string($value) ? parse_url($value) : false;
            if (! is_array($url) || ($url['scheme'] ?? '') !== 'https'
                || ! in_array($url['host'] ?? '', config('podeli.return_url_hosts', []), true)
                || isset($url['user']) || isset($url['pass']) || isset($url['port'])) {
                throw new DomainException('PAYMENT_PODELI_RETURN_URL_INVALID');
            }
        }
    }

    public function startPayment(array $payload): array
    {
        $this->validateStartPayload($payload);
        $client = app(PodeliClient::class);
        $items = $payload['items'];
        $customer = $payload['client_info'];
        $id = PodeliClient::orderId((int) $payload['order_id'], (int) $payload['attempt_id']);
        $itemId = PodeliClient::itemId((string) ($items[0]['sellable_sku'] ?? ''));
        $result = $client->createOrder([
            'order' => [
                'id' => $id, 'amount' => PodeliClient::decimal((int) $payload['amount']), 'prepaidAmount' => '0.00',
                'isTwoStagePayment' => config('podeli.capture_mode') === 'two_stage',
                'items' => [['id' => $itemId, 'name' => $items[0]['display_title'],
                    'amount' => PodeliClient::decimal((int) $payload['amount']), 'quantity' => '1', 'prepaidAmount' => '0.00']],
            ],
            'clientInfo' => ['firstName' => $customer['first_name'], 'lastName' => $customer['last_name'],
                'phone' => $customer['phone'], 'email' => $customer['email']],
            'notificationUrl' => config('podeli.notification_url'),
            'successUrl' => $payload['return_url'], 'failUrl' => $payload['cancel_url'], 'orderSource' => 'web',
        ]);
        if (PodeliClient::minor($result['amount'] ?? null) !== (int) $payload['amount']) {
            throw new DomainException('PAYMENT_PODELI_ORDER_MISMATCH');
        }

        return ['provider_payment_id' => $id, 'provider_payment_url' => $client->paymentUrl($result['redirectUrl'] ?? null),
            'provider_substatus' => ['provider_status' => (string) ($result['status'] ?? 'CREATED'),
                'sandbox' => (bool) config('podeli.sandbox', true), 'capture_mode' => config('podeli.capture_mode'),
                'account_fingerprint' => $client->accountFingerprint(), 'item_id' => $itemId],
            'expires_at' => now()->addHours(6)->toIso8601String()];
    }

    public function refund(array $payload): array
    {
        throw new DomainException('PAYMENT_PODELI_DURABLE_REFUND_REQUIRED');
    }

    public function verifyWebhookSignature(string $rawPayload, ?string $signature): bool
    {
        // Podeli documents source filtering plus authenticated INFO, not HMAC.
        return false;
    }

    public function parseWebhookEvent(array $payload): array
    {
        throw new DomainException('PAYMENT_PODELI_TYPED_WEBHOOK_REQUIRED');
    }

    public function mapProviderStatus(array $event): array
    {
        return match ($event['provider_status'] ?? null) {
            'COMPLETED' => ['target_payment_status' => 'paid', 'target_attempt_status' => 'completed', 'is_terminal' => true, 'diagnostic_code' => 'OK_COMPLETED'],
            'REJECTED', 'CANCELLED' => ['target_payment_status' => 'cancelled', 'target_attempt_status' => 'cancelled', 'is_terminal' => true, 'diagnostic_code' => 'OK_FAILED'],
            default => ['target_payment_status' => null, 'target_attempt_status' => 'pending', 'is_terminal' => false, 'diagnostic_code' => 'NO_TRANSITION'],
        };
    }
}
