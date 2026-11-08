<?php

namespace App\Services\Payments;

use DomainException;
use Illuminate\Support\Facades\Http;
use Throwable;

class CloudpaymentsPaymentProviderAdapter extends AbstractHmacPaymentProviderAdapter
{
    /** @var array<string, mixed> */
    private readonly array $config;

    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(string $provider, string $secret, array $config = [])
    {
        parent::__construct($provider, $secret);
        $this->config = $config;
    }

    public function startPayment(array $payload): array
    {
        $publicId = trim((string) ($this->config['public_id'] ?? ''));
        if ($publicId === '') {
            throw new DomainException('PAYMENT_CLOUDPAYMENTS_PUBLIC_ID_MISSING');
        }

        $amount = (int) ($payload['amount'] ?? 0);
        $currency = strtoupper(trim((string) ($payload['currency'] ?? 'RUB')));
        $orderId = (int) ($payload['order_id'] ?? 0);
        $attemptId = (int) ($payload['attempt_id'] ?? 0);

        if ($amount < 1 || $orderId < 1 || $attemptId < 1 || $currency === '') {
            throw new DomainException('PAYMENT_CLOUDPAYMENTS_SESSION_INVALID');
        }

        return [
            'provider_payment_id' => null,
            'provider_payment_url' => null,
            'provider_substatus' => [
                'mode' => 'widget',
                'public_id' => $publicId,
                'invoice_id' => "cp-{$orderId}-{$attemptId}",
                'attempt_id' => (string) $attemptId,
                'amount' => number_format($amount / 100, 2, '.', ''),
                'currency' => $currency,
                'description' => 'Оплата заказа #'.$orderId,
            ],
            'expires_at' => now()->addHour()->toIso8601String(),
        ];
    }

    public function verifyWebhookSignature(string $rawPayload, ?string $signature): bool
    {
        if ($this->secret === '' || ! is_string($signature) || $signature === '') {
            return false;
        }

        $expected = base64_encode(hash_hmac('sha256', $rawPayload, $this->secret, true));

        return hash_equals($expected, trim($signature));
    }

    public function parseWebhookEvent(array $payload): array
    {
        throw new DomainException('PAYMENT_CLOUDPAYMENTS_TYPED_WEBHOOK_REQUIRED');
    }

    public function refund(array $payload): array
    {
        $publicId = trim((string) ($this->config['public_id'] ?? ''));
        $transaction = $payload['provider_payment_id'] ?? null;
        $key = $payload['request_key'] ?? null;
        $amount = $payload['amount'] ?? null;
        if ($publicId === '' || $this->secret === '') {
            throw new DomainException('PAYMENT_CLOUDPAYMENTS_CREDENTIALS_MISSING');
        }
        if (! is_string($transaction) || ! preg_match('/^[1-9][0-9]{0,18}$/D', $transaction)
            || ! is_string($key) || ! preg_match('/^[a-zA-Z0-9-]{16,64}$/D', $key)
            || ! is_int($amount) || $amount < 1) {
            throw new DomainException('PAYMENT_REFUND_REQUEST_INVALID');
        }

        try {
            // Never retry a money request here: CP retains X-Request-ID only for one hour.
            $response = Http::withBasicAuth($publicId, $this->secret)
                ->acceptJson()->asJson()->connectTimeout(5)->timeout(20)
                ->withOptions(['allow_redirects' => false])
                ->withHeaders(['X-Request-ID' => $key])
                ->post('https://api.cloudpayments.ru/payments/refund', [
                    'TransactionId' => $transaction,
                    'Amount' => number_format($amount / 100, 2, '.', ''),
                    'JsonData' => ['cms_refund_request_key' => $key],
                ]);
            $body = $response->json();
        } catch (Throwable) {
            // Do not propagate provider response/request text or authentication details.
            throw new DomainException('PAYMENT_REFUND_OUTCOME_UNKNOWN');
        }
        if (! $response->successful() || ! is_array($body)) {
            throw new DomainException('PAYMENT_REFUND_OUTCOME_UNKNOWN');
        }
        if (($body['Success'] ?? null) === false) {
            throw new DomainException('PAYMENT_REFUND_PROVIDER_REJECTED');
        }
        $refundId = $body['Model']['TransactionId'] ?? null;
        if (($body['Success'] ?? null) !== true || (! is_string($refundId) && ! is_int($refundId))
            || ! preg_match('/^[1-9][0-9]{0,18}$/D', (string) $refundId)) {
            throw new DomainException('PAYMENT_REFUND_OUTCOME_UNKNOWN');
        }

        return [
            'provider_refund_id' => (string) $refundId,
            'status' => 'submitted',
            'provider_substatus' => [],
        ];
    }

    /** @return array<string, mixed> */
    public function transaction(string $transactionId): array
    {
        $publicId = trim((string) ($this->config['public_id'] ?? ''));
        if ($publicId === '' || $this->secret === '' || ! preg_match('/^[1-9][0-9]{0,18}$/D', $transactionId)) {
            throw new DomainException('PAYMENT_RECONCILIATION_CONFIG_INVALID');
        }
        try {
            $response = Http::withBasicAuth($publicId, $this->secret)
                ->acceptJson()->asJson()->connectTimeout(5)->timeout(20)
                ->withOptions(['allow_redirects' => false])
                ->post('https://api.cloudpayments.ru/payments/get', ['TransactionId' => $transactionId]);
            $body = $response->json();
        } catch (Throwable) {
            throw new DomainException('PAYMENT_RECONCILIATION_UNAVAILABLE');
        }
        $model = is_array($body) ? ($body['Model'] ?? null) : null;
        if (! $response->successful() || ($body['Success'] ?? null) !== true || ! is_array($model)
            || (string) ($model['TransactionId'] ?? '') !== $transactionId
            || ($model['PublicId'] ?? null) !== $publicId
            || ($model['TestMode'] ?? null) !== (bool) ($this->config['test_mode'] ?? false)) {
            throw new DomainException('PAYMENT_RECONCILIATION_IDENTITY_INVALID');
        }

        // Only a minimal financial envelope leaves the transport: no PAN, token,
        // contact details, or full provider response may reach logs/artifacts.
        return array_intersect_key($model, array_flip([
            'TransactionId', 'OriginalTransactionId', 'InvoiceId', 'Amount', 'Currency',
            'Status', 'TestMode', 'ReasonCode', 'Type',
        ]));
    }
}
