<?php

namespace App\Services\Payments;

class DolyamePaymentProviderAdapter extends AbstractHmacPaymentProviderAdapter
{
    public function mapProviderStatus(array $event): array
    {
        $status = (string) $event['provider_status'];

        return match ($status) {
            'completed' => [
                'target_payment_status' => 'paid',
                'target_attempt_status' => 'completed',
                'is_terminal' => true,
                'diagnostic_code' => 'OK_COMPLETED',
            ],
            'canceled', 'cancelled', 'rejected' => [
                'target_payment_status' => 'cancelled',
                'target_attempt_status' => 'cancelled',
                'is_terminal' => true,
                'diagnostic_code' => 'OK_CANCELLED',
            ],
            'created' => [
                'target_payment_status' => null,
                'target_attempt_status' => 'pending',
                'is_terminal' => false,
                'diagnostic_code' => 'NO_TRANSITION_PENDING',
            ],
            default => parent::mapProviderStatus($event),
        };
    }
}
