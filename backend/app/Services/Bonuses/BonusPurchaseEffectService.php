<?php

namespace App\Services\Bonuses;

use App\Jobs\ProcessPaymentSideEffectJob;
use App\Models\Order;
use App\Models\OutboxEvent;
use App\Models\SideEffectExecution;
use App\Services\OrderItemSnapshotService;
use DomainException;
use Illuminate\Support\Facades\DB;

class BonusPurchaseEffectService
{
    public function execute(int $id): void
    {
        $reference = SideEffectExecution::query()->findOrFail($id);
        try {
            DB::transaction(function () use ($id, $reference): void {
                $order = Order::query()->lockForUpdate()->findOrFail($reference->order_id);
                $effect = SideEffectExecution::query()->lockForUpdate()->findOrFail($id);
                if ($effect->status === 'completed') {
                    return;
                }
                $event = OutboxEvent::query()->findOrFail($effect->outbox_event_id);
                $items = $order->items()->get();
                $refund = $effect->handler === 'bonus.purchase_refund';
                $kind = $refund ? 'refund' : 'reward';
                if (! in_array($effect->handler, ['bonus.purchase_reward', 'bonus.purchase_refund'], true)
                    || (int) $effect->order_id !== (int) $order->id || (int) $event->order_id !== (int) $order->id
                    || $effect->refund_id !== $event->refund_id || $effect->attempt_id !== $event->attempt_id
                    || ($effect->meta['delivery_version'] ?? null) !== 1
                    || $effect->dedup_key !== "order:{$order->id}:bonus:{$kind}"
                    || ($event->payload['to_status'] ?? null) !== ($refund ? 'refunded' : 'paid')
                    || ($refund ? $effect->refund_id === null : $effect->refund_id !== null)
                    || $items->count() !== 1 || ($items->sole()->benefit_snapshot['purchase_bonus_policy_version'] ?? null) !== 1
                    || $items->sole()->snapshot_hash !== OrderItemSnapshotService::snapshotHash($items->sole()->toArray())) {
                    throw new DomainException('BONUS_EFFECT_EVIDENCE_INVALID');
                }
                $settlement = app(PurchaseRewardService::class)->confirmedSettlement($order);
                if (($settlement->metadata['grant_status'] ?? null) !== 'granted') {
                    throw new DomainException('BONUS_ACCESS_CONFIRMATION_REQUIRED');
                }
                $results = $refund
                    ? [app(PurchaseBonusRefundService::class)->apply((int) $effect->refund_id),
                        app(AutoPurchasePromoService::class)->revokeForRefund((int) $effect->refund_id)]
                    : [app(PurchaseRewardService::class)->apply((int) $order->id),
                        app(AutoPurchasePromoService::class)->apply((int) $order->id)];
                $needsReview = collect($results)->contains(fn (array $result): bool => ($result['status'] ?? null) === 'promotion_violation_review');
                $effect->forceFill(['status' => $needsReview ? 'manual_review' : 'completed', 'processed_at' => now(),
                    'meta' => [...($effect->meta ?? []), 'verified' => ! $needsReview, 'results' => $results,
                        'diagnostic' => $needsReview ? 'BONUS_USED_PROMO_REFUND_REVIEW' : null]])->save();
                if (! $refund && ($results[1]['status'] ?? null) === 'issued') {
                    $notification = SideEffectExecution::query()->firstOrCreate([
                        'dedup_key' => "order:{$order->id}:notify:purchase_promo",
                    ], ['outbox_event_id' => $event->id, 'order_id' => $order->id,
                        'attempt_id' => $effect->attempt_id, 'refund_id' => null,
                        'handler' => 'user_notification.purchase_promo', 'status' => 'pending',
                        'meta' => ['delivery_version' => 1, 'promo_code_id' => $results[1]['promo_code_id']],
                        'processed_at' => now()]);
                    ProcessPaymentSideEffectJob::dispatch($notification->id)->afterCommit();
                }
            }, 3);
        } catch (DomainException $exception) {
            SideEffectExecution::query()->whereKey($id)->where('status', '!=', 'completed')->update([
                'status' => 'manual_review',
                'meta' => [...($reference->meta ?? []), 'diagnostic' => $exception->getMessage()],
            ]);
        }
    }
}
