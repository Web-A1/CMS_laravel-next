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

class YandexSplitWebhookHandler
{
    public function __construct(
        private readonly YandexSplitClient $client,
        private readonly OrderSettlementService $settlements,
        private readonly PaymentOutboxService $outbox,
        private readonly PaymentStateMachine $stateMachine,
        private readonly YandexSplitRefundService $refunds,
    ) {}

    public function handle(string $token): void
    {
        $claims = $this->client->verifyToken($token);
        if (! in_array($claims['event'] ?? '', ['ORDER_STATUS_UPDATED', 'OPERATION_STATUS_UPDATED'], true)) {
            return;
        }
        $id = $claims['order']['orderId'] ?? $claims['operation']['orderId'] ?? null;
        if (! is_string($id) || ! preg_match('/^cms-([1-9][0-9]*)-([1-9][0-9]*)$/D', $id, $matches)) {
            throw new DomainException('PAYMENT_YANDEX_ORDER_MISMATCH');
        }
        $attempt = PaymentAttempt::query()->whereKey($matches[2])->where('order_id', $matches[1])
            ->where('provider', 'yandex_split')->first();
        if (! $attempt || ($attempt->provider_payment_id !== null && $attempt->provider_payment_id !== $id)) {
            throw new DomainException('PAYMENT_YANDEX_ORDER_MISMATCH');
        }
        $eventKey = 'jwt:'.hash('sha256', json_encode($claims, JSON_THROW_ON_ERROR));
        $this->reconcile($attempt, $eventKey, true);
    }

    public function reconcile(PaymentAttempt $attempt, ?string $eventKey = null, bool $signed = false): void
    {
        if ($attempt->provider !== 'yandex_split'
            || ($attempt->provider_substatus['merchant_id'] ?? null) !== (string) config('yandex_split.merchant_id')
            || ($attempt->provider_substatus['sandbox'] ?? null) !== (bool) config('yandex_split.sandbox', true)) {
            throw new DomainException('PAYMENT_YANDEX_ORDER_MISMATCH');
        }
        $id = YandexSplitClient::orderId((int) $attempt->order_id, (int) $attempt->id);
        if ($attempt->provider_payment_id !== null && $attempt->provider_payment_id !== $id) {
            throw new DomainException('PAYMENT_YANDEX_ORDER_MISMATCH');
        }
        // Provider IO deliberately precedes transaction/row locks.
        $data = $this->client->getOrder($id);
        $remote = $this->client->assertOrder($data, $id, (int) $attempt->amount, (string) $attempt->currency);
        $status = $remote['paymentStatus'] ?? '';
        $paymentUrl = isset($remote['paymentUrl']) ? $this->client->paymentUrl($remote['paymentUrl']) : null;
        $key = $eventKey ?? 'read:'.hash('sha256', $id.':'.$status.':'.($remote['updated'] ?? ''));
        DB::transaction(function () use ($attempt, $id, $status, $key, $signed, $paymentUrl): void {
            $order = Order::query()->lockForUpdate()->findOrFail($attempt->order_id);
            $lockedAttempt = PaymentAttempt::query()->lockForUpdate()->findOrFail($attempt->id);
            if ((int) $order->active_attempt_id !== (int) $lockedAttempt->id) {
                throw new DomainException('PAYMENT_YANDEX_STALE_ATTEMPT_REVIEW');
            }
            if ((int) $lockedAttempt->amount !== (int) $attempt->amount || $lockedAttempt->currency !== $attempt->currency) {
                throw new DomainException('PAYMENT_YANDEX_ORDER_MISMATCH');
            }
            if (($lockedAttempt->provider_substatus['merchant_id'] ?? null) !== (string) config('yandex_split.merchant_id')
                || ($lockedAttempt->provider_substatus['sandbox'] ?? null) !== (bool) config('yandex_split.sandbox', true)) {
                throw new DomainException('PAYMENT_YANDEX_ORDER_MISMATCH');
            }
            $event = ProviderEvent::query()->createOrFirst(
                ['provider' => 'yandex_split', 'provider_event_id' => $key],
                ['event_type' => 'yandex_split.readback', 'order_id' => $order->id, 'attempt_id' => $attempt->id,
                    'signature_valid' => $signed, 'processed' => false, 'received_at' => now(),
                    'payload_raw' => ['order_id' => $id, 'payment_status' => $status, 'source' => $signed ? 'signed_callback_readback' : 'authenticated_readback']],
            );
            if ((int) $event->order_id !== (int) $order->id || (int) $event->attempt_id !== (int) $attempt->id) {
                throw new DomainException('PAYMENT_YANDEX_EVENT_CONFLICT');
            }
            if ($event->processed) {
                return;
            }
            $from = $order->payment_status;
            if ($status === 'CAPTURED' && ! in_array($from, ['paid', 'refunded'], true)) {
                $this->settlements->confirmPaid($order, [
                    'idempotency_key' => 'yandex_split:paid:'.$id, 'amount' => (int) $attempt->amount,
                    'currency' => $attempt->currency, 'settlement_type' => OrderSettlement::TYPE_PROVIDER_PAYMENT,
                    'payment_attempt_id' => $attempt->id, 'provider_event_id' => $event->id,
                    'metadata' => ['provider' => 'yandex_split', 'provider_order_id' => $id],
                ]);
                $order->refresh();
                $lockedAttempt->forceFill(['status' => 'completed', 'completed_at' => now()])->save();
                $this->outbox->publishTransition($order, $attempt->id, null, $from, 'paid', ['source' => 'yandex_split_readback']);
            } elseif ($status === 'FAILED' && $this->stateMachine->canTransition($from, 'cancelled')) {
                app(OrderDiscountService::class)->releaseForOrder($order, 'failed');
                $this->stateMachine->transition($order, 'cancelled', 'not_granted');
                $lockedAttempt->forceFill(['status' => 'cancelled'])->save();
                $this->outbox->publishTransition($order->fresh(), $attempt->id, null, $from, 'cancelled', ['source' => 'yandex_split_readback']);
            }
            $lockedAttempt->forceFill(['provider_payment_id' => $id, 'provider_substatus' => [
                ...($lockedAttempt->provider_substatus ?? []), 'provider_status' => $status, 'initialization' => 'completed',
            ]])->save();
            if ($paymentUrl !== null) {
                $lockedAttempt->forceFill([
                    'provider_payment_url' => $paymentUrl,
                    'expires_at' => $lockedAttempt->expires_at ?? $lockedAttempt->created_at->copy()->addMinutes(30),
                ])->save();
            }
            PaymentAuditLog::query()->create([
                'order_id' => $order->id, 'attempt_id' => $attempt->id, 'event_id' => $event->id,
                'action' => 'yandex_split_reconciled', 'actor_type' => 'provider', 'from_status' => $from,
                'to_status' => $order->fresh()->payment_status, 'meta' => ['provider_status' => $status],
            ]);
            $event->forceFill(['processed' => true, 'processed_at' => now()])->save();
        }, 3);
        // Each returned operation is authenticated by getOrder; local matching and
        // amount validation remain mandatory and independent of callback content.
        foreach ($data['operations'] ?? [] as $operation) {
            if (is_array($operation) && ($operation['operationType'] ?? null) === 'REFUND') {
                $this->refunds->applyOperation($attempt->fresh(), $operation);
            }
        }
    }
}
