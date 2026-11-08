<?php

namespace App\Services\Bonuses;

use App\Models\CoinReservation;
use App\Models\Order;
use App\Models\OrderSettlement;
use App\Models\PaymentAttempt;
use App\Services\OrderItemSnapshotService;
use DomainException;
use Illuminate\Support\Facades\DB;

class CoinOrderPricingService
{
    public function __construct(private readonly BonusReservationContext $context, private readonly CoinLedgerService $coins) {}

    public function apply(int $userId, int $orderId, int $units, string $key, ?string $expectedQuoteHash = null): Order
    {
        $this->assertEnabled();

        return DB::transaction(function () use ($userId, $orderId, $units, $key, $expectedQuoteHash): Order {
            [$order] = $this->context->lock($userId, $orderId);
            $items = $order->items()->lockForUpdate()->get();
            if ($items->count() !== 1 || (int) $items->sole()->quantity !== 1) {
                throw new DomainException('BONUS_SINGLE_PURCHASE_REQUIRED');
            }
            $item = $items->sole();
            if (isset($item->benefit_snapshot['coin_reservation'])) {
                $bound = $this->binding($order);
                $reservation = CoinReservation::query()->findOrFail($bound['reservation_id']);
                if ($reservation->idempotency_key !== $key || $reservation->units !== $units
                    || ($expectedQuoteHash !== null && ($bound['proof']['quote_hash'] ?? null) !== $expectedQuoteHash)) {
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
            $reservation = $this->coins->reservePriced($userId, $orderId, $units, $key, $expectedQuoteHash);
            if ($reservation->status !== 'reserved' || $reservation->expires_at->lessThanOrEqualTo(now())) {
                throw new DomainException('BONUS_RESERVATION_EXPIRED');
            }
            app(OrderDiscountSnapshotService::class)->apply($order, $item, 'coins', (int) $reservation->id, $reservation->proof);
            $this->binding($order);

            return $order->fresh();
        }, 3);
    }

    public function finalizeZeroCash(int $userId, int $orderId, string $key): OrderSettlement
    {
        return app(OrderDiscountService::class)->finalizeZeroCash($userId, $orderId, $key);
    }

    /** Caller holds the order lock. A missing/tampered binding fails closed even when checkout is disabled. */
    public function binding(Order $order): ?array
    {
        $items = $order->items()->get();
        $markers = $items->filter(fn ($item) => isset($item->benefit_snapshot['coin_reservation']));
        if ($markers->isEmpty()) {
            if (CoinReservation::query()->where('order_id', $order->id)->get()->contains(fn ($r) => ($r->proof['monetary_pricing_verified'] ?? false) === true)) {
                throw new DomainException('BONUS_BINDING_MISSING');
            }

            return null;
        }
        if ($items->count() !== 1 || (int) $items->sole()->quantity !== 1) {
            throw new DomainException('BONUS_SINGLE_PURCHASE_REQUIRED');
        }
        $item = $items->sole();
        $marker = $item->benefit_snapshot['coin_reservation'];
        if (! is_array($marker) || ! is_int($marker['reservation_id'] ?? null) || ! is_string($marker['proof_hash'] ?? null)) {
            throw new DomainException('BONUS_BINDING_INVALID');
        }
        $reservation = CoinReservation::query()->find($marker['reservation_id']);
        $proof = $reservation?->proof ?? [];
        $storedHash = $proof['proof_hash'] ?? null;
        unset($proof['proof_hash']);
        if ($reservation === null || (int) $reservation->order_id !== (int) $order->id
            || (int) $reservation->user_id !== (int) $order->user_id || ($proof['operation'] ?? null) !== 'priced-v1'
            || ($proof['monetary_pricing_verified'] ?? null) !== true || ($proof['coin_minor_units'] ?? null) !== 100
            || ! is_string($storedHash) || ! hash_equals($storedHash, hash('sha256', json_encode($proof, JSON_THROW_ON_ERROR)))
            || ! hash_equals($storedHash, $marker['proof_hash'])) {
            throw new DomainException('BONUS_BINDING_INVALID');
        }
        $this->context->assertPurchase($order, $proof);
        app(OrderDiscountSnapshotService::class)->assertMatches($order, $item, 'coins', (int) ($proof['coins_writeoff'] ?? -1), (int) ($proof['payable_total'] ?? -1));
        if (($proof['coins_units'] ?? null) !== $reservation->units || $reservation->units < 1
            || $reservation->units > intdiv((int) $order->amount_total, 100)
            || ($proof['coins_writeoff'] ?? null) !== $reservation->units * 100
            || ($proof['payable_total'] ?? null) !== (int) $order->amount_total - $reservation->units * 100
            || ($proof['expires_at'] ?? null) !== $reservation->expires_at->toIso8601String()
            || (int) $order->amount_discount !== $proof['coins_writeoff'] || (int) $order->amount_final !== $proof['payable_total']
            || (int) $item->discount_amount !== $proof['coins_writeoff'] || (int) $item->final_amount !== $proof['payable_total']
            || ($item->price_snapshot['discount_amount'] ?? null) !== $proof['coins_writeoff']
            || ($item->price_snapshot['final_amount'] ?? null) !== $proof['payable_total']
            || ($item->benefit_snapshot['discount_amount'] ?? null) !== $proof['coins_writeoff']
            || ! hash_equals((string) $item->snapshot_hash, OrderItemSnapshotService::snapshotHash($item->getAttributesForSnapshot()))) {
            throw new DomainException('BONUS_BOUND_PRICE_MISMATCH');
        }

        return ['reservation_id' => (int) $reservation->id, 'status' => $reservation->status,
            'expires_at' => $reservation->expires_at, 'proof' => $reservation->proof];
    }

    public function assertCanStartPayment(Order $order): void
    {
        $this->context->lock((int) $order->user_id, (int) $order->id);
        if ((int) $order->amount_final <= 0) {
            throw new DomainException('PAYMENT_POSITIVE_CASH_REQUIRED');
        }
        $binding = $this->binding($order);
        if ($binding !== null && ($binding['status'] !== 'reserved' || $binding['expires_at']->lessThanOrEqualTo(now()))) {
            throw new DomainException('BONUS_RESERVATION_EXPIRED');
        }
    }

    public function hasPricingRecord(Order $order): bool
    {
        return $order->items()->get()->contains(fn ($item) => isset($item->benefit_snapshot['coin_reservation']))
            || CoinReservation::query()->where('order_id', $order->id)->get()->contains(fn ($row) => ($row->proof['monetary_pricing_verified'] ?? false) === true);
    }

    /** Failure is returned, not thrown: authenticated cash must survive missing coin funds. */
    public function commitForSettlement(Order $order): ?array
    {
        try {
            $binding = $this->binding($order);
            if ($binding === null) {
                return null;
            }
            $reservation = $this->coins->commit((int) $order->user_id, $binding['reservation_id']);

            return ['state' => 'committed', 'reservation_id' => (int) $reservation->id,
                'units' => $reservation->units, 'proof_hash' => $reservation->proof['proof_hash']];
        } catch (DomainException $exception) {
            return ['state' => 'blocked', 'reason' => $exception->getMessage()];
        }
    }

    public function releaseForOrder(Order $order, string $reason): void
    {
        $binding = $this->binding($order);
        if ($binding !== null && $binding['status'] === 'reserved') {
            $this->coins->release((int) $order->user_id, $binding['reservation_id'], $reason);
        }
    }

    private function assertEnabled(): void
    {
        if (! config('bonuses.checkout_enabled', false)) {
            throw new DomainException('BONUS_CHECKOUT_DISABLED');
        }
    }
}
