<?php

namespace App\Services\Payments;

use App\Models\Order;
use App\Models\OrderSettlement;
use App\Models\OutboxEvent;
use App\Models\Refund;
use App\Models\SideEffectExecution;
use App\Models\UserEntitlementGrant;
use App\Services\AccessResolver;
use App\Services\Bonuses\OrderDiscountService;
use App\Services\OrderItemSnapshotService;
use DomainException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/** Confirms existing domain effects; never grants or revokes access. */
class EntitlementEffectEvidenceService
{
    public function __construct(private readonly AccessResolver $resolver) {}

    public function execute(int $effectId): void
    {
        $reference = SideEffectExecution::query()->find($effectId);
        if ($reference === null) {
            return;
        }
        DB::transaction(function () use ($reference, $effectId): void {
            $order = Order::query()->lockForUpdate()->find($reference->order_id);
            $effect = SideEffectExecution::query()->lockForUpdate()->find($effectId);
            if ($effect === null || $effect->status === 'completed') {
                return;
            }
            if (empty($effect->meta['delivery_version'])) {
                $effect->update(['status' => 'legacy_unverified']);

                return;
            }
            try {
                $evidence = $this->verify($order, $effect);
                $effect->update(['status' => 'completed', 'processed_at' => now(),
                    'meta' => [...$effect->meta, 'verified' => true, 'evidence_version' => 1,
                        'verified_at' => now()->toIso8601String(), 'evidence' => $evidence, 'diagnostic' => null]]);
            } catch (DomainException $exception) {
                $effect->update(['status' => $exception->getMessage() === 'ENTITLEMENT_GRANT_PENDING' ? 'awaiting_entitlement' : 'manual_review',
                    'meta' => [...$effect->meta, 'verified' => false, 'diagnostic' => $exception->getMessage()]]);
            }
        }, 3);
    }

