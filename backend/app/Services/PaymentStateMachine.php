<?php

namespace App\Services;

use App\Models\Order;
use DomainException;

class PaymentStateMachine
{
    private const TRANSITIONS = [
        'draft' => ['pending_payment', 'cancelled'],
        'pending_payment' => ['paid', 'cancelled'],
        'paid' => ['refunded'],
        'cancelled' => [],
        'refunded' => [],
    ];

    public function transition(Order $order, string $to, ?string $accessStatus = null): Order
    {
        if ($to === 'paid' && $accessStatus === 'granted') {
            throw new DomainException('PAYMENT_ACCESS_GRANT_REQUIRES_SETTLEMENT');
        }

        if (! $this->canTransition($order->payment_status, $to)) {
            $from = $order->payment_status;
            throw new DomainException("Invalid payment transition: {$from} -> {$to}");
        }

        $order->payment_status = $to;

        if ($accessStatus !== null) {
            $order->access_status = $accessStatus;
        }

        if ($to === 'paid') {
            $order->paid_at = now();
        }

        if ($to === 'cancelled') {
            $order->cancelled_at = now();
        }

        if ($to === 'refunded') {
            $order->refunded_at = now();
        }

        $order->save();

        return $order->refresh();
    }

    public function canTransition(string $from, string $to): bool
    {
        $allowed = self::TRANSITIONS[$from] ?? [];

        return in_array($to, $allowed, true);
    }
}
