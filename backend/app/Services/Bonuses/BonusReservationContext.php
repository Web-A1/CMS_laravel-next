<?php

namespace App\Services\Bonuses;

use App\Models\CoinReservation;
use App\Models\Order;
use App\Models\PromoReservation;
use App\Models\User;
use DomainException;

/** Internal lock/proof contract. Call only inside the caller's transaction. */
class BonusReservationContext
{
    public function lock(int $userId, int $orderId): array
    {
        $order = Order::query()->lockForUpdate()->findOrFail($orderId);
        if ((int) $order->user_id !== $userId) {
            throw new DomainException('BONUS_ORDER_OWNER_MISMATCH');
        }
        $user = User::query()->lockForUpdate()->findOrFail($userId);

        return [$order, $user];
    }

    public function purchase(Order $order): array
    {
        $items = $order->items()->orderBy('id')->get();
        if ($items->isEmpty() || $order->currency !== 'RUB') {
            throw new DomainException('BONUS_ORDER_INVALID');
        }
        $subtotal = 0;
        $snapshot = [];
        foreach ($items as $item) {
            if ($item->currency !== $order->currency || (int) $item->quantity < 1 || (int) $item->subtotal_amount < 0
                || (int) $item->subtotal_amount > PHP_INT_MAX - $subtotal) {
                throw new DomainException('BONUS_ORDER_INVALID');
            }
            $subtotal += (int) $item->subtotal_amount;
            $snapshot[] = $item->only(['id', 'sellable_sku', 'quantity', 'subtotal_amount', 'currency', 'grant_snapshot']);
        }
        if ($subtotal < 1 || $subtotal !== (int) $order->amount_total) {
            throw new DomainException('BONUS_ORDER_INVALID');
        }

        return ['order_id' => (int) $order->id, 'user_id' => (int) $order->user_id,
            'subtotal' => $subtotal, 'currency' => $order->currency,
            'purchase_hash' => hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR))];
    }

    public function assertReservable(Order $order): void
    {
        if (! in_array($order->payment_status, ['draft', 'pending_payment'], true) || $order->active_attempt_id !== null) {
            throw new DomainException('BONUS_ORDER_ALREADY_IN_PAYMENT');
        }
    }

    public function assertNoDiscountReservation(int $orderId): void
    {
        foreach ([CoinReservation::class, PromoReservation::class] as $model) {
            if ($model::query()->where('order_id', $orderId)->where(function ($query): void {
                $query->where('status', 'committed')->orWhere(fn ($q) => $q->where('status', 'reserved')->where('expires_at', '>', now()));
            })->exists()) {
                throw new DomainException('BONUS_DISCOUNT_ALREADY_RESERVED');
            }
        }
    }

    public function assertKey(string $key): void
    {
        if (! preg_match('/^[A-Za-z0-9:_-]{1,128}$/D', $key)) {
            throw new DomainException('BONUS_IDEMPOTENCY_KEY_INVALID');
        }
    }

    public function assertPurchase(Order $order, array $proof): void
    {
        $current = $this->purchase($order);
        foreach ($current as $key => $value) {
            if (($proof[$key] ?? null) !== $value) {
                throw new DomainException('BONUS_PURCHASE_CHANGED');
            }
        }
    }
}
