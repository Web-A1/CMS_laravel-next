<?php

namespace App\Jobs;

use App\Models\OutboxEvent;
use App\Models\SideEffectExecution;
use App\Services\Bonuses\BonusPurchaseEffectService;
use App\Services\Payments\EntitlementEffectEvidenceService;
use App\Services\Payments\FiscalReceiptService;
use App\Services\Payments\PaymentNotificationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessPaymentSideEffectJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly int $executionId) {}

    public function handle(FiscalReceiptService $service): void
    {
        $effect = SideEffectExecution::query()->find($this->executionId);
        if ($effect === null) {
            return;
        }
        if ($effect->status === 'completed') {
            // Legacy synthetic markers require explicit audited reconciliation, never reissue.
            return;
        }
        if (str_starts_with($effect->handler, 'receipt.')) {
            $service->execute($effect->id);
            $effect->refresh();
            if (in_array($effect->status, ['submitted', 'waiting_income_receipt'], true)) {
                $connection = $this->connection ?? config('queue.default');
                if (in_array(config('queue.connections.'.$connection.'.driver'), ['database', 'redis', 'sqs', 'beanstalkd'], true)) {
                    self::dispatch($effect->id)->onConnection($connection)->onQueue($this->queue)->delay(now()->addMinute());
                }
                // Sync queues ignore delays. Keep submitted; the existing
                // payments:outbox:retry --event-id command resumes polling.
            }
        } elseif (in_array($effect->handler, ['bonus.purchase_reward', 'bonus.purchase_refund'], true)) {
            app(BonusPurchaseEffectService::class)->execute($effect->id);
        } elseif (str_starts_with($effect->handler, 'user_notification.')) {
            app(PaymentNotificationService::class)->execute($effect->id);
        } elseif (in_array($effect->handler, ['entitlement.grant', 'entitlement.revoke', 'entitlement.keep'], true)) {
            app(EntitlementEffectEvidenceService::class)->execute($effect->id);
        } else {
            $effect->forceFill(['status' => 'blocked_handler'])->save();
        }
        $event = OutboxEvent::query()->find($effect->outbox_event_id);
        if ($event !== null && empty($event->payload['legacy_unverified_effect_ids'])
            && ! SideEffectExecution::query()->where('outbox_event_id', $effect->outbox_event_id)->where('status', '!=', 'completed')->exists()) {
            OutboxEvent::query()->whereKey($effect->outbox_event_id)->update(['status' => 'processed', 'processed_at' => now()]);
        }
    }
}
