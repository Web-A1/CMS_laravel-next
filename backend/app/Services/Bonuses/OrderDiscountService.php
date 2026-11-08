<?php

namespace App\Services\Bonuses;

use App\Models\Order;
use App\Models\OrderSettlement;
use App\Models\PaymentAttempt;
use App\Services\OrderSettlementService;
use App\Services\Payments\PaymentOutboxService;
use DomainException;
use Illuminate\Support\Facades\DB;

class OrderDiscountService
{
    public function __construct(private readonly BonusReservationContext $context,
        private readonly CoinOrderPricingService $coins, private readonly PromoOrderPricingService $promos,
        private readonly PromoReservationService $reservations) {}

    public function binding(Order $order): ?array
    {
        if ($this->coins->hasPricingRecord($order) && $this->promos->hasPricingRecord($order)) {
            throw new DomainException('BONUS_DISCOUNTS_MUTUALLY_EXCLUSIVE');
        }
        $coins = $this->coins->binding($order);
        $promo = $this->promos->binding($order);

        return $coins !== null ? ['kind' => 'coins', ...$coins] : ($promo !== null ? ['kind' => 'promo', ...$promo] : null);
    }

    public function hasPricingRecord(Order $order): bool
    {
        return $this->coins->hasPricingRecord($order) || $this->promos->hasPricingRecord($order);
    }

    public function assertCanStartPayment(Order $order): void
    {
        $this->context->lock((int) $order->user_id, (int) $order->id);
        if ((int) $order->amount_final <= 0) {
            throw new DomainException('PAYMENT_POSITIVE_CASH_REQUIRED');
        }
        $binding = $this->binding($order);
        if ($binding === null) {
            return;
        }
        if ($binding['status'] !== 'reserved' || $binding['expires_at']->lessThanOrEqualTo(now())) {
            throw new DomainException('BONUS_RESERVATION_EXPIRED');
        }
        if ($binding['kind'] === 'promo') {
            $this->reservations->assertReserved($order, $binding['reservation_id']);
        }
    }

    /** Verified provider cash survives discount failure; settlement permanently blocks access. */
    public function commitForSettlement(Order $order): ?array
    {
        try {
            $binding = $this->binding($order);
            if ($binding === null) {
                return null;
            }
            if ($binding['kind'] === 'coins') {
                return ['kind' => 'coins', ...$this->coins->commitForSettlement($order)];
            }
            $reservation = $this->reservations->commit((int) $order->user_id, $binding['reservation_id']);

            return ['kind' => 'promo', 'state' => 'committed', 'reservation_id' => (int) $reservation->id,
                'proof_hash' => $reservation->proof['proof_hash']];
        } catch (DomainException $exception) {
            $kind = $this->coins->hasPricingRecord($order) && ! $this->promos->hasPricingRecord($order) ? 'coins' : 'promo';

            return ['kind' => $kind, 'state' => 'blocked', 'reason' => $exception->getMessage()];
        }
    }

    public function releaseForOrder(Order $order, string $reason): void
    {
        $binding = $this->binding($order);
        if ($binding === null || $binding['status'] !== 'reserved') {
            return;
        }
        if ($binding['kind'] === 'coins') {
            $this->coins->releaseForOrder($order, $reason);
        } else {
            $this->reservations->release((int) $order->user_id, $binding['reservation_id'], $reason);
        }
    }

    public function settlementEvidenceMatches(OrderSettlement $settlement, array $binding): bool
    {
        $evidence = $settlement->metadata['discount_settlement'] ?? null;
        if ($evidence === null && $binding['kind'] === 'coins') {
            $evidence = ['kind' => 'coins', ...($settlement->metadata['coin_settlement'] ?? [])];
        }

        return ($evidence['kind'] ?? null) === $binding['kind'] && ($evidence['state'] ?? null) === 'committed'
            && ($evidence['reservation_id'] ?? null) === $binding['reservation_id']
            && ($evidence['proof_hash'] ?? null) === $binding['proof']['proof_hash'];
    }

    public function finalizeZeroCash(int $userId, int $orderId, string $key): OrderSettlement
    {
        if (! config('bonuses.checkout_enabled', false)) {
            throw new DomainException('BONUS_CHECKOUT_DISABLED');
        }
        $this->context->assertKey($key);

        return DB::transaction(function () use ($userId, $orderId, $key): OrderSettlement {
            [$order] = $this->context->lock($userId, $orderId);
            $binding = $this->binding($order);
            if ($binding === null || (int) $order->amount_final !== 0
                || PaymentAttempt::query()->where('order_id', $orderId)->exists()) {
                throw new DomainException('BONUS_ZERO_CASH_PROOF_REQUIRED');
            }
            $alreadySettled = OrderSettlement::query()->where('order_id', $orderId)->where('status', 'confirmed')->exists();
            if ($order->payment_status === 'draft') {
                $order->update(['payment_status' => 'pending_payment']);
            }
            $settlement = app(OrderSettlementService::class)->confirmPaid($order, [
                'idempotency_key' => $binding['kind'].':zero:'.$orderId.':'.$key, 'amount' => 0, 'currency' => $order->currency,
                'settlement_type' => OrderSettlement::TYPE_SETTLED_ZERO_CASH]);
            if (! $alreadySettled) {
                app(PaymentOutboxService::class)->publishTransition($order->fresh(), null, null, 'pending_payment', 'paid',
                    ['source' => $binding['kind'].'_zero_cash']);
            }

            return $settlement;
        }, 3);
    }
}
