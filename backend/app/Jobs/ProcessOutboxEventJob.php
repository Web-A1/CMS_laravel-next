<?php

namespace App\Jobs;

use App\Models\Order;
use App\Models\OutboxEvent;
use App\Models\SideEffectExecution;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProcessOutboxEventJob implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly int $outboxEventId) {}

    public function handle(): void
    {
        try {
            $dispatchIds = DB::transaction(function (): array {
                $event = OutboxEvent::query()->lockForUpdate()->find($this->outboxEventId);
                if ($event === null || $event->status === 'processed') {
                    return [];
                }

                $payload = $event->payload ?? [];
                $from = (string) ($payload['from_status'] ?? '');
                $to = (string) ($payload['to_status'] ?? '');
                $accessStatus = (string) ($payload['access_status'] ?? 'not_granted');
                $orderId = (int) $event->order_id;
                $refundId = $event->refund_id ? (int) $event->refund_id : null;

                $effects = $this->effectsForTransition($orderId, $refundId, $from, $to, $accessStatus);
                $items = Order::query()->findOrFail($orderId)->items()->get();
                if ($items->count() === 1 && ($items->sole()->benefit_snapshot['purchase_bonus_policy_version'] ?? null) === 1) {
                    if ($refundId !== null && $to === 'refunded') {
                        $effects[] = $this->effect('bonus.purchase_refund', "order:{$orderId}:bonus:refund");
                    } elseif ($refundId === null && in_array($from, ['pending_payment', 'cancelled'], true) && $to === 'paid') {
                        $effects[] = $this->effect('bonus.purchase_reward', "order:{$orderId}:bonus:reward");
                    }
                }
                $createdEffects = 0;
                $dedupedEffects = 0;
                $dispatchIds = [];
                $legacyIds = [];
                foreach ($effects as $effect) {
                    $execution = SideEffectExecution::query()->firstOrCreate(
                        ['dedup_key' => $effect['dedup_key']],
                        [
                            'outbox_event_id' => $event->id,
                            'order_id' => $event->order_id,
                            'attempt_id' => $event->attempt_id,
                            'refund_id' => $event->refund_id,
                            'handler' => $effect['handler'],
                            'status' => 'pending',
                            'meta' => ['delivery_version' => 1],
                            'processed_at' => now(),
                        ]
                    );

                    if ($execution->wasRecentlyCreated) {
                        $createdEffects++;
                    } else {
                        $dedupedEffects++;
                    }
                    if (empty($execution->meta['delivery_version'])) {
                        $legacyIds[] = $execution->id;
                    } elseif ($execution->status !== 'completed') {
                        $dispatchIds[] = $execution->id;
                    }
                }

                $event->status = $dispatchIds === [] && $legacyIds === [] ? 'processed' : 'pending';
                $event->processed_at = $event->status === 'processed' ? now() : null;
                $event->payload = [...$payload, 'delivery_state' => $legacyIds === [] ? 'materialized' : 'legacy_unverified', 'legacy_unverified_effect_ids' => $legacyIds];
                $event->save();

                Log::info('payments.outbox.materialized', [
                    'outbox_event_id' => $event->id,
                    'event_type' => $event->event_type,
                    'retry_count' => (int) $event->retry_count,
                    'side_effects_created' => $createdEffects,
                    'side_effects_deduped' => $dedupedEffects,
                    'metrics' => [
                        'processed' => $event->status === 'processed' ? 1 : 0,
                        'failed' => 0,
                        'retried' => (int) $event->retry_count > 0 ? 1 : 0,
                    ],
                ]);

                return $dispatchIds;
            });
            foreach ($dispatchIds as $id) {
                ProcessPaymentSideEffectJob::dispatch($id)->afterCommit();
            }
        } catch (Throwable $e) {
            // Another worker may have completed after this transaction released its lock.
            $recorded = OutboxEvent::query()
                ->whereKey($this->outboxEventId)
                ->where('status', '!=', 'processed')
                ->increment('retry_count', 1, ['status' => 'failed']);

            Log::warning('payments.outbox.process_failed', [
                'outbox_event_id' => $this->outboxEventId,
                'error' => $e->getMessage(),
                'metrics' => [
                    'processed' => 0,
                    'failed' => $recorded,
                    'retried' => 0,
                ],
            ]);

            throw $e;
        }
    }

    private function effectsForTransition(int $orderId, ?int $refundId, string $from, string $to, string $accessStatus): array
    {
        if (in_array($from, ['pending_payment', 'cancelled'], true) && $to === 'paid' && $refundId === null) {
            return [
                $this->effect('entitlement.grant', "order:{$orderId}:to:paid:entitlement"),
                $this->effect('manager_task.create', "order:{$orderId}:to:paid:manager_task"),
                $this->effect('receipt.income.issue', "order:{$orderId}:receipt:income"),
                $this->effect('user_notification.payment_success', "order:{$orderId}:notify:payment_success"),
            ];
        }

        if ($refundId !== null && in_array($from, ['paid', 'refunded'], true) && in_array($to, ['paid', 'refunded'], true)) {
            return [
                $this->effect('entitlement.'.($accessStatus === 'revoked' ? 'revoke' : 'keep'), "order:{$orderId}:refund:{$refundId}:entitlement:{$accessStatus}"),
                $this->effect('receipt.refund.issue', "order:{$orderId}:refund:{$refundId}:receipt"),
                $this->effect('user_notification.refund_processed', "order:{$orderId}:refund:{$refundId}:notify"),
            ];
        }

        if ($from === 'pending_payment' && $to === 'cancelled') {
            return [
                $this->effect('user_notification.payment_cancelled', "order:{$orderId}:notify:payment_cancelled"),
            ];
        }

        return [];
    }

    private function effect(string $handler, string $dedupKey): array
    {
        return [
            'handler' => $handler,
            'dedup_key' => $dedupKey,
            'meta' => [],
        ];
    }
}
