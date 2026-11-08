<?php

namespace App\Services\Payments;

use App\Models\Order;
use App\Models\OrderSettlement;
use App\Models\PaymentAttempt;
use App\Models\PaymentAuditLog;
use App\Models\ProviderEvent;
use App\Services\Bonuses\OrderDiscountService;
use App\Services\OrderSettlementService;
use App\Services\PaymentStateMachine;
use DomainException;
use Illuminate\Support\Facades\DB;

class PodeliWebhookHandler
{
    public function __construct(
        private readonly PodeliClient $client,
        private readonly OrderSettlementService $settlements,
        private readonly PaymentStateMachine $stateMachine,
        private readonly PaymentOutboxService $outbox,
        private readonly PodeliRefundService $refunds,
    ) {}

    public function handle(array $payload): void
    {
        $id = $payload['order']['id'] ?? null;
        $time = $payload['order']['statusDateTime'] ?? null;
        if (! is_string($id) || ! preg_match('/^cms-([1-9][0-9]*)-([1-9][0-9]*)$/D', $id, $m)
            || ! is_string($time) || strlen($time) > 64
            || ! preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})?$/D', $time)) {
            throw new DomainException('PAYMENT_PODELI_CALLBACK_INVALID');
        }
        $attempt = PaymentAttempt::query()->whereKey($m[2])->where('order_id', $m[1])->where('provider', 'podeli')->first();
        if (! $attempt || $attempt->provider_payment_id !== $id) {
            throw new DomainException('PAYMENT_PODELI_ORDER_MISMATCH');
        }
        // Notification fields never directly establish payment/refund outcomes.
        $this->reconcile($attempt, $time);
    }

    public function reconcile(PaymentAttempt $attempt, ?string $notificationTime = null, bool $allowCommit = true): void
    {
        $this->assertAttempt($attempt);
        $item = $attempt->order->items()->sole();
        $inspected = ['amount' => (int) $attempt->amount, 'currency' => $attempt->currency,
            'item' => $item->only(['id', 'sellable_sku', 'quantity', 'final_amount'])];
        $itemId = PodeliClient::itemId((string) $item->sellable_sku);
        $data = $this->client->getOrder((string) $attempt->provider_payment_id);
        $remote = $this->client->assertOrder($data, (string) $attempt->provider_payment_id, (int) $attempt->amount, $itemId);
        $status = $remote['status'] ?? '';
        if (! in_array($status, ['CREATED', 'APPROVED', 'WAIT_FOR_COMMIT', 'COMPLETED', 'REJECTED', 'CANCELLED', 'REFUNDED'], true)) {
            throw new DomainException('PAYMENT_PODELI_STATUS_UNKNOWN');
        }
        if ($status === 'WAIT_FOR_COMMIT' && $allowCommit && $attempt->provider_substatus['capture_mode'] === 'two_stage') {
            $reserved = DB::transaction(function () use ($attempt, $inspected): bool {
                $order = Order::query()->lockForUpdate()->findOrFail($attempt->order_id);
                $locked = PaymentAttempt::query()->lockForUpdate()->findOrFail($attempt->id);
                $this->assertInspectedSnapshot($locked, $order, $inspected);
                if ((int) $order->active_attempt_id !== (int) $locked->id || $order->payment_status !== 'pending_payment') {
                    throw new DomainException('PAYMENT_PODELI_STALE_ATTEMPT_REVIEW');
                }
                if (isset($locked->provider_substatus['commit_state'])) {
                    return false;
                }
                try {
                    app(OrderDiscountService::class)->assertCanStartPayment($order);
                } catch (DomainException $exception) {
                    $locked->update(['provider_substatus' => [...$locked->provider_substatus, 'coin_capture_blocked' => $exception->getMessage()]]);

                    return false;
                }
                $locked->update(['provider_substatus' => [...$locked->provider_substatus, 'commit_state' => 'pending']]);

                return true;
            }, 3);
            if ($reserved) {
                try {
                    $this->client->commit((string) $attempt->provider_payment_id, (int) $attempt->amount);
                    $commitState = 'submitted';
                } catch (DomainException) {
                    $commitState = 'unknown';
                }
                DB::transaction(function () use ($attempt, $commitState): void {
                    Order::query()->lockForUpdate()->findOrFail($attempt->order_id);
                    $locked = PaymentAttempt::query()->lockForUpdate()->findOrFail($attempt->id);
                    if (($locked->provider_substatus['commit_state'] ?? '') === 'pending') {
                        $locked->update(['provider_substatus' => [...$locked->provider_substatus, 'commit_state' => $commitState]]);
                    }
                });
                $this->reconcile($attempt->fresh(), $notificationTime, false);

                return;
            }
        }
        $summary = ['provider_id' => $attempt->provider_payment_id, 'status' => $status, 'notification_time' => $notificationTime];
        $key = 'info:'.hash('sha256', json_encode([$summary, $data['refund'] ?? []], JSON_THROW_ON_ERROR));
        DB::transaction(function () use ($attempt, $data, $status, $summary, $key, $notificationTime, $inspected): void {
            $order = Order::query()->lockForUpdate()->findOrFail($attempt->order_id);
            $locked = PaymentAttempt::query()->lockForUpdate()->findOrFail($attempt->id);
            $this->assertInspectedSnapshot($locked, $order, $inspected);
            if ((int) $order->active_attempt_id !== (int) $locked->id) {
                throw new DomainException('PAYMENT_PODELI_STALE_ATTEMPT_REVIEW');
            }
            $this->assertAttempt($locked);
            // Reordered notifications are triggers only. Their timestamps cannot
            // suppress a newer authoritative INFO result or downgrade settlement.
            $event = ProviderEvent::query()->createOrFirst(['provider' => 'podeli', 'provider_event_id' => $key], [
                'event_type' => 'podeli.info', 'order_id' => $order->id, 'attempt_id' => $locked->id,
                'signature_valid' => false, 'processed' => false, 'received_at' => now(),
                'payload_raw' => [...$summary, 'source' => 'authenticated_mtls_info'],
            ]);
            if ((int) $event->order_id !== (int) $order->id || (int) $event->attempt_id !== (int) $locked->id) {
                throw new DomainException('PAYMENT_PODELI_EVENT_CONFLICT');
            }
            if ($event->processed) {
                return;
            }
            $from = $order->payment_status;
            $confirmedRefundHistory = $status === 'REFUNDED' && is_array($data['refund'] ?? null)
                && count(array_filter($data['refund'], static fn ($entry): bool => is_array($entry) && ($entry['status'] ?? null) === 'PROCESSED')) > 0;
            if (($status === 'COMPLETED' || $confirmedRefundHistory) && ! in_array($from, ['paid', 'refunded'], true)) {
                $this->settlements->confirmPaid($order, [
                    'idempotency_key' => 'podeli:paid:'.$locked->provider_payment_id,
                    'amount' => (int) $locked->amount, 'currency' => $locked->currency,
                    'settlement_type' => OrderSettlement::TYPE_PROVIDER_PAYMENT,
                    'payment_attempt_id' => $locked->id, 'provider_event_id' => $event->id,
                    'metadata' => ['provider' => 'podeli', 'provider_order_id' => $locked->provider_payment_id],
                ]);
                $order->refresh();
                $locked->update(['status' => 'completed', 'completed_at' => now()]);
                $this->outbox->publishTransition($order, $locked->id, null, $from, 'paid', ['source' => 'podeli_info']);
            } elseif (in_array($status, ['REJECTED', 'CANCELLED'], true) && $this->stateMachine->canTransition($from, 'cancelled')) {
                app(OrderDiscountService::class)->releaseForOrder($order, 'cancelled');
                $this->stateMachine->transition($order, 'cancelled', 'not_granted');
                $locked->update(['status' => 'cancelled']);
                $this->outbox->publishTransition($order->fresh(), $locked->id, null, $from, 'cancelled', ['source' => 'podeli_info']);
            } elseif ($status === 'REFUNDED' && ! in_array($from, ['paid', 'refunded'], true)) {
                throw new DomainException('PAYMENT_PODELI_SETTLEMENT_HISTORY_REVIEW');
            }
            $meta = [...$locked->provider_substatus, 'provider_status' => $status, 'initialization' => 'ready'];
            if ($notificationTime !== null) {
                $meta['last_notification_time'] = $notificationTime;
            }
            $locked->update(['provider_substatus' => $meta]);
            foreach ($data['refund'] ?? [] as $refund) {
                if (! is_array($refund)) {
                    throw new DomainException('PAYMENT_PODELI_REFUND_MISMATCH');
                }
                $this->refunds->applyRefund($locked, $refund);
            }
            PaymentAuditLog::query()->create([
                'order_id' => $order->id, 'attempt_id' => $locked->id, 'event_id' => $event->id,
                'action' => 'podeli_reconciled', 'actor_type' => 'provider', 'from_status' => $from,
                'to_status' => $order->fresh()->payment_status, 'meta' => $summary,
            ]);
            $event->update(['processed' => true, 'processed_at' => now()]);
        }, 3);
    }

    private function assertInspectedSnapshot(PaymentAttempt $attempt, Order $order, array $inspected): void
    {
        $this->assertAttempt($attempt);
        $item = $order->items()->lockForUpdate()->sole();
        if ((int) $attempt->amount !== $inspected['amount'] || $attempt->currency !== $inspected['currency']
            || (int) $order->amount_final !== $inspected['amount'] || $order->currency !== $inspected['currency']
            || $item->only(['id', 'sellable_sku', 'quantity', 'final_amount']) !== $inspected['item']
            || (int) $item->final_amount !== $inspected['amount'] || (int) $item->quantity !== 1) {
            throw new DomainException('PAYMENT_PODELI_ORDER_MISMATCH');
        }
    }

    public function assertAttempt(PaymentAttempt $attempt): void
    {
        if ($attempt->provider !== 'podeli' || $attempt->currency !== 'RUB'
            || $attempt->provider_payment_id !== PodeliClient::orderId((int) $attempt->order_id, (int) $attempt->id)
            || ($attempt->provider_substatus['sandbox'] ?? null) !== (bool) config('podeli.sandbox', true)
            || ($attempt->provider_substatus['account_fingerprint'] ?? null) !== $this->client->accountFingerprint()
            || ($attempt->provider_substatus['capture_mode'] ?? null) !== config('podeli.capture_mode')) {
            throw new DomainException('PAYMENT_PODELI_ORDER_MISMATCH');
        }
    }
}