    private function verify(?Order $order, SideEffectExecution $effect): array
    {
        $event = OutboxEvent::query()->find($effect->outbox_event_id);
        if ($order === null || (int) $effect->order_id !== (int) $order->id || $order->user_id === null
            || $event === null || $event->event_type !== 'payment.transition.applied'
            || (int) $event->order_id !== (int) $order->id
            || $event->attempt_id !== $effect->attempt_id || $event->refund_id !== $effect->refund_id
            || ! in_array($effect->handler, ['entitlement.grant', 'entitlement.keep', 'entitlement.revoke'], true)) {
            throw new DomainException('ENTITLEMENT_EFFECT_IDENTITY_MISMATCH');
        }
        $grantEffect = $effect->handler === 'entitlement.grant';
        $zeroCash = (int) $order->amount_paid === 0;
        $from = $event->payload['from_status'] ?? null;
        $to = $event->payload['to_status'] ?? null;
        $access = $event->payload['access_status'] ?? null;
        $refund = null;
        if ($grantEffect) {
            if ($from !== 'pending_payment' || $to !== 'paid' || $effect->refund_id !== null
                || $effect->dedup_key !== "order:{$order->id}:to:paid:entitlement") {
                throw new DomainException('ENTITLEMENT_EFFECT_IDENTITY_MISMATCH');
            }
        } else {
            $refund = Refund::query()->find($effect->refund_id);
            $policy = $effect->handler === 'entitlement.revoke' ? 'revoke' : 'keep';
            if ($refund === null || $refund->status !== 'completed' || $refund->completed_at === null
                || (int) $refund->order_id !== (int) $order->id || $refund->attempt_id !== $effect->attempt_id
                || $refund->policy_access_action !== $policy || $refund->currency !== $order->currency
                || ($zeroCash ? ((int) $refund->amount !== 0 || $refund->refund_type !== 'full' || $policy !== 'revoke') : (int) $refund->amount <= 0)
                || ! in_array($from, ['paid', 'refunded'], true) || ! in_array($to, ['paid', 'refunded'], true)
                || ($policy === 'revoke') !== ($access === 'revoked')
                || $effect->dedup_key !== "order:{$order->id}:refund:{$refund->id}:entitlement:{$access}") {
                throw new DomainException('ENTITLEMENT_REFUND_IDENTITY_MISMATCH');
            }
        }
        $settlements = OrderSettlement::query()->where('order_id', $order->id)->where('status', 'confirmed')->get();
        if ($settlements->count() !== 1) {
            throw new DomainException('ENTITLEMENT_SETTLEMENT_MISSING');
        }
        $settlement = $settlements->sole();
        if ($settlement->payment_attempt_id !== $effect->attempt_id || $settlement->confirmed_at === null
            || $settlement->currency !== $order->currency || (int) $settlement->amount !== (int) $order->amount_paid) {
            throw new DomainException('ENTITLEMENT_SETTLEMENT_MISMATCH');
        }
        if ($zeroCash) {
            $binding = app(OrderDiscountService::class)->binding($order);
            if ($settlement->settlement_type !== OrderSettlement::TYPE_SETTLED_ZERO_CASH
                || $settlement->payment_attempt_id !== null || $order->active_attempt_id !== null
                || (int) $order->amount_final !== 0 || $order->currency !== 'RUB'
                || $binding === null || $binding['status'] !== 'committed'
                || ! app(OrderDiscountService::class)->settlementEvidenceMatches($settlement, $binding)) {
                throw new DomainException('ENTITLEMENT_ZERO_CASH_NOT_PROVEN');
            }
        }
        if (($settlement->metadata['grant_status'] ?? null) === 'pending') {
            throw new DomainException('ENTITLEMENT_GRANT_PENDING');
        }
        if (($settlement->metadata['grant_status'] ?? null) !== 'granted') {
            throw new DomainException('ENTITLEMENT_GRANT_NOT_PROVEN');
        }
        $items = $order->items()->orderBy('id')->get();
        $grants = UserEntitlementGrant::query()->where('order_id', $order->id)->where('source_type', 'order_item')->orderBy('id')->get();
        if ($items->isEmpty() || $grants->isEmpty()) {
            throw new DomainException('ENTITLEMENT_GRANTS_MISSING');
        }
        $matched = [];
        foreach ($items as $item) {
            if (! hash_equals((string) $item->snapshot_hash, OrderItemSnapshotService::snapshotHash($item->getAttributesForSnapshot()))) {
                throw new DomainException('ENTITLEMENT_ITEM_SNAPSHOT_MISMATCH');
            }
            $atoms = $item->grant_snapshot['atoms'] ?? null;
            if (! is_array($atoms) || $atoms === []) {
                throw new DomainException('ENTITLEMENT_ITEM_ATOMS_INVALID');
            }
            try {
                $atoms = $this->resolver->normalizeAtoms($atoms);
            } catch (InvalidArgumentException) {
                throw new DomainException('ENTITLEMENT_ITEM_ATOMS_INVALID');
            }
            foreach ($atoms as $atom) {
                $matches = $grants->filter(fn ($grant) => (int) $grant->order_item_id === (int) $item->id
                    && $grant->atom_kind === $atom['kind'] && (int) $grant->atom_id === $atom['id']);
                if ($matches->count() !== 1) {
                    throw new DomainException('ENTITLEMENT_GRANT_ATOM_MISMATCH');
                }
                $grant = $matches->sole();
                if ((int) $grant->user_id !== (int) $order->user_id || $grant->source_id !== "order_item:{$item->id}"
                    || $grant->idempotency_key !== "order_item:{$item->id}:grant:v1" || $grant->granted_at === null
                    || $grant->granted_at->lessThan($settlement->confirmed_at)
                    || ($refund !== null && $grant->granted_at->greaterThan($refund->completed_at))
                    || (int) ($grant->metadata['settlement_id'] ?? 0) !== (int) $settlement->id
                    || ($grant->metadata['snapshot_hash'] ?? null) !== $item->snapshot_hash) {
                    throw new DomainException('ENTITLEMENT_GRANT_SOURCE_MISMATCH');
                }
                $matched[] = (int) $grant->id;
            }
        }
        if (count($matched) !== $grants->count()) {
            throw new DomainException('ENTITLEMENT_GRANT_ATOM_MISMATCH');
        }
        $revoked = $order->payment_status === 'refunded' && $order->access_status === 'revoked';
        $refundIds = [];
        if ($revoked) {
            $refunds = Refund::query()->where('order_id', $order->id)->where('status', 'completed')->orderBy('id')->get();
            $terminalRefunds = $refunds->where('policy_access_action', 'revoke');
            $terminalRefund = $terminalRefunds->count() === 1 ? $terminalRefunds->sole() : null;
            if ($refunds->isEmpty() || (! $zeroCash && (int) $order->amount_paid <= 0)
                || ($zeroCash && ($refunds->count() !== 1 || $refunds->sole()->refund_type !== 'full'))
                || (int) $refunds->sum('amount') !== (int) $order->amount_paid
                || (int) $order->amount_refunded !== (int) $order->amount_paid
                || $refunds->contains(fn ($row) => $row->currency !== $order->currency || ($zeroCash ? (int) $row->amount !== 0 : (int) $row->amount <= 0)
                    || $row->completed_at === null || $row->attempt_id !== $settlement->payment_attempt_id)
                || $terminalRefund === null || $terminalRefund->completed_at === null
                || $refunds->contains(fn ($row) => $row->completed_at->greaterThan($terminalRefund->completed_at))
                || ($effect->handler === 'entitlement.revoke' && (int) $terminalRefund->id !== (int) $refund->id)
                || ($effect->handler === 'entitlement.keep' && (int) $terminalRefund->id === (int) $refund->id)) {
                throw new DomainException('ENTITLEMENT_FULL_REFUND_NOT_PROVEN');
            }
            foreach ($grants as $grant) {
                if ($grant->status !== 'revoked' || $grant->revoked_at === null
                    || ! str_starts_with((string) $grant->revoked_reason, 'refund:')
                    || $grant->revoked_at->lessThan($grant->granted_at)
                    || $grant->revoked_at->greaterThan($terminalRefund->completed_at)
                    || ($effect->handler === 'entitlement.keep' && $grant->revoked_at->lessThan($refund->completed_at))) {
                    throw new DomainException('ENTITLEMENT_REVOCATION_NOT_PROVEN');
                }
            }
            if ($items->contains(fn ($item) => $item->access_status !== 'revoked')) {
                throw new DomainException('ENTITLEMENT_REVOCATION_NOT_PROVEN');
            }
            $refundIds = $refunds->pluck('id')->all();
        } else {
            if ($effect->handler === 'entitlement.revoke' || $order->payment_status !== 'paid' || $order->access_status !== 'granted'
                || $items->contains(fn ($item) => $item->access_status !== 'granted')
                || $grants->contains(fn ($grant) => $grant->status !== 'active' || $grant->revoked_at !== null
                    || ($grant->expires_at !== null && $grant->expires_at->lessThanOrEqualTo(now())))) {
                throw new DomainException('ENTITLEMENT_ACTIVE_ACCESS_NOT_PROVEN');
            }
            if ($refund !== null && ((int) $order->amount_refunded < (int) $refund->amount
                || (int) $order->amount_refunded >= (int) $order->amount_paid)) {
                throw new DomainException('ENTITLEMENT_PARTIAL_REFUND_NOT_PROVEN');
            }
            if ($refund !== null) {
                $refunds = Refund::query()->where('order_id', $order->id)->where('status', 'completed')->get();
                if ((int) $refunds->sum('amount') !== (int) $order->amount_refunded
                    || $refunds->contains(fn ($row) => $row->currency !== $order->currency || (int) $row->amount <= 0
                        || $row->completed_at === null || $row->attempt_id !== $settlement->payment_attempt_id
                        || $row->policy_access_action !== 'keep')) {
                    throw new DomainException('ENTITLEMENT_PARTIAL_REFUND_NOT_PROVEN');
                }
                $refundIds = $refunds->pluck('id')->all();
            }
        }

        return ['settlement_id' => (int) $settlement->id, 'grant_ids' => $matched, 'refund_ids' => $refundIds,
            'refund_id' => $refund?->id, 'outcome' => $revoked
                ? ($grantEffect ? 'historical_grant_verified_then_refunded' : ($effect->handler === 'entitlement.keep' ? 'keep_superseded_by_full_refund' : 'revocation_verified'))
                : ($grantEffect ? 'grant_verified' : 'keep_verified')];
    }
}
