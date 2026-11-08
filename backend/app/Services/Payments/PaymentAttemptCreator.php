<?php

namespace App\Services\Payments;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PaymentAttempt;
use App\Models\PaymentAuditLog;
use App\Services\Bonuses\OrderDiscountService;
use App\Services\PaymentStateMachine;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

class PaymentAttemptCreator
{
    public function __construct(private readonly PaymentProviderRegistry $providers) {}

    /**
     * @param  array{provider:string,return_url:string,cancel_url:string}  $validated
     *
     * @throws DomainException
     */
    public function create(Order $order, array $validated, PaymentStateMachine $stateMachine): Order
    {
        $validated['provider'] = strtolower(trim($validated['provider']));
        if (in_array($validated['provider'], ['yandex_split', 'podeli'], true)) {
            return $this->createRemote($order, $validated, $stateMachine);
        }

        return DB::transaction(function () use ($order, $validated, $stateMachine): Order {
            /** @var Order $lockedOrder */
            $lockedOrder = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();

            if (! in_array($lockedOrder->payment_status, ['draft', 'pending_payment'], true)) {
                throw new DomainException('Invalid payment transition');
            }

            /** @var Collection<int, OrderItem> $items */
            $items = $lockedOrder->items()
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $this->assertOrderHasBillableItems($lockedOrder, $items);

            $provider = strtolower(trim((string) $validated['provider']));
            $adapter = $this->providers->forProvider($provider);
            if (! $adapter) {
                throw new DomainException('PAYMENT_PROVIDER_NOT_CONFIGURED');
            }

            $amount = (int) $lockedOrder->amount_final;
            $currency = strtoupper((string) $lockedOrder->currency);

            $activeAttempt = $lockedOrder->activeAttempt()->lockForUpdate()->first();
            if ($activeAttempt?->provider === 'robokassa' && $activeAttempt->status === 'pending') {
                if ($provider === 'robokassa' && $activeAttempt->provider_payment_url !== null) {
                    return $lockedOrder->fresh();
                }
                throw new DomainException('PAYMENT_CONFIRMATION_PENDING');
            }
            if (in_array($activeAttempt?->provider, ['yandex_split', 'podeli'], true) && $activeAttempt->status === 'pending') {
                throw new DomainException('PAYMENT_CONFIRMATION_PENDING');
            }
            if ($activeAttempt?->provider === 'cloudpayments' && $activeAttempt->status === 'pending'
                && $activeAttempt->provider_payment_id !== null) {
                throw new DomainException('PAYMENT_CONFIRMATION_PENDING');
            }
            if ($provider === 'cloudpayments' && $activeAttempt?->provider === $provider
                && $activeAttempt->status === 'pending'
                && $activeAttempt->expires_at?->isFuture()
                && (int) $activeAttempt->amount === $amount
                && $activeAttempt->currency === $currency
                && ($activeAttempt->provider_substatus['mode'] ?? null) === 'widget') {
                return $lockedOrder->fresh();
            }

            $fromStatus = $lockedOrder->payment_status;

            if ($lockedOrder->payment_status !== 'pending_payment') {
                $stateMachine->transition($lockedOrder, 'pending_payment');
            }

            $attempt = PaymentAttempt::query()->create([
                'order_id' => $lockedOrder->id,
                'provider' => $provider,
                'provider_payment_id' => null,
                'provider_payment_url' => null,
                'status' => 'pending',
                'amount' => $amount,
                'currency' => $currency,
                'provider_substatus' => [],
            ]);

            $providerPayment = $adapter->startPayment([
                'order_id' => (int) $lockedOrder->id,
                'attempt_id' => (int) $attempt->id,
                'amount' => $amount,
                'currency' => $currency,
                'return_url' => (string) $validated['return_url'],
                'cancel_url' => (string) $validated['cancel_url'],
            ]);
            $attempt->forceFill([
                'provider_payment_id' => $providerPayment['provider_payment_id'] ?? null,
                'provider_payment_url' => $providerPayment['provider_payment_url'] ?? null,
                'provider_substatus' => $providerPayment['provider_substatus'] ?? [],
                'expires_at' => $this->limitExpiry($lockedOrder, $providerPayment['expires_at'] ?? null),
            ])->save();

            $lockedOrder->active_attempt_id = $attempt->id;
            $lockedOrder->save();

            PaymentAuditLog::query()->create([
                'order_id' => $lockedOrder->id,
                'attempt_id' => $attempt->id,
                'action' => 'attempt_created',
                'from_status' => $fromStatus,
                'to_status' => 'pending_payment',
                'actor_type' => 'system',
                'actor_id' => $lockedOrder->user_id,
                'meta' => [
                    'provider' => $provider,
                    'cancel_url' => $validated['cancel_url'],
                ],
            ]);

            return $lockedOrder->fresh();
        });
    }

