<?php

namespace App\Services\Payments;

use App\Jobs\ProcessOutboxEventJob;
use App\Models\Order;
use App\Models\OutboxEvent;
use App\Models\Refund;
use Illuminate\Support\Facades\DB;

class PaymentOutboxService
{
    public function publishTransition(
        Order $order,
        ?int $attemptId,
        ?Refund $refund,
        string $fromStatus,
        string $toStatus,
        array $meta = []
    ): OutboxEvent {
        $event = OutboxEvent::query()->create([
            'event_type' => 'payment.transition.applied',
            'order_id' => $order->id,
            'attempt_id' => $attemptId,
            'refund_id' => $refund?->id,
            'status' => 'pending',
            'payload' => [
                'from_status' => $fromStatus,
                'to_status' => $toStatus,
                'access_status' => $order->access_status,
                'meta' => $meta,
            ],
            'available_at' => now(),
        ]);

        DB::afterCommit(static function () use ($event): void {
            ProcessOutboxEventJob::dispatch($event->id);
        });

        return $event;
    }
}
