<?php

namespace App\Services\Payments;

use App\Models\Order;
use App\Models\PaymentAttempt;
use App\Models\PaymentAuditLog;
use App\Models\Refund;
use App\Services\EntitlementGrantService;
use App\Services\PaymentStateMachine;
use DomainException;
use Illuminate\Support\Facades\DB;

class YandexSplitRefundService
{
    public function __construct(
        private readonly YandexSplitClient $client,
        private readonly EntitlementGrantService $entitlements,
        private readonly PaymentStateMachine $stateMachine,
        private readonly PaymentOutboxService $outbox,
    ) {}

    public function request(Order $order, array $input, string $key, int $actorId): Refund
    {
        if (! $this->client->configured()) {
            throw new DomainException('PAYMENT_PROVIDER_NOT_CONFIGURED');
        }
        if (! preg_match('/^[A-Za-z0-9-]{16,64}$/D', $key)) {
            throw new DomainException('PAYMENT_REFUND_IDEMPOTENCY_KEY_REQUIRED');
        }
        if (($input['refund_type'] ?? null) !== 'full' || ($input['policy_access_action'] ?? null) !== 'revoke') {
            throw new DomainException('PAYMENT_REFUND_WHOLE_SERVICE_REQUIRED');
        }
        [$refund, $attempt, $created] = DB::transaction(function () use ($order, $input, $key, $actorId): array {
            $locked = Order::query()->lockForUpdate()->findOrFail($order->id);
            $attempt = $locked->activeAttempt()->lockForUpdate()->first();
            if (! $attempt || $attempt->provider !== 'yandex_split' || ! $attempt->provider_payment_id) {
                throw new DomainException('PAYMENT_REFUND_PAYMENT_MISSING');
            }
            if (($attempt->provider_substatus['sandbox'] ?? null) !== (bool) config('yandex_split.sandbox', true)) {
                throw new DomainException('PAYMENT_YANDEX_ORDER_MISMATCH');
            }
            if (($attempt->provider_substatus['merchant_id'] ?? null) !== (string) config('yandex_split.merchant_id')) {
                throw new DomainException('PAYMENT_YANDEX_ORDER_MISMATCH');
            }
            $old = Refund::query()->where('request_key', $key)->first();
            if ($old) {
                if ((int) $old->order_id !== (int) $order->id || $old->reason !== ($input['reason'] ?? '')
                    || $old->refund_type !== 'full' || $old->policy_access_action !== 'revoke') {
                    throw new DomainException('PAYMENT_REFUND_IDEMPOTENCY_CONFLICT');
                }

                return [$old, $attempt, false];
            }
            if (! in_array($locked->payment_status, ['paid', 'refunded'], true)
                || Refund::query()->where('order_id', $locked->id)->whereIn('status', ['pending', 'submitted', 'unknown'])->exists()) {
                throw new DomainException('PAYMENT_REFUND_CONFIRMATION_PENDING');
            }
            $remaining = (int) $locked->amount_paid - (int) $locked->amount_refunded;
            if ($remaining < 1) {
                throw new DomainException('PAYMENT_REFUND_AMOUNT_EXCEEDED');
            }
            $refund = Refund::query()->createOrFirst(['request_key' => $key], [
                'order_id' => $locked->id, 'attempt_id' => $attempt->id, 'refund_type' => 'full',
                'amount' => $remaining, 'currency' => $locked->currency, 'status' => 'pending',
                'reason' => $input['reason'], 'policy_access_action' => 'revoke', 'created_by' => $actorId,
            ]);
            if (! $refund->wasRecentlyCreated) {
                throw new DomainException('PAYMENT_REFUND_IDEMPOTENCY_CONFLICT');
            }
            PaymentAuditLog::query()->create([
                'order_id' => $locked->id, 'attempt_id' => $attempt->id, 'refund_id' => $refund->id,
                'action' => 'refund_requested', 'actor_type' => 'staff', 'actor_id' => $actorId,
                'reason' => $input['reason'], 'meta' => ['provider' => 'yandex_split', 'amount' => $remaining],
            ]);

            return [$refund, $attempt, true];
        }, 3);
        if (! $created) {
            return $refund;
        }
        try {
            $result = $this->client->refund((string) $attempt->provider_payment_id, (int) $refund->amount, $key);
            $operation = $result['operation'] ?? [];
            if (! is_array($operation) || ($operation['externalOperationId'] ?? null) !== $key
                || YandexSplitClient::minor($operation['amount'] ?? null) !== (int) $refund->amount) {
                throw new DomainException('PAYMENT_YANDEX_REFUND_MISMATCH');
            }
            $this->applyOperation($attempt, $operation);
        } catch (DomainException $exception) {
            if ($exception->getMessage() === 'PAYMENT_YANDEX_DUPLICATE_OPERATION') {
                return $this->reconcile($refund);
            }
            Refund::query()->whereKey($refund->id)->where('status', 'pending')->update(['status' => 'unknown']);
        }

        return $refund->fresh();
    }

