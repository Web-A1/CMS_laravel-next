<?php

namespace App\Services\Bonuses;

use App\Models\Order;
use App\Models\OrderItem;
use App\Services\OrderItemSnapshotService;
use DomainException;

class OrderDiscountSnapshotService
{
    public function apply(Order $order, OrderItem $item, string $kind, int $reservationId, array $proof): void
    {
        $discount = $proof[$kind === 'coins' ? 'coins_writeoff' : 'promo_discount'];
        $marker = $kind === 'coins' ? 'coin_reservation' : 'promo_reservation';
        $item->forceFill(['discount_amount' => $discount, 'final_amount' => $proof['payable_total'],
            'price_snapshot' => [...$item->price_snapshot, 'discount_amount' => $discount, 'final_amount' => $proof['payable_total']],
            'benefit_snapshot' => [...$item->benefit_snapshot, 'discount_amount' => $discount,
                'discount_type' => $kind, $marker => ['reservation_id' => $reservationId, 'proof_hash' => $proof['proof_hash']]]]);
        $item->snapshot_hash = OrderItemSnapshotService::snapshotHash($item->getAttributesForSnapshot());
        $item->save();
        $order->update(['amount_discount' => $discount, 'amount_final' => $proof['payable_total']]);
    }

    public function assertMatches(Order $order, OrderItem $item, string $kind, int $discount, int $payable): void
    {
        if ((int) $order->amount_discount !== $discount || (int) $order->amount_final !== $payable
            || (int) $item->discount_amount !== $discount || (int) $item->final_amount !== $payable
            || ($item->price_snapshot['discount_amount'] ?? null) !== $discount
            || ($item->price_snapshot['final_amount'] ?? null) !== $payable
            || ($item->benefit_snapshot['discount_amount'] ?? null) !== $discount
            || ($item->benefit_snapshot['discount_type'] ?? null) !== $kind
            || ! hash_equals((string) $item->snapshot_hash, OrderItemSnapshotService::snapshotHash($item->getAttributesForSnapshot()))) {
            throw new DomainException('BONUS_BOUND_PRICE_MISMATCH');
        }
    }
}
