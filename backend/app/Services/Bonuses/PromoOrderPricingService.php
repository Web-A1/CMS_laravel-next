<?php

namespace App\Services\Bonuses;

use App\Models\Order;
use App\Models\OrderSettlement;
use App\Models\PaymentAttempt;
use App\Models\PromoReservation;
use DomainException;
use Illuminate\Support\Facades\DB;

class PromoOrderPricingService
{
    public function __construct(private readonly BonusReservationContext $context, private readonly PromoReservationService $promos,
        private readonly OrderDiscountSnapshotService $snapshots) {}

    public function apply(int $userId, int $orderId, string $code, string $key, ?string $expectedQuoteHash = null): Order
    {
        if (! config('bonuses.checkout_enabled', false)) {
            throw new DomainException('BONUS_CHECKOUT_DISABLED');
        }

        return DB::transaction(function () use ($userId, $orderId, $code, $key, $expectedQuoteHash): Order {
            [$order] = $this->context->lock($userId, $orderId);
            $items = $order->items()->lockForUpdate()->get();
            if ($items->count() !== 1 || (int) $items->sole()->quantity !== 1) {
                throw new DomainException('BONUS_SINGLE_PURCHASE_REQUIRED');
            }
            $item = $items->sole();
            if (isset($item->benefit_snapshot['promo_reservation'])) {
                $binding = app(OrderDiscountService::class)->binding($order);
                $existing = PromoReservation::query()->findOrFail($binding['reservation_id']);
                if ($existing->idempotency_key !== $key) {
                    throw new DomainException('BONUS_IDEMPOTENCY_CONFLICT');
                }
                $reservation = $this->promos->reservePriced($userId, $orderId, $code, $key, $expectedQuoteHash);
                if ($binding['kind'] !== 'promo' || $binding['reservation_id'] !== (int) $reservation->id) {
                    throw new DomainException('BONUS_IDEMPOTENCY_CONFLICT');
                }

                return $order->fresh();
            }
            $this->context->assertReservable($order);
            if (app(OrderDiscountService::class)->hasPricingRecord($order)
                || PaymentAttempt::query()->where('order_id', $orderId)->exists()
                || OrderSettlement::query()->where('order_id', $orderId)->exists()
                || (int) $order->amount_discount !== 0 || (int) $item->discount_amount !== 0) {
                throw new DomainException('BONUS_ORDER_ALREADY_IN_PAYMENT');
            }
            $reservation = $this->promos->reservePriced($userId, $orderId, $code, $key, $expectedQuoteHash);
            $this->promos->assertReserved($order, (int) $reservation->id);
            $this->snapshots->apply($order, $item, 'promo', (int) $reservation->id, $reservation->proof);
            app(OrderDiscountService::class)->binding($order);

            return $order->fresh();
        }, 3);
    }

    /** Structural proof remains valid after consumption, expiry or later source-promo revocation. */
    public function binding(Order $order): ?array
    {
        $items = $order->items()->get();
        $markers = $items->filter(fn ($item) => isset($item->benefit_snapshot['promo_reservation']));
        if ($markers->isEmpty()) {
            if ($this->hasPricingRecord($order)) {
                throw new DomainException('BONUS_BINDING_MISSING');
            }

            return null;
        }
        if ($items->count() !== 1 || (int) $items->sole()->quantity !== 1) {
            throw new DomainException('BONUS_SINGLE_PURCHASE_REQUIRED');
        }
        $item = $items->sole();
        $marker = $item->benefit_snapshot['promo_reservation'];
        if (! is_array($marker) || ! is_int($marker['reservation_id'] ?? null) || ! is_string($marker['proof_hash'] ?? null)) {
            throw new DomainException('BONUS_BINDING_INVALID');
        }
        $reservation = PromoReservation::query()->find($marker['reservation_id']);
        $proof = $reservation?->proof ?? [];
        $hash = $proof['proof_hash'] ?? null;
        unset($proof['proof_hash']);
        if ($reservation === null || (int) $reservation->user_id !== (int) $order->user_id
            || (int) $reservation->order_id !== (int) $order->id
            || ($proof['promo_code_id'] ?? null) !== (int) $reservation->promo_code_id
            || ($proof['operation'] ?? null) !== 'promo-priced-v1' || ($proof['monetary_pricing_verified'] ?? null) !== true
            || ($proof['discount_type'] ?? null) !== 'promo' || ($proof['coins_writeoff'] ?? null) !== 0
            || ! is_string($hash) || ! hash_equals($hash, hash('sha256', json_encode($proof, JSON_THROW_ON_ERROR)))
            || ! hash_equals($hash, $marker['proof_hash'])) {
            throw new DomainException('BONUS_BINDING_INVALID');
        }
        $this->context->assertPurchase($order, $proof);
        if (! is_int($proof['promo_discount'] ?? null) || $proof['promo_discount'] < 0
            || $proof['promo_discount'] > (int) $order->amount_total
            || $proof['promo_discount'] !== $reservation->discount_minor
            || ($proof['payable_total'] ?? null) !== (int) $order->amount_total - $proof['promo_discount']
            || ($proof['expires_at'] ?? null) !== $reservation->expires_at->toIso8601String()) {
            throw new DomainException('BONUS_BOUND_PRICE_MISMATCH');
        }
        $this->snapshots->assertMatches($order, $item, 'promo', $proof['promo_discount'], $proof['payable_total']);

        return ['reservation_id' => (int) $reservation->id, 'status' => $reservation->status,
            'expires_at' => $reservation->expires_at, 'proof' => $reservation->proof];
    }

    public function hasPricingRecord(Order $order): bool
    {
        return $order->items()->get()->contains(fn ($item) => isset($item->benefit_snapshot['promo_reservation']))
            || PromoReservation::query()->where('order_id', $order->id)->get()->contains(fn ($row) => ($row->proof['monetary_pricing_verified'] ?? false) === true);
    }
}
