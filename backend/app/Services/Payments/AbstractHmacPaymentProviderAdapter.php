<?php

namespace App\Services\Payments;

use App\Contracts\Payments\PaymentProviderAdapter;
use Illuminate\Support\Str;

abstract class AbstractHmacPaymentProviderAdapter implements PaymentProviderAdapter
{
    public function __construct(
        protected readonly string $provider,
        protected readonly string $secret
    ) {}

    public function provider(): string
    {
        return $this->provider;
    }

    public function verifyWebhookSignature(string $rawPayload, ?string $signature): bool
    {
        if ($this->secret === '' || ! is_string($signature) || $signature === '') {
            return false;
        }

        $expected = hash_hmac('sha256', $rawPayload, $this->secret);

        return hash_equals($expected, $signature);
    }

    public function startPayment(array $payload): array
    {
        $paymentId = $this->provider.'-'.(string) Str::uuid();

        return [
            'provider_payment_id' => $paymentId,
            'provider_payment_url' => rtrim((string) $payload['return_url'], '/').'?payment_id='.$paymentId,
            'provider_substatus' => ['status' => 'pending'],
            'expires_at' => now()->addDay()->toIso8601String(),
        ];
    }

    public function parseWebhookEvent(array $payload): array
    {
        return [
            'provider_event_id' => (string) ($payload['event_id'] ?? $payload['provider_event_id'] ?? ''),
            'event_type' => (string) ($payload['event_type'] ?? 'unknown'),
            'provider_status' => strtolower((string) ($payload['status'] ?? 'unknown')),
            'raw_payload' => $payload,
        ];
    }

    public function mapProviderStatus(array $event): array
    {
        $status = (string) $event['provider_status'];

        return match ($status) {
            'completed', 'paid', 'success', 'succeeded' => [
                'target_payment_status' => 'paid',
                'target_attempt_status' => 'completed',
                'is_terminal' => true,
                'diagnostic_code' => 'OK_COMPLETED',
            ],
            'cancelled', 'canceled', 'failed', 'rejected' => [
                'target_payment_status' => 'cancelled',
                'target_attempt_status' => 'cancelled',
                'is_terminal' => true,
                'diagnostic_code' => 'OK_CANCELLED',
            ],
            'pending', 'created' => [
                'target_payment_status' => null,
                'target_attempt_status' => 'pending',
                'is_terminal' => false,
                'diagnostic_code' => 'NO_TRANSITION_PENDING',
            ],
            default => [
                'target_payment_status' => null,
                'target_attempt_status' => 'pending',
                'is_terminal' => false,
                'diagnostic_code' => 'NO_TRANSITION_UNKNOWN',
            ],
        };
    }

    public function refund(array $payload): array
    {
        return [
            'provider_refund_id' => $this->provider.'-refund-'.(string) Str::uuid(),
            'status' => 'completed',
            'provider_substatus' => [
                'refund_type' => (string) ($payload['refund_type'] ?? 'partial'),
            ],
        ];
    }
}
