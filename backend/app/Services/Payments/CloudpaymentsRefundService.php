<?php

namespace App\Services\Payments;

use App\Models\Order;
use App\Models\PaymentAuditLog;
use App\Models\Refund;
use DomainException;
use Illuminate\Support\Facades\DB;

class CloudpaymentsRefundService
{
    public function __construct(private readonly PaymentProviderRegistry $registry) {}

    /** @param array{refund_type:string,amount?:?int,reason:string,policy_access_action:string} $input */
    public function request(Order $order, array $input, string $key, ?int $actorId): Refund
    {
        if (! preg_match('/^[a-zA-Z0-9-]{16,64}$/D', $key)) {
            throw new DomainException('PAYMENT_REFUND_IDEMPOTENCY_KEY_REQUIRED');
        }
        [$refund, $transactionId, $created] = DB::transaction(function () use ($order, $input, $key, $actorId): array {
            $locked = Order::query()->lockForUpdate()->findOrFail($order->id);
            $existing = Refund::query()->where('request_key', $key)->first();
            if ($existing) {
                if ((int) $existing->order_id !== (int) $locked->id
                    || $existing->refund_type !== $input['refund_type']
                    || $existing->policy_access_action !== $input['policy_access_action']
                    || $existing->reason !== $input['reason']
                    || ($input['refund_type'] === 'partial' && (int) $existing->amount !== (int) ($input['amount'] ?? 0))) {
                    throw new DomainException('PAYMENT_REFUND_IDEMPOTENCY_CONFLICT');
                }

                return [$existing, null, false];
            }
            if (! in_array($locked->payment_status, ['paid', 'refunded'], true)) {
                throw new DomainException('PAYMENT_REFUND_FORBIDDEN');
            }
            $attempt = $locked->activeAttempt()->lockForUpdate()->first();
            if (! $attempt || $attempt->provider !== 'cloudpayments' || ! $attempt->provider_payment_id) {
                throw new DomainException('PAYMENT_REFUND_PAYMENT_MISSING');
            }
            if ($input['policy_access_action'] !== 'revoke') {
                throw new DomainException('PAYMENT_REFUND_ACCESS_POLICY_UNSUPPORTED');
            }
            if ($input['refund_type'] !== 'full') {
                throw new DomainException('PAYMENT_REFUND_WHOLE_SERVICE_REQUIRED');
            }
            if (Refund::query()->where('order_id', $locked->id)
                ->whereIn('status', ['pending', 'submitted', 'unknown'])->exists()) {
                throw new DomainException('PAYMENT_REFUND_CONFIRMATION_PENDING');
            }
            $remaining = (int) $locked->amount_paid - (int) $locked->amount_refunded;
            $amount = $input['refund_type'] === 'full' ? $remaining : (int) ($input['amount'] ?? 0);
            if ($amount < 1 || $amount > $remaining) {
                throw new DomainException('PAYMENT_REFUND_AMOUNT_EXCEEDED');
            }
            if (! $this->registry->forProvider('cloudpayments')
                || trim((string) config('services.payments.providers.cloudpayments.public_id', '')) === '') {
                throw new DomainException('PAYMENT_CLOUDPAYMENTS_CREDENTIALS_MISSING');
            }
            $refund = Refund::query()->createOrFirst(['request_key' => $key], [
                'order_id' => $locked->id,
                'attempt_id' => $attempt->id,
                'refund_type' => $input['refund_type'],
                'amount' => $amount,
                'currency' => $locked->currency,
                'status' => 'pending',
                'reason' => $input['reason'],
                'policy_access_action' => 'revoke',
                'created_by' => $actorId,
            ]);
            if (! $refund->wasRecentlyCreated) {
                // Another order can race for the globally unique request key while
                // holding a different order lock. Never leak an SQL exception/500.
                throw new DomainException('PAYMENT_REFUND_IDEMPOTENCY_CONFLICT');
            }
            PaymentAuditLog::query()->create([
                'order_id' => $locked->id, 'attempt_id' => $attempt->id, 'refund_id' => $refund->id,
                'action' => 'refund_requested', 'from_status' => $locked->payment_status,
                'to_status' => $locked->payment_status, 'actor_type' => $actorId === null ? 'system' : 'staff', 'actor_id' => $actorId,
                'reason' => $input['reason'], 'meta' => ['amount' => $amount, 'provider' => 'cloudpayments'],
            ]);

            return [$refund, (string) $attempt->provider_payment_id, true];
        }, 3);

        if (! $created) {
            return $refund;
        }
        // The reservation is durable before network I/O. A crash/timeout freezes new
        // requests until reconciliation; replaying this key never sends money twice.
        try {
            $result = $this->registry->forProvider('cloudpayments')?->refund([
                'provider_payment_id' => $transactionId,
                'amount' => (int) $refund->amount,
                'request_key' => $key,
            ]);
            if (! $result) {
                throw new DomainException('PAYMENT_REFUND_OUTCOME_UNKNOWN');
            }
        } catch (DomainException $exception) {
            $status = $exception->getMessage() === 'PAYMENT_REFUND_PROVIDER_REJECTED' ? 'failed' : 'unknown';
            Refund::query()->whereKey($refund->id)->where('status', 'pending')->update(['status' => $status]);

            return $refund->fresh();
        }

        return DB::transaction(function () use ($refund, $result): Refund {
            Order::query()->lockForUpdate()->findOrFail($refund->order_id);
            $locked = Refund::query()->lockForUpdate()->findOrFail($refund->id);
            if ($locked->provider_refund_id !== null && $locked->provider_refund_id !== $result['provider_refund_id']) {
                throw new DomainException('PAYMENT_REFUND_PROVIDER_IDENTITY_CONFLICT');
            }
            if ($locked->status !== 'completed') {
                $locked->update(['provider_refund_id' => $result['provider_refund_id'], 'status' => 'submitted']);
            }
            PaymentAuditLog::query()->create([
                'order_id' => $locked->order_id, 'attempt_id' => $locked->attempt_id, 'refund_id' => $locked->id,
                'action' => 'refund_provider_submitted', 'actor_type' => 'provider',
                'meta' => ['transaction_id' => $result['provider_refund_id']],
            ]);

            return $locked;
        });
    }
}