    public function reconcile(Refund $refund): Refund
    {
        if (! $refund->request_key) {
            throw new DomainException('PAYMENT_YANDEX_REFUND_IDENTITY_MISSING');
        }
        $attempt = PaymentAttempt::query()->findOrFail($refund->attempt_id);
        if ($attempt->provider !== 'yandex_split'
            || ($attempt->provider_substatus['merchant_id'] ?? null) !== (string) config('yandex_split.merchant_id')
            || ($attempt->provider_substatus['sandbox'] ?? null) !== (bool) config('yandex_split.sandbox', true)) {
            throw new DomainException('PAYMENT_YANDEX_ORDER_MISMATCH');
        }
        $data = $this->client->getOrder((string) $attempt->provider_payment_id);
        $this->client->assertOrder($data, (string) $attempt->provider_payment_id, (int) $attempt->amount, (string) $attempt->currency);
        $result = $this->client->getOperation((string) $refund->request_key);
        if (($result['operation']['externalOperationId'] ?? null) !== $refund->request_key
            || YandexSplitClient::minor($result['operation']['amount'] ?? null) !== (int) $refund->amount) {
            throw new DomainException('PAYMENT_YANDEX_REFUND_MISMATCH');
        }
        $this->applyOperation($attempt, $result['operation'] ?? []);

        return $refund->fresh();
    }

    /** Apply only operations returned by an authenticated Yandex API call. */
    public function applyOperation(PaymentAttempt $attempt, array $operation): void
    {
        $id = $operation['operationId'] ?? null;
        $externalId = $operation['externalOperationId'] ?? null;
        $amount = YandexSplitClient::minor($operation['amount'] ?? null);
        if ($attempt->provider !== 'yandex_split' || ($operation['orderId'] ?? null) !== $attempt->provider_payment_id
            || ($attempt->provider_substatus['merchant_id'] ?? null) !== (string) config('yandex_split.merchant_id')
            || ($operation['operationType'] ?? null) !== 'REFUND' || ! is_string($id) || $id === '' || strlen($id) > 128
            || $amount < 1 || ! in_array($operation['status'] ?? null, ['PENDING', 'SUCCESS', 'FAIL'], true)
            || ($attempt->provider_substatus['sandbox'] ?? null) !== (bool) config('yandex_split.sandbox', true)) {
            throw new DomainException('PAYMENT_YANDEX_REFUND_MISMATCH');
        }
        DB::transaction(function () use ($attempt, $operation, $id, $externalId, $amount): void {
            $order = Order::query()->lockForUpdate()->findOrFail($attempt->order_id);
            $refund = Refund::query()->where('attempt_id', $attempt->id)->where('provider_refund_id', $id)->first();
            if (! $refund && is_string($externalId)) {
                $refund = Refund::query()->where('request_key', $externalId)->first();
            }
            if ($refund && ((int) $refund->order_id !== (int) $order->id || (int) $refund->attempt_id !== (int) $attempt->id
                || (int) $refund->amount !== $amount || $refund->currency !== $attempt->currency
                || ($refund->provider_refund_id !== null && $refund->provider_refund_id !== $id))) {
                throw new DomainException('PAYMENT_YANDEX_REFUND_MISMATCH');
            }
            if ($refund?->status === 'completed') {
                return;
            }
            if ($refund?->status === 'failed' && ($operation['status'] ?? null) === 'PENDING') {
                return;
            }
            if (($operation['status'] ?? null) !== 'SUCCESS') {
                if ($refund) {
                    $refund->forceFill(['provider_refund_id' => $id, 'status' => $operation['status'] === 'FAIL' ? 'failed' : 'submitted'])->save();
                }

                return;
            }
            if (! in_array($order->payment_status, ['paid', 'refunded'], true)
                || $amount > (int) $order->amount_paid - (int) $order->amount_refunded) {
                throw new DomainException('PAYMENT_REFUND_AMOUNT_EXCEEDED');
            }
            $full = (int) $order->amount_refunded + $amount === (int) $order->amount_paid;
            if (! $refund) {
                $refund = Refund::query()->create([
                    'order_id' => $order->id, 'attempt_id' => $attempt->id,
                    'refund_type' => $full ? 'full' : 'partial', 'amount' => $amount, 'currency' => $attempt->currency,
                    'status' => 'submitted', 'reason' => 'Provider initiated refund',
                    'policy_access_action' => $full ? 'revoke' : 'keep', 'provider_refund_id' => $id,
                ]);
            }
            $from = $order->payment_status;
            $order->amount_refunded = (int) $order->amount_refunded + $amount;
            $order->save();
            if ($full) {
                $itemIds = $order->items()->orderBy('id')->lockForUpdate()->pluck('id')->all();
                if ($order->user_id !== null) {
                    $this->entitlements->revokeOrderGrants((int) $order->user_id, (int) $order->id, $itemIds, 'refund:yandex_split:'.$id);
                }
                $order->items()->update(['access_status' => 'revoked']);
                $this->stateMachine->transition($order, 'refunded', 'revoked');
            }
            $refund->forceFill(['provider_refund_id' => $id, 'status' => 'completed', 'completed_at' => now()])->save();
            $this->outbox->publishTransition($order->fresh(), $attempt->id, $refund, $from, $order->payment_status, [
                'source' => 'yandex_split_refund', 'access_allocation' => $full ? 'whole_service' : 'manual_review',
            ]);
            PaymentAuditLog::query()->create([
                'order_id' => $order->id, 'attempt_id' => $attempt->id, 'refund_id' => $refund->id,
                'action' => $full ? 'refund_confirmed' : 'partial_service_refund_review',
                'actor_type' => 'provider', 'meta' => ['provider' => 'yandex_split', 'operation_id' => $id, 'amount' => $amount],
            ]);
        }, 3);
    }
}
