<?php

namespace App\Services\Payments;

use App\Models\Order;
use App\Models\OrderSettlement;
use App\Models\PaymentAttempt;
use App\Models\PaymentAuditLog;
use App\Models\Refund;
use App\Models\User;
use App\Models\UserEntitlementGrant;
use App\Services\Bonuses\OrderDiscountService;
use App\Services\EntitlementGrantService;
use App\Services\PaymentStateMachine;
use DomainException;
use Illuminate\Support\Facades\DB;

class ZeroCashRefundService
{
    public function __construct(
        private readonly OrderDiscountService $pricing,
        private readonly EntitlementGrantService $entitlements,
        private readonly PaymentStateMachine $states,
        private readonly PaymentOutboxService $outbox,
    ) {}

    /** Authorization belongs to the existing manual-refund controller permission. */
    public function request(Order $order, array $input, string $key, int $actorId): Refund
    {
        if (! preg_match('/^[A-Za-z0-9:_-]{1,128}$/D', $key)) {
            throw new DomainException('PAYMENT_REFUND_IDEMPOTENCY_KEY_REQUIRED');
        }
        if (($input['refund_type'] ?? null) !== 'full' || ($input['policy_access_action'] ?? null) !== 'revoke'
            || (isset($input['amount']) && $input['amount'] !== 0) || ! is_string($input['reason'] ?? null)) {
            throw new DomainException('PAYMENT_REFUND_WHOLE_SERVICE_REQUIRED');
        }
        if ($actorId < 1 || ! User::query()->whereKey($actorId)->exists()) {
            throw new DomainException('PAYMENT_REFUND_ACTOR_INVALID');
        }

        return DB::transaction(function () use ($order, $input, $key, $actorId): Refund {
            $locked = Order::query()->lockForUpdate()->findOrFail($order->id);
            $existing = Refund::query()->where('request_key', $key)->first();
            if ($existing) {
                if ((int) $existing->order_id !== (int) $locked->id || $existing->refund_type !== 'full'
                    || $existing->policy_access_action !== 'revoke' || $existing->reason !== $input['reason']
                    || (int) $existing->amount !== 0 || $existing->attempt_id !== null || $existing->status !== 'completed') {
                    throw new DomainException('PAYMENT_REFUND_IDEMPOTENCY_CONFLICT');
                }

                return $existing;
            }
            if ($locked->payment_status !== 'paid' || $locked->access_status !== 'granted' || $locked->user_id === null
                || $locked->currency !== 'RUB' || (int) $locked->amount_final !== 0 || (int) $locked->amount_paid !== 0
                || (int) $locked->amount_refunded !== 0 || $locked->active_attempt_id !== null
                || PaymentAttempt::query()->where('order_id', $locked->id)->exists()
                || Refund::query()->where('order_id', $locked->id)->exists()) {
                throw new DomainException('PAYMENT_ZERO_CASH_REFUND_EVIDENCE_REQUIRED');
            }
            User::query()->lockForUpdate()->findOrFail($locked->user_id);
            $items = $locked->items()->lockForUpdate()->get();
            if ($items->count() !== 1 || (int) $items->sole()->quantity !== 1
                || ! in_array($items->sole()->sellable_kind, ['course', 'lesson'], true)
                || $items->sole()->access_status !== 'granted') {
                throw new DomainException('PAYMENT_REFUND_WHOLE_SERVICE_REQUIRED');
            }
            $binding = $this->pricing->binding($locked);
            $settlements = OrderSettlement::query()->where('order_id', $locked->id)->where('status', 'confirmed')->get();
            $settlement = $settlements->count() === 1 ? $settlements->sole() : null;
            if ($binding === null || $binding['status'] !== 'committed' || $settlement === null
                || $settlement->settlement_type !== OrderSettlement::TYPE_SETTLED_ZERO_CASH || (int) $settlement->amount !== 0
                || $settlement->currency !== 'RUB' || $settlement->payment_attempt_id !== null || $settlement->confirmed_at === null
                || ! $this->pricing->settlementEvidenceMatches($settlement, $binding)
                || ($settlement->metadata['grant_status'] ?? null) !== 'granted') {
                throw new DomainException('PAYMENT_ZERO_CASH_REFUND_EVIDENCE_REQUIRED');
            }
            $item = $items->sole();
            $grants = UserEntitlementGrant::query()->where('order_id', $locked->id)->where('source_type', 'order_item')->lockForUpdate()->get();
            $atoms = $item->grant_snapshot['atoms'] ?? [];
            if ($atoms === [] || count($atoms) !== $grants->count()
                || $grants->contains(fn ($grant) => (int) $grant->user_id !== (int) $locked->user_id
                    || (int) $grant->order_item_id !== (int) $item->id || $grant->status !== 'active'
                    || $grant->source_id !== "order_item:{$item->id}"
                    || ($grant->metadata['settlement_id'] ?? null) !== $settlement->id
                    || ($grant->metadata['snapshot_hash'] ?? null) !== $item->snapshot_hash)
                || collect($atoms)->contains(fn ($atom) => $grants->filter(fn ($grant) => $grant->atom_kind === $atom['kind']
                    && (string) $grant->atom_id === (string) $atom['id'])->count() !== 1)) {
                throw new DomainException('PAYMENT_ZERO_CASH_REFUND_GRANT_EVIDENCE_REQUIRED');
            }
            $refund = Refund::query()->createOrFirst(['request_key' => $key], ['order_id' => $locked->id,
                'refund_type' => 'full', 'amount' => 0, 'currency' => 'RUB', 'status' => 'pending',
                'reason' => $input['reason'], 'policy_access_action' => 'revoke', 'created_by' => $actorId]);
            if (! $refund->wasRecentlyCreated) {
                throw new DomainException('PAYMENT_REFUND_IDEMPOTENCY_CONFLICT');
            }
            $this->entitlements->revokeOrderGrants((int) $locked->user_id, (int) $locked->id, [$item->id], 'refund:zero_cash');
            $item->update(['access_status' => 'revoked']);
            $this->states->transition($locked, 'refunded', 'revoked');
            $refund->update(['status' => 'completed', 'completed_at' => now()]);
            PaymentAuditLog::query()->create(['order_id' => $locked->id, 'refund_id' => $refund->id,
                'action' => 'zero_cash_refund_confirmed', 'actor_type' => 'admin', 'actor_id' => $actorId,
                'from_status' => 'paid', 'to_status' => 'refunded', 'meta' => ['settlement_id' => $settlement->id,
                    'reservation_id' => $binding['reservation_id'], 'proof_hash' => $binding['proof']['proof_hash'], 'amount' => 0]]);
            $this->outbox->publishTransition($locked->fresh(), null, $refund, 'paid', 'refunded', ['source' => 'zero_cash_refund']);

            return $refund;
        }, 3);
    }
}