    /** @param array{provider:string,return_url:string,cancel_url:string} $validated */
    private function createRemote(Order $order, array $validated, PaymentStateMachine $stateMachine): Order
    {
        if (DB::transactionLevel() !== 0) {
            throw new DomainException('PAYMENT_REMOTE_TRANSACTION_BOUNDARY_INVALID');
        }
        $provider = $validated['provider'];
        $adapter = $this->providers->forProvider($provider);
        if (! $adapter || ! config($provider.'.enabled', false)) {
            throw new DomainException('PAYMENT_PROVIDER_NOT_CONFIGURED');
        }
        [$attempt, $items, $created] = DB::transaction(function () use ($order, $validated, $stateMachine, $provider, $adapter): array {
            $locked = Order::query()->lockForUpdate()->findOrFail($order->id);
            if (! in_array($locked->payment_status, ['draft', 'pending_payment'], true)) {
                throw new DomainException('Invalid payment transition');
            }
            $items = $locked->items()->orderBy('id')->lockForUpdate()->get();
            $this->assertOrderHasBillableItems($locked, $items);
            if ($adapter instanceof YandexSplitPaymentProviderAdapter || $adapter instanceof PodeliPaymentProviderAdapter) {
                $adapter->validateStartPayload([
                    'amount' => (int) $locked->amount_final, 'currency' => $locked->currency,
                    'return_url' => $validated['return_url'], 'cancel_url' => $validated['cancel_url'],
                    'client_info' => $validated['client_info'] ?? [],
                    'items' => $items->map(fn (OrderItem $item): array => $item->only([
                        'sellable_sku', 'display_title', 'quantity', 'final_amount',
                    ]))->all(),
                ]);
            }
            $active = $locked->activeAttempt()->lockForUpdate()->first();
            if ($active?->status === 'pending' && ($active->provider_payment_id !== null
                || in_array($active->provider, ['yandex_split', 'podeli', 'robokassa'], true))) {
                if ($active->provider === $provider && $active->provider_payment_url !== null
                    && $active->expires_at?->isFuture()) {
                    return [$active, $items, false];
                }
                throw new DomainException('PAYMENT_CONFIRMATION_PENDING');
            }
            if ($locked->payment_status === 'draft') {
                $stateMachine->transition($locked, 'pending_payment');
            }
            $attempt = PaymentAttempt::query()->create([
                'order_id' => $locked->id, 'provider' => $provider, 'status' => 'pending',
                'amount' => (int) $locked->amount_final, 'currency' => $locked->currency,
                'expires_at' => $this->limitExpiry($locked, null),
                'provider_substatus' => ['initialization' => 'pending', 'sandbox' => (bool) config($provider.'.sandbox', true),
                    'merchant_id' => config($provider.'.merchant_id'),
                    'account_fingerprint' => $provider === 'podeli' ? PodeliClient::accountFingerprint() : null,
                    'capture_mode' => config($provider.'.capture_mode')],
            ]);
            $attempt->update(['provider_payment_id' => "cms-{$locked->id}-{$attempt->id}"]);
            $locked->update(['active_attempt_id' => $attempt->id]);
            PaymentAuditLog::query()->create([
                'order_id' => $locked->id, 'attempt_id' => $attempt->id, 'action' => 'attempt_reserved',
                'actor_type' => 'system', 'actor_id' => $locked->user_id,
                'meta' => ['provider' => $provider],
            ]);

            return [$attempt, $items, true];
        }, 3);
        if (! $created) {
            return $order->fresh();
        }
        try {
            $payment = $adapter->startPayment([
                'order_id' => (int) $order->id, 'attempt_id' => (int) $attempt->id,
                'amount' => (int) $attempt->amount, 'currency' => $attempt->currency,
                'return_url' => $validated['return_url'], 'cancel_url' => $validated['cancel_url'],
                'client_info' => $validated['client_info'] ?? [],
                'items' => $items->map(fn (OrderItem $item): array => $item->only([
                    'sellable_sku', 'display_title', 'quantity', 'final_amount',
                ]))->all(),
            ]);
        } catch (Throwable) {
            // A provider might have created the session despite a lost response.
            // Leave the same durable identity for readback; never create a new one.
            DB::transaction(function () use ($order, $attempt): void {
                Order::query()->lockForUpdate()->findOrFail($order->id);
                $current = PaymentAttempt::query()->lockForUpdate()->findOrFail($attempt->id);
                if ($current->status === 'pending') {
                    $current->update(['provider_substatus' => [...($current->provider_substatus ?? []), 'initialization' => 'unknown']]);
                }
            });
            throw new DomainException('PAYMENT_CONFIRMATION_PENDING');
        }

        return DB::transaction(function () use ($order, $attempt, $payment): Order {
            $locked = Order::query()->lockForUpdate()->findOrFail($order->id);
            $current = PaymentAttempt::query()->lockForUpdate()->findOrFail($attempt->id);
            if (($payment['provider_payment_id'] ?? null) !== $current->provider_payment_id
                || (int) $locked->active_attempt_id !== (int) $current->id) {
                throw new DomainException('PAYMENT_PROVIDER_IDENTITY_CONFLICT');
            }
            if ($current->status === 'pending') {
                $current->update([
                    'provider_payment_url' => $payment['provider_payment_url'] ?? null,
                    'provider_substatus' => [...($current->provider_substatus ?? []), ...($payment['provider_substatus'] ?? []), 'initialization' => 'ready'],
                    'expires_at' => $this->limitExpiry($locked, $payment['expires_at'] ?? null),
                ]);
            }

            return $locked->fresh();
        });
    }

