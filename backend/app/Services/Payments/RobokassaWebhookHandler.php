<?php

namespace App\Services\Payments;

use App\Models\Order;
use App\Models\OrderSettlement;
use App\Models\PaymentAttempt;
use App\Models\ProviderEvent;
use App\Services\OrderSettlementService;
use DomainException;
use Illuminate\Support\Facades\DB;

class RobokassaWebhookHandler
{
    public function __construct(private readonly OrderSettlementService $settlements, private readonly PaymentOutboxService $outbox) {}

    /** Only ResultURL: success/fail browser redirects must never call this handler. */
    public function handle(string $rawBody): string
    {
        $adapter = new RobokassaPaymentProviderAdapter('robokassa', '');
        if (! $adapter->verifyWebhookSignature($rawBody, null)) {
            throw new DomainException('ROBOKASSA_SIGNATURE_INVALID');
        }
        $fields = $adapter->decode($rawBody);
        $event = $adapter->parseWebhookEvent($fields);

        return DB::transaction(function () use ($fields, $event): string {
            $order = Order::query()->lockForUpdate()->find($event['order_id']);
            $attempt = PaymentAttempt::query()->lockForUpdate()->find($event['attempt_id']);
            $custom = array_filter($fields, fn ($key) => str_starts_with($key, 'Shp_'), ARRAY_FILTER_USE_KEY);
            ksort($custom);
            $expected = $attempt?->provider_substatus['custom'] ?? [];
            ksort($expected);
            if ($order === null || $attempt === null || (int) $attempt->order_id !== $order->id
                || $attempt->provider !== 'robokassa' || (string) $attempt->provider_payment_id !== $fields['InvId']
                || (int) $order->active_attempt_id !== $attempt->id || $custom !== $expected
                || ($attempt->provider_substatus['merchant_login'] ?? null) !== config('robokassa.merchant_login')
                || ($attempt->provider_substatus['test_mode'] ?? null) !== (bool) config('robokassa.test_mode')
                || $event['amount'] !== (int) $attempt->amount || $event['amount'] !== (int) $order->amount_final
                || $attempt->currency !== 'RUB' || $order->currency !== 'RUB') {
                throw new DomainException('ROBOKASSA_PAYMENT_MISMATCH');
            }
            $envelope = ['invoice' => $fields['InvId'], 'amount' => $event['amount'], 'custom' => $custom];
            $stored = ProviderEvent::query()->createOrFirst(['provider' => 'robokassa', 'provider_event_id' => $event['provider_event_id']],
                ['event_type' => 'robokassa.result', 'order_id' => $order->id, 'attempt_id' => $attempt->id,
                    'signature_valid' => true, 'processed' => false, 'payload_raw' => $envelope, 'received_at' => now()]);
            if (! $stored->wasRecentlyCreated) {
                if ($stored->payload_raw !== $envelope || ! $stored->processed) {
                    throw new DomainException('ROBOKASSA_EVENT_CONFLICT');
                }

                return 'OK'.$fields['InvId'];
            }
            $from = $order->payment_status;
            $this->settlements->confirmPaid($order, ['idempotency_key' => 'robokassa:'.$fields['InvId'],
                'amount' => $event['amount'], 'currency' => 'RUB', 'settlement_type' => OrderSettlement::TYPE_PROVIDER_PAYMENT,
                'payment_attempt_id' => $attempt->id, 'provider_event_id' => $stored->id,
                'metadata' => ['provider' => 'robokassa', 'invoice_id' => $fields['InvId']]]);
            $attempt->update(['status' => 'completed', 'completed_at' => now()]);
            $order->refresh();
            if ($from !== $order->payment_status) {
                $this->outbox->publishTransition($order, $attempt->id, null, $from, $order->payment_status, ['source' => 'robokassa_result']);
            }
            $stored->update(['processed' => true, 'processed_at' => now()]);

            return 'OK'.$fields['InvId'];
        });
    }
}
