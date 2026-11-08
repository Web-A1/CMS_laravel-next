<?php

namespace App\Services\Payments;

use App\Models\Order;
use App\Models\PaymentAuditLog;
use App\Models\Refund;
use App\Services\EntitlementGrantService;
use App\Services\PaymentStateMachine;
use DomainException;
use Illuminate\Support\Facades\DB;

class RobokassaRefundService
{
    public function __construct(private readonly RobokassaClient $client, private readonly PaymentStateMachine $states,
        private readonly EntitlementGrantService $entitlements, private readonly PaymentOutboxService $outbox) {}

    public function request(Order $order, array $input, string $key, int $actorId): Refund
    {
        (new RobokassaPaymentProviderAdapter('robokassa', ''))->assertConfigured();
        if (! config('robokassa.refund_enabled') || ! config('robokassa.password3')) {
            throw new DomainException('ROBOKASSA_REFUND_NOT_CONFIGURED');
        }
        if (! preg_match('/^[A-Za-z0-9-]{16,64}$/D', $key)) {
            throw new DomainException('PAYMENT_REFUND_IDEMPOTENCY_KEY_REQUIRED');
        }
        [$refund, $created, $invoice, $expectedCustom] = DB::transaction(function () use ($order, $input, $key, $actorId): array {
            $locked = Order::query()->lockForUpdate()->findOrFail($order->id);
            $existing = Refund::query()->where('request_key', $key)->first();
            if ($existing) {
                if ((int) $existing->order_id !== $locked->id || ($input['refund_type'] ?? null) !== $existing->refund_type
                    || ($input['policy_access_action'] ?? null) !== $existing->policy_access_action || ($input['reason'] ?? '') !== $existing->reason
                    || $locked->paymentAttempts()->whereKey($existing->attempt_id)->value('provider') !== 'robokassa') {
                    throw new DomainException('PAYMENT_REFUND_IDEMPOTENCY_CONFLICT');
                }

                return [$existing, false, null, null];
            }
            if (config('robokassa.test_mode')) {
                throw new DomainException('ROBOKASSA_TEST_LOOKUP_UNSUPPORTED');
            }
            $attempt = $locked->activeAttempt()->lockForUpdate()->first();
            if ($locked->payment_status !== 'paid' || $locked->settlement_type !== 'provider_payment'
                || (int) $locked->amount_paid < 1 || (int) $locked->amount_refunded !== 0 || $locked->items()->count() !== 1
                || ! $attempt || $attempt->provider !== 'robokassa' || ! $attempt->provider_payment_id
                || ($attempt->provider_substatus['test_mode'] ?? null) !== false
                || ($attempt->provider_substatus['merchant_login'] ?? null) !== config('robokassa.merchant_login')) {
                throw new DomainException('PAYMENT_REFUND_FORBIDDEN');
            }
            if (($input['refund_type'] ?? null) !== 'full' || ($input['policy_access_action'] ?? null) !== 'revoke') {
                throw new DomainException('PAYMENT_REFUND_WHOLE_SERVICE_REQUIRED');
            }
            $item = $locked->items()->firstOrFail();
            if ($locked->currency !== 'RUB' || $attempt->currency !== 'RUB' || (int) $attempt->amount !== (int) $locked->amount_paid
                || (int) $item->final_amount !== (int) $locked->amount_paid || (int) $item->quantity !== 1
                || ! empty($item->benefit_snapshot['coins_writeoff']) || ($item->benefit_snapshot['discount_type'] ?? '') === 'coins') {
                throw new DomainException('PAYMENT_REFUND_FISCAL_QUALIFICATION_REQUIRED');
            }
            if (Refund::query()->where('order_id', $locked->id)->whereIn('status', ['pending', 'submitted', 'unknown'])->exists()) {
                throw new DomainException('PAYMENT_REFUND_CONFIRMATION_PENDING');
            }
            $refund = Refund::query()->createOrFirst(['request_key' => $key], ['order_id' => $locked->id,
                'attempt_id' => $attempt->id, 'refund_type' => 'full', 'amount' => $locked->amount_paid, 'currency' => $locked->currency,
                'status' => 'pending', 'reason' => $input['reason'] ?? '', 'policy_access_action' => 'revoke', 'created_by' => $actorId]);
            if (! $refund->wasRecentlyCreated) {
                throw new DomainException('PAYMENT_REFUND_IDEMPOTENCY_CONFLICT');
            }

            return [$refund, true, (string) $attempt->provider_payment_id, $attempt->provider_substatus['custom'] ?? []];
        });
        if (! $created) {
            return $refund;
        }
        $dispatching = false;
        try {
            $operation = $this->client->operation($invoice);
            ksort($operation['custom']);
            ksort($expectedCustom);
            if ($operation['amount'] !== (int) $refund->amount || $operation['custom'] !== $expectedCustom) {
                throw new DomainException('ROBOKASSA_PAYMENT_MISMATCH');
            }
            // Persist the exact provider operation before sending a non-idempotent money request.
            PaymentAuditLog::query()->create(['order_id' => $refund->order_id, 'attempt_id' => $refund->attempt_id,
                'refund_id' => $refund->id, 'action' => 'robokassa_refund_dispatching', 'actor_type' => 'staff', 'actor_id' => $actorId,
                'meta' => ['op_key' => $operation['op_key'], 'request_key' => $key, 'amount' => (int) $refund->amount]]);
            $dispatching = true;
            $id = $this->client->createRefund($operation['op_key']);
            DB::transaction(function () use ($refund, $id, $operation, $key): void {
                Order::query()->lockForUpdate()->findOrFail($refund->order_id);
                $locked = Refund::query()->lockForUpdate()->findOrFail($refund->id);
                if ($locked->status !== 'pending') {
                    throw new DomainException('ROBOKASSA_REFUND_STATE_CONFLICT');
                }
                $locked->update(['provider_refund_id' => $id, 'status' => 'submitted']);
                PaymentAuditLog::query()->create(['order_id' => $locked->order_id, 'attempt_id' => $locked->attempt_id,
                    'refund_id' => $locked->id, 'action' => 'robokassa_refund_submitted', 'actor_type' => 'provider',
                    'meta' => ['provider_request_id' => $id, 'op_key' => $operation['op_key'], 'request_key' => $key,
                        'amount' => (int) $locked->amount, 'merchant_login' => config('robokassa.merchant_login'),
                        'test_mode' => (bool) config('robokassa.test_mode')]]);
            });
        } catch (DomainException $exception) {
            $refund->update(['status' => ! $dispatching || $exception->getMessage() === 'ROBOKASSA_REFUND_REJECTED' ? 'failed' : 'unknown']);
        }

        return $refund->fresh();
    }