    /**
     * @param  Collection<int, OrderItem>  $items
     */
    private function assertOrderHasBillableItems(Order $order, Collection $items): void
    {
        app(OrderDiscountService::class)->assertCanStartPayment($order);
        if ($items->isEmpty()) {
            throw new DomainException('PAYMENT_ORDER_REQUIRES_ITEMS');
        }

        $expectedAmount = (int) $order->amount_final;
        $itemsAmount = 0;
        $orderCurrency = strtoupper((string) $order->currency);

        foreach ($items as $item) {
            if (strtoupper((string) $item->currency) !== $orderCurrency) {
                throw new DomainException('PAYMENT_ORDER_ITEM_CURRENCY_MISMATCH');
            }

            $itemsAmount += (int) $item->final_amount;
        }

        if ($itemsAmount !== $expectedAmount) {
            throw new DomainException('PAYMENT_ORDER_ITEM_TOTAL_MISMATCH');
        }
    }

    private function limitExpiry(Order $order, mixed $providerExpiry): mixed
    {
        $binding = app(OrderDiscountService::class)->binding($order);
        if ($binding === null) {
            return $providerExpiry;
        }

        return $providerExpiry === null ? $binding['expires_at'] : CarbonImmutable::parse($providerExpiry)->min($binding['expires_at']);
    }
}
