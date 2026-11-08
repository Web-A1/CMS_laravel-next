<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderSettlement;
use App\Models\PaymentAttempt;
use App\Models\UserEntitlementGrant;
use App\Services\Bonuses\OrderDiscountService;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use JsonException;
use Throwable;

class OrderSettlementService
{
    public function __construct(
        private readonly EntitlementGrantService $entitlementGrantService,
        private readonly AccessResolver $accessResolver
    ) {}

    /**
     * @param  array{
     *     idempotency_key:string,
     *     amount:int,
     *     currency:string,
     *     settlement_type?:string,
     *     payment_attempt_id?:int|null,
     *     provider_event_id?:int|null,
     *     metadata?:array<string, mixed>
     * }  $intent
     */
    public function confirmPaid(Order|int $order, array $intent): OrderSettlement
    {
        $orderId = $order instanceof Order ? (int) $order->id : $order;
        $normalized = $this->normalizeIntent($intent);

        $settlement = DB::transaction(function () use ($orderId, $normalized): OrderSettlement {
            /** @var Order $lockedOrder */
            $lockedOrder = Order::query()
                ->whereKey($orderId)
                ->lockForUpdate()
                ->firstOrFail();

            $items = OrderItem::query()
                ->where('order_id', $lockedOrder->id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $fingerprintHash = $this->fingerprintHash($lockedOrder, $items, $normalized);

            $existingByKey = OrderSettlement::query()
                ->where('idempotency_key', $normalized['idempotency_key'])
                ->lockForUpdate()
                ->first();

            if ($existingByKey) {
                $this->assertSameSettlement($existingByKey, $lockedOrder, $normalized, $fingerprintHash);

                return $existingByKey->fresh();
            }

            $existingForOrder = OrderSettlement::query()
                ->where('order_id', $lockedOrder->id)
                ->where('status', OrderSettlement::STATUS_CONFIRMED)
                ->lockForUpdate()
                ->first();

            if ($existingForOrder) {
                throw new DomainException('ORDER_ALREADY_SETTLED');
            }

            $this->assertAttemptMatchesOrder($lockedOrder, $normalized);
            $coins = app(OrderDiscountService::class)->commitForSettlement($lockedOrder);
            $blocked = ($coins['state'] ?? null) === 'blocked';
            if ($normalized['amount'] === 0 && ($normalized['settlement_type'] !== OrderSettlement::TYPE_SETTLED_ZERO_CASH
                || $normalized['payment_attempt_id'] !== null || $normalized['provider_event_id'] !== null
                || ($coins['state'] ?? null) !== 'committed')) {
                throw new DomainException('BONUS_ZERO_CASH_PROOF_REQUIRED');
            }
            if ($normalized['settlement_type'] === OrderSettlement::TYPE_SETTLED_ZERO_CASH && $normalized['amount'] !== 0) {
                throw new DomainException('BONUS_ZERO_CASH_PROOF_REQUIRED');
            }
            $this->assertOrderCanSettle($lockedOrder, $items, $normalized, $blocked);

            /** @var OrderSettlement $settlement */
            $settlement = OrderSettlement::query()->create([
                'order_id' => $lockedOrder->id,
                'payment_attempt_id' => $normalized['payment_attempt_id'],
                'provider_event_id' => $normalized['provider_event_id'],
                'settlement_type' => $normalized['settlement_type'],
                'idempotency_key' => $normalized['idempotency_key'],
                'amount' => $normalized['amount'],
                'currency' => $normalized['currency'],
                'status' => OrderSettlement::STATUS_CONFIRMED,
                'fingerprint_hash' => $fingerprintHash,
                'confirmed_at' => now(),
                'metadata' => [
                    ...$normalized['metadata'],
                    'discount_settlement' => $coins,
                    'coin_settlement' => ($coins['kind'] ?? null) === 'coins' ? $coins : null,
                    'grant_status' => $blocked ? 'blocked' : 'pending',
                    'grant_blocked_reason' => $blocked ? $coins['reason'] : null,
                ],
            ]);

            $lockedOrder->forceFill([
                'payment_status' => 'paid',
                'access_status' => $blocked ? 'grant_failed' : 'pending_grant',
                'settlement_type' => $normalized['settlement_type'],
                'amount_paid' => max((int) $lockedOrder->amount_paid, $normalized['amount']),
                'paid_at' => $lockedOrder->paid_at ?: now(),
                'settled_at' => now(),
            ])->save();

            foreach ($items as $item) {
                $item->forceFill(['access_status' => $blocked ? OrderItem::ACCESS_GRANT_FAILED : OrderItem::ACCESS_PENDING_GRANT])->save();
            }

            return $settlement->fresh();
        }, 3);

        return $this->grantConfirmedSettlementAccess($settlement);
    }

    private function grantConfirmedSettlementAccess(OrderSettlement $settlement): OrderSettlement
    {
        if (($settlement->fresh()->metadata['grant_status'] ?? null) === 'blocked') {
            return $settlement->fresh();
        }
        /** @var Order $order */
        $order = Order::query()->whereKey($settlement->order_id)->firstOrFail();

        if ((string) $order->payment_status !== 'paid' || in_array((string) $order->access_status, ['granted', 'revoked'], true)) {
            return $settlement->fresh();
        }

        try {
            DB::transaction(function () use ($order, $settlement): void {
                /** @var Order $lockedOrder */
                $lockedOrder = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
                if ((string) $lockedOrder->payment_status !== 'paid' || in_array((string) $lockedOrder->access_status, ['granted', 'revoked'], true)) {
                    return;
                }

                /** @var OrderSettlement $lockedSettlement */
                $lockedSettlement = OrderSettlement::query()
                    ->whereKey($settlement->id)
                    ->lockForUpdate()
                    ->firstOrFail();
                if (($lockedSettlement->metadata['grant_status'] ?? null) === 'blocked') {
                    return;
                }

                /** @var Collection<int, OrderItem> $items */
                $items = OrderItem::query()
                    ->where('order_id', $lockedOrder->id)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();

                $createdGrants = 0;
                foreach ($items as $item) {
                    $atoms = $this->grantAtomsForItem($item);

                    $result = $this->entitlementGrantService->grantAtoms(
                        (int) $lockedOrder->user_id,
                        $atoms,
                        UserEntitlementGrant::SOURCE_ORDER_ITEM,
                        "order_item:{$item->id}",
                        "order_item:{$item->id}:grant:v1",
                        UserEntitlementGrant::STATUS_ACTIVE,
                        (int) $lockedOrder->id,
                        (int) $item->id,
                        [
                            'settlement_id' => (int) $lockedSettlement->id,
                            'snapshot_hash' => (string) $item->snapshot_hash,
                            'sellable_kind' => (string) $item->sellable_kind,
                            'purchased_entity_type' => (string) $item->purchased_entity_type,
                        ]
                    );

                    $createdGrants += (int) $result['created_count'];
                    $item->forceFill(['access_status' => OrderItem::ACCESS_GRANTED])->save();
                }

                OrderItem::query()
                    ->whereIn('id', $items->pluck('id')->all())
                    ->update(['access_status' => OrderItem::ACCESS_GRANTED]);

                $lockedOrder->forceFill(['access_status' => 'granted'])->save();

                $metadata = $lockedSettlement->metadata ?? [];
                unset($metadata['grant_error']);

                $lockedSettlement->forceFill([
                    'metadata' => [
                        ...$metadata,
                        'grant_status' => 'granted',
                        'granted_items_count' => $items->count(),
                        'created_grants_count' => $createdGrants,
                    ],
                ])->save();
            }, 3);
        } catch (Throwable $e) {
            DB::transaction(function () use ($order, $settlement, $e): void {
                /** @var Order $lockedOrder */
                $lockedOrder = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
                /** @var OrderSettlement $lockedSettlement */
                $lockedSettlement = OrderSettlement::query()
                    ->whereKey($settlement->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                /** @var Collection<int, OrderItem> $items */
                $items = OrderItem::query()
                    ->where('order_id', $lockedOrder->id)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();

                if (
                    (string) $lockedOrder->payment_status !== 'paid'
                    || (string) $lockedOrder->access_status === 'revoked'
                    ||
                    (string) $lockedOrder->access_status === 'granted'
                    || data_get($lockedSettlement->metadata, 'grant_status') === 'granted'
                    || $items->contains(
                        static fn (OrderItem $item): bool => $item->access_status === OrderItem::ACCESS_GRANTED
                    )
                ) {
                    return;
                }

                OrderItem::query()
                    ->where('order_id', $lockedOrder->id)
                    ->update(['access_status' => OrderItem::ACCESS_GRANT_FAILED]);

                $lockedOrder->forceFill(['access_status' => 'grant_failed'])->save();

                $lockedSettlement->forceFill([
                    'metadata' => [
                        ...($lockedSettlement->metadata ?? []),
                        'grant_status' => 'failed',
                        'granted_items_count' => 0,
                        'created_grants_count' => 0,
                        'grant_error' => [
                            'class' => $e::class,
                            'message' => $e->getMessage(),
                        ],
                    ],
                ])->save();
            }, 3);
        }

        return $settlement->fresh();
    }

    /**
     * @param  array<string, mixed>  $intent
     * @return array{idempotency_key:string, amount:int, currency:string, settlement_type:string, payment_attempt_id:int|null, provider_event_id:int|null, metadata:array<string, mixed>}
     */
    private function normalizeIntent(array $intent): array
    {
        $idempotencyKey = trim((string) ($intent['idempotency_key'] ?? ''));
        if ($idempotencyKey === '') {
            throw new InvalidArgumentException('Settlement idempotency key is required.');
        }

        $amount = (int) ($intent['amount'] ?? -1);
        if ($amount < 0) {
            throw new InvalidArgumentException('Settlement amount must be zero or greater.');
        }

        $currency = strtoupper(trim((string) ($intent['currency'] ?? '')));
        if (strlen($currency) !== 3) {
            throw new InvalidArgumentException('Settlement currency must be a 3-letter code.');
        }

        $settlementType = (string) ($intent['settlement_type'] ?? OrderSettlement::TYPE_BACKEND_CONFIRMATION);
        if (! in_array($settlementType, [
            OrderSettlement::TYPE_BACKEND_CONFIRMATION,
            OrderSettlement::TYPE_PROVIDER_PAYMENT,
            OrderSettlement::TYPE_SETTLED_ZERO_CASH,
        ], true)) {
            throw new InvalidArgumentException("Unsupported settlement type [{$settlementType}].");
        }

        $metadata = $intent['metadata'] ?? [];
        if (! is_array($metadata)) {
            throw new InvalidArgumentException('Settlement metadata must be an array.');
        }

        return [
            'idempotency_key' => $idempotencyKey,
            'amount' => $amount,
            'currency' => $currency,
            'settlement_type' => $settlementType,
            'payment_attempt_id' => isset($intent['payment_attempt_id']) ? (int) $intent['payment_attempt_id'] : null,
            'provider_event_id' => isset($intent['provider_event_id']) ? (int) $intent['provider_event_id'] : null,
            'metadata' => $metadata,
        ];
    }

    /**
     * @param  Collection<int, OrderItem>  $items
     * @param  array{idempotency_key:string, amount:int, currency:string, settlement_type:string, payment_attempt_id:int|null, provider_event_id:int|null, metadata:array<string, mixed>}  $intent
     */
    private function assertOrderCanSettle(Order $order, Collection $items, array $intent, bool $coinBlocked = false): void
    {
        if ($order->user_id === null) {
            throw new DomainException('ORDER_SETTLEMENT_REQUIRES_USER');
        }

        $lateBoundCash = $coinBlocked && $order->payment_status === 'cancelled'
            && $intent['settlement_type'] === OrderSettlement::TYPE_PROVIDER_PAYMENT && $intent['amount'] > 0
            && $intent['payment_attempt_id'] !== null && (int) $order->active_attempt_id === $intent['payment_attempt_id']
            && $intent['provider_event_id'] !== null;
        if ((string) $order->payment_status !== 'pending_payment' && ! $lateBoundCash) {
            throw new DomainException('ORDER_SETTLEMENT_INVALID_STATUS');
        }

        $expectedAmount = (int) $order->amount_final;
        if ($expectedAmount !== $intent['amount']) {
            throw new DomainException('ORDER_SETTLEMENT_AMOUNT_MISMATCH');
        }

        if (strtoupper((string) $order->currency) !== $intent['currency']) {
            throw new DomainException('ORDER_SETTLEMENT_CURRENCY_MISMATCH');
        }

        if ($items->isEmpty()) {
            throw new DomainException('ORDER_SETTLEMENT_REQUIRES_ITEMS');
        }

        $itemsAmount = 0;
        foreach ($items as $item) {
            if (strtoupper((string) $item->currency) !== $intent['currency']) {
                throw new DomainException('ORDER_SETTLEMENT_ITEM_CURRENCY_MISMATCH');
            }

            $itemsAmount += (int) $item->final_amount;
            if (! $coinBlocked) {
                $this->assertOrderItemSnapshotIsUsable($item);
            }
        }

        if ($itemsAmount !== $expectedAmount) {
            throw new DomainException('ORDER_SETTLEMENT_ITEM_TOTAL_MISMATCH');
        }
    }

    /**
     * @param  array{idempotency_key:string, amount:int, currency:string, settlement_type:string, payment_attempt_id:int|null, provider_event_id:int|null, metadata:array<string, mixed>}  $intent
     */
    private function assertAttemptMatchesOrder(Order $order, array $intent): void
    {
        if ($intent['payment_attempt_id'] === null) {
            return;
        }

        /** @var PaymentAttempt|null $attempt */
        $attempt = PaymentAttempt::query()
            ->whereKey($intent['payment_attempt_id'])
            ->lockForUpdate()
            ->first();

        if (! $attempt || (int) $attempt->order_id !== (int) $order->id) {
            throw new DomainException('ORDER_SETTLEMENT_ATTEMPT_MISMATCH');
        }

        if ($order->active_attempt_id !== null && (int) $order->active_attempt_id !== (int) $attempt->id) {
            throw new DomainException('ORDER_SETTLEMENT_ATTEMPT_NOT_ACTIVE');
        }

        if ((int) $attempt->amount !== $intent['amount'] || strtoupper((string) $attempt->currency) !== $intent['currency']) {
            throw new DomainException('ORDER_SETTLEMENT_ATTEMPT_AMOUNT_MISMATCH');
        }
    }

    private function assertOrderItemSnapshotIsUsable(OrderItem $item): void
    {
        if (! in_array((string) $item->sellable_kind, OrderItem::SELLABLE_KINDS, true)) {
            throw new DomainException('ORDER_ITEM_SELLABLE_KIND_UNSUPPORTED');
        }

        if (! in_array((string) $item->purchased_entity_type, OrderItem::SELLABLE_KINDS, true)) {
            throw new DomainException('ORDER_ITEM_PURCHASED_ENTITY_UNSUPPORTED');
        }

        if ($this->grantAtomsForItem($item) === []) {
            throw new DomainException('ORDER_ITEM_GRANT_SNAPSHOT_EMPTY');
        }

        try {
            $expectedHash = OrderItemSnapshotService::snapshotHash($item->getAttributesForSnapshot());
        } catch (JsonException $e) {
            throw new DomainException('ORDER_ITEM_SNAPSHOT_HASH_INVALID', previous: $e);
        }

        if (! hash_equals((string) $item->snapshot_hash, $expectedHash)) {
            throw new DomainException('ORDER_ITEM_SNAPSHOT_HASH_MISMATCH');
        }
    }

    /**
     * @return array<int, array{kind:string,id:int}>
     */
    private function grantAtomsForItem(OrderItem $item): array
    {
        $grantSnapshot = $item->grant_snapshot ?? [];
        $atoms = is_array($grantSnapshot) ? ($grantSnapshot['atoms'] ?? []) : [];

        if (! is_array($atoms)) {
            return [];
        }

        return $this->accessResolver->normalizeAtoms($atoms);
    }

    /**
     * @param  Collection<int, OrderItem>  $items
     * @param  array{idempotency_key:string, amount:int, currency:string, settlement_type:string, payment_attempt_id:int|null, provider_event_id:int|null, metadata:array<string, mixed>}  $intent
     *
     * @throws JsonException
     */
    private function fingerprintHash(Order $order, Collection $items, array $intent): string
    {
        $payload = [
            'order_id' => (int) $order->id,
            'payment_attempt_id' => $intent['payment_attempt_id'],
            'provider_event_id' => $intent['provider_event_id'],
            'settlement_type' => $intent['settlement_type'],
            'amount' => $intent['amount'],
            'currency' => $intent['currency'],
            'items' => $items->map(fn (OrderItem $item): array => [
                'id' => (int) $item->id,
                'final_amount' => (int) $item->final_amount,
                'snapshot_hash' => (string) $item->snapshot_hash,
            ])->values()->all(),
        ];

        return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
    }

    /**
     * @param  array{idempotency_key:string, amount:int, currency:string, settlement_type:string, payment_attempt_id:int|null, provider_event_id:int|null, metadata:array<string, mixed>}  $intent
     */
    private function assertSameSettlement(
        OrderSettlement $existing,
        Order $order,
        array $intent,
        string $fingerprintHash
    ): void {
        $incoming = [
            'order_id' => (int) $order->id,
            'payment_attempt_id' => $intent['payment_attempt_id'],
            'provider_event_id' => $intent['provider_event_id'],
            'settlement_type' => $intent['settlement_type'],
            'amount' => $intent['amount'],
            'currency' => $intent['currency'],
            'fingerprint_hash' => $fingerprintHash,
        ];
        $stored = [
            'order_id' => (int) $existing->order_id,
            'payment_attempt_id' => $existing->payment_attempt_id !== null ? (int) $existing->payment_attempt_id : null,
            'provider_event_id' => $existing->provider_event_id !== null ? (int) $existing->provider_event_id : null,
            'settlement_type' => (string) $existing->settlement_type,
            'amount' => (int) $existing->amount,
            'currency' => (string) $existing->currency,
            'fingerprint_hash' => (string) $existing->fingerprint_hash,
        ];

        if ($stored !== $incoming) {
            throw new InvalidArgumentException('Settlement idempotency key was reused with different confirmation data.');
        }
    }
}