    public function reconcile(Refund $refund): Refund
    {
        $refund->refresh();
        $order = $refund->order()->firstOrFail();
        $attempt = $order->paymentAttempts()->find($refund->attempt_id);
        if (! $attempt || $attempt->provider !== 'robokassa' || ($attempt->provider_substatus['test_mode'] ?? null) !== (bool) config('robokassa.test_mode')
            || ($attempt->provider_substatus['merchant_login'] ?? null) !== config('robokassa.merchant_login')) {
            throw new DomainException('ROBOKASSA_PAYMENT_MISMATCH');
        }
        if ($refund->status === 'completed' || ! $refund->provider_refund_id) {
            return $refund;
        }
        $submitted = PaymentAuditLog::query()->where('order_id', $refund->order_id)->where('attempt_id', $refund->attempt_id)
            ->where('refund_id', $refund->id)->where('action', 'robokassa_refund_submitted')->get();
        $dispatch = PaymentAuditLog::query()->where('order_id', $refund->order_id)->where('attempt_id', $refund->attempt_id)
            ->where('refund_id', $refund->id)->where('action', 'robokassa_refund_dispatching')->get();
        if ($submitted->count() !== 1 || $dispatch->count() !== 1) {
            throw new DomainException('ROBOKASSA_REFUND_PROVENANCE_MISSING');
        }
        $proof = $submitted->first()->meta ?? [];
        if (($proof['provider_request_id'] ?? null) !== $refund->provider_refund_id
            || ($proof['request_key'] ?? null) !== $refund->request_key
            || ($proof['merchant_login'] ?? null) !== config('robokassa.merchant_login')
            || ($proof['test_mode'] ?? null) !== (bool) config('robokassa.test_mode')
            || ($proof['amount'] ?? null) !== (int) $refund->amount || empty($proof['op_key'])
            || ($dispatch->first()->meta['op_key'] ?? null) !== $proof['op_key']
            || ($dispatch->first()->meta['amount'] ?? null) !== (int) $refund->amount
            || ($dispatch->first()->meta['request_key'] ?? null) !== $refund->request_key) {
            throw new DomainException('ROBOKASSA_REFUND_PROVENANCE_MISSING');
        }
        $state = $this->client->refundState($refund->provider_refund_id);
        if ($state['amount'] !== (int) $refund->amount) {
            throw new DomainException('ROBOKASSA_REFUND_AMOUNT_MISMATCH');
        }

        return DB::transaction(function () use ($refund, $state): Refund {
            $order = Order::query()->lockForUpdate()->findOrFail($refund->order_id);
            $locked = Refund::query()->lockForUpdate()->findOrFail($refund->id);
            if ($locked->provider_refund_id !== $refund->provider_refund_id || $locked->request_key !== $refund->request_key
                || (int) $locked->amount !== (int) $refund->amount) {
                throw new DomainException('ROBOKASSA_REFUND_PROVENANCE_MISSING');
            }
            if ($locked->status === 'completed' || $state['status'] === 'processing') {
                return $locked;
            }
            if ($state['status'] === 'canceled') {
                $locked->update(['status' => 'failed']);

                return $locked;
            }
            if ((int) $order->amount_refunded !== 0 || (int) $order->amount_paid !== (int) $locked->amount
                || $order->payment_status !== 'paid') {
                throw new DomainException('ROBOKASSA_REFUND_STATE_CONFLICT');
            }
            $itemIds = $order->items()->lockForUpdate()->pluck('id')->all();
            if ($order->user_id !== null) {
                $this->entitlements->revokeOrderGrants((int) $order->user_id, $order->id, $itemIds, 'refund:robokassa');
            }
            $order->items()->update(['access_status' => 'revoked']);
            $order->amount_refunded = (int) $locked->amount;
            $order->save();
            $this->states->transition($order, 'refunded', 'revoked');
            $locked->update(['status' => 'completed', 'completed_at' => now()]);
            PaymentAuditLog::query()->create(['order_id' => $order->id, 'attempt_id' => $locked->attempt_id,
                'refund_id' => $locked->id, 'action' => 'robokassa_refund_confirmed', 'actor_type' => 'provider',
                'from_status' => 'paid', 'to_status' => 'refunded',
                'meta' => ['provider_request_id' => $locked->provider_refund_id, 'amount' => (int) $locked->amount]]);
            $this->outbox->publishTransition($order->fresh(), $locked->attempt_id, $locked, 'paid', 'refunded', ['source' => 'robokassa_refund_reconciliation']);

            return $locked;
        });
    }
}
