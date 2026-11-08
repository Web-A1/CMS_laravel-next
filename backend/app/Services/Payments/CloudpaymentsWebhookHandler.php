<?php

namespace App\Services\Payments;

use App\Models\Order;
use App\Models\OrderSettlement;
use App\Models\PaymentAuditLog;
use App\Models\ProviderEvent;
use App\Models\Refund;
use App\Services\Bonuses\OrderDiscountService;
use App\Services\EntitlementGrantService;
use App\Services\OrderSettlementService;
use App\Services\PaymentStateMachine;
use Illuminate\Support\Facades\DB;

class CloudpaymentsWebhookHandler
{
    public function __construct(
        private readonly PaymentStateMachine $stateMachine,
        private readonly PaymentOutboxService $outbox,
        private readonly OrderSettlementService $settlements,
        private readonly EntitlementGrantService $entitlements,
    ) {}

    /** The caller must authenticate the signed body before passing it here.
     * @param  array<string, mixed>  $payload
     */
    public function handle(string $type, array $payload, string $source = 'webhook'): int
    {
        $invoice = $payload['InvoiceId'] ?? null;
        if (! is_string($invoice) && ! is_int($invoice)) {
            return 10;
        }
        // Numeric invoices belong to pre-widget attempts only.
        if (! preg_match('/^(?:cp-)?([1-9][0-9]{0,17})(?:-([1-9][0-9]{0,17}))?$/D', (string) $invoice, $parts)
            || (str_starts_with((string) $invoice, 'cp-') !== isset($parts[2]))) {
            return 10;
        }
        $orderId = (int) $parts[1];
        $attemptId = isset($parts[2]) ? (int) $parts[2] : null;
        $transactionId = $this->transactionId($payload['TransactionId'] ?? null);
        $amount = $this->amountCents($payload['Amount'] ?? null);
        $currency = $payload['Currency'] ?? null;
        if ($amount === null || ! is_string($currency) || ! preg_match('/^[A-Z]{3}$/D', $currency)) {
            return 12;
        }
        if ($transactionId === null) {
            return 13;
        }
        if ($type !== 'refund') {
            $testMode = $payload['TestMode'] ?? null;
            if (! in_array($testMode, [0, 1, '0', '1'], true)
                || (bool) (int) $testMode !== (bool) config('services.payments.providers.cloudpayments.test_mode', false)
                || ($payload['OperationType'] ?? null) !== 'Payment'
                || (in_array($type, ['check', 'pay'], true) && ($payload['Status'] ?? null) !== 'Completed')) {
                return 13;
            }
        } elseif (($payload['OperationType'] ?? null) !== 'Refund') {
            return 13;
        }

        // A failed asynchronous SBP refund is not a money return.
        if ($type === 'refund' && (array_key_exists('Status', $payload) && $payload['Status'] !== 'Completed'
            || ($payload['PaymentMethod'] ?? null) === 'Sbp' && ($payload['Status'] ?? null) !== 'Completed')) {
            return 13;
        }

        if ($type === 'fail' && $this->transactionId($payload['ReasonCode'] ?? null) === null) {
            return 13;
        }

        $originalTransactionId = $type === 'refund'
            ? $this->transactionId($payload['PaymentTransactionId'] ?? null)
            : $transactionId;
        if ($originalTransactionId === null) {
            return 13;
        }

        return DB::transaction(function () use ($type, $invoice, $orderId, $attemptId, $transactionId, $originalTransactionId, $amount, $currency, $payload, $source): int {
            $order = Order::query()->whereKey($orderId)->lockForUpdate()->first();
            if (! $order) {
                return 10;
            }
            $attempt = $order->activeAttempt()->lockForUpdate()->first();
            if (! $attempt || $attempt->provider !== 'cloudpayments'
                || ($attemptId !== null && (int) $attempt->id !== $attemptId)) {
                return 13;
            }
            $substatus = $attempt->provider_substatus ?? [];
            if (($substatus['mode'] ?? null) === 'widget'
                && (string) $invoice !== ($substatus['invoice_id'] ?? null)) {
                return 13;
            }
            $expectedAmount = (int) $order->amount_final;
            if ($currency !== $order->currency || $currency !== $attempt->currency
                || ($type !== 'refund' && ($amount !== $expectedAmount || $amount !== (int) $attempt->amount))) {
                return 12;
            }
            if ($attempt->provider_payment_id !== null
                && (string) $attempt->provider_payment_id !== $originalTransactionId) {
                return 13;
            }
            if ($type === 'check') {
                try {
                    app(OrderDiscountService::class)->assertCanStartPayment($order);
                } catch (\DomainException) {
                    return 20;
                }
                if ($order->payment_status !== 'pending_payment' || $attempt->status !== 'pending') {
                    return 13;
                }
                if ($attempt->expires_at?->isPast()) {
                    return 20;
                }
                // Reserve the transaction before charging, preventing two open forms
                // from authorizing different payments for the same attempt.
                if ($attempt->provider_payment_id === null) {
                    $attempt->provider_payment_id = $transactionId;
                    $attempt->save();
                }

                return 0;
            }

            // Keep only the stable financial envelope, never card details or tokens.
            $envelope = [
                'invoice_id' => (string) $invoice,
                'transaction_id' => $transactionId,
                'payment_transaction_id' => $originalTransactionId,
                'amount' => $amount,
                'currency' => $currency,
                'test_mode' => $type === 'refund' ? null : (int) $payload['TestMode'],
            ];
            $eventKey = $type.':'.$transactionId;
            $existing = ProviderEvent::query()->where('provider', 'cloudpayments')
                ->where('provider_event_id', $eventKey)->lockForUpdate()->first();
            if ($existing) {
                return (int) $existing->order_id === (int) $order->id
                    && (int) $existing->attempt_id === (int) $attempt->id
                    && $existing->payload_raw === $envelope && $existing->processed ? 0 : 13;
            }
            if ($type === 'pay' && ! $this->stateMachine->canTransition($order->payment_status, 'paid')
                && ! ($order->payment_status === 'cancelled' && app(OrderDiscountService::class)->hasPricingRecord($order))) {
                return 13;
            }
            $refund = null;
            if ($type === 'refund') {
                if (! in_array($order->payment_status, ['paid', 'refunded'], true)
                    || $attempt->provider_payment_id === null) {
                    return 13;
                }
                if ($amount > (int) $order->amount_paid - (int) $order->amount_refunded) {
                    return 12;
                }
                $refund = Refund::query()->where('order_id', $order->id)
                    ->where('attempt_id', $attempt->id)->where('provider_refund_id', $transactionId)
                    ->lockForUpdate()->first();
                $data = $payload['Data'] ?? null;
                if (is_string($data)) {
                    $data = json_decode($data, true);
                }
                $requestKey = is_array($data) ? ($data['cms_refund_request_key'] ?? null) : null;
                if (is_string($requestKey)) {
                    $requested = Refund::query()->where('request_key', $requestKey)->lockForUpdate()->first();
                    if (! $requested || (int) $requested->order_id !== (int) $order->id
                        || (int) $requested->attempt_id !== (int) $attempt->id
                        || ($requested->provider_refund_id !== null && $requested->provider_refund_id !== $transactionId)
                        || ($refund && $refund->id !== $requested->id)) {
                        return 13;
                    }
                    $refund = $requested;
                }
                if ($refund && ((int) $refund->amount !== $amount || $refund->currency !== $currency
                    || ! in_array($refund->status, ['pending', 'submitted', 'unknown'], true))) {
                    return 13;
                }
                // If callback beats the API response and does not echo Data, ask CP
                // to retry after we have the transaction id; never guess by amount.
                if (! $refund && Refund::query()->where('order_id', $order->id)
                    ->whereIn('status', ['pending', 'submitted', 'unknown'])->exists()) {
                    return 13;
                }
            }
            $event = ProviderEvent::query()->createOrFirst(
                ['provider' => 'cloudpayments', 'provider_event_id' => $eventKey],
                [
                    'event_type' => 'cloudpayments.'.$type,
                    'order_id' => $order->id,
                    'attempt_id' => $attempt->id,
                    'signature_valid' => $source === 'webhook',
                    'processed' => false,
                    'payload_raw' => $envelope,
                    'received_at' => now(),
                ],
            );
            // The provider-wide unique key also protects concurrent cross-order replay.
            if (! $event->wasRecentlyCreated) {
                return (int) $event->order_id === (int) $order->id
                    && (int) $event->attempt_id === (int) $attempt->id
                    && $event->payload_raw === $envelope && $event->processed ? 0 : 13;
            }

            $from = $order->payment_status;
            if ($type === 'pay') {
                $settlement = $this->settlements->confirmPaid($order, [
                    'idempotency_key' => 'cloudpayments:pay:'.$transactionId,
                    'amount' => $amount,
                    'currency' => $currency,
                    'settlement_type' => OrderSettlement::TYPE_PROVIDER_PAYMENT,
                    'payment_attempt_id' => $attempt->id,
                    'provider_event_id' => $event->id,
                    'metadata' => ['provider' => 'cloudpayments', 'transaction_id' => $transactionId],
                ]);
                $order = $settlement->order()->firstOrFail();
                $attempt->forceFill([
                    'status' => 'completed',
                    'completed_at' => now(),
                    'provider_payment_id' => $transactionId,
                    'provider_substatus' => [...$substatus, 'provider_status' => 'paid'],
                ])->save();
            } elseif ($type === 'fail' && $this->stateMachine->canTransition($from, 'cancelled')) {
                app(OrderDiscountService::class)->releaseForOrder($order, 'failed');
                $this->stateMachine->transition($order, 'cancelled', 'not_granted');
                $attempt->forceFill([
                    'status' => 'cancelled',
                    'provider_payment_id' => $transactionId,
                    'provider_substatus' => [...$substatus, 'provider_status' => 'failed'],
                ])->save();
            } elseif ($type === 'refund') {
                $refund ??= Refund::query()->create([
                    'order_id' => $order->id, 'attempt_id' => $attempt->id,
                    'refund_type' => $amount === (int) $order->amount_paid - (int) $order->amount_refunded ? 'full' : 'partial',
                    'amount' => $amount, 'currency' => $currency,
                    'policy_access_action' => $amount === (int) $order->amount_paid - (int) $order->amount_refunded ? 'revoke' : 'keep',
                    'reason' => 'Provider-confirmed refund',
                    'status' => 'pending', 'provider_refund_id' => $transactionId,
                ]);
                $refund->update(['status' => 'completed', 'completed_at' => now(), 'provider_refund_id' => $transactionId]);
                $order->amount_refunded = (int) $order->amount_refunded + $amount;
                $order->save();
                // The purchased course is one service. An unexpected external partial
                // return is recorded, but must not revoke the unrefunded service.
                if ((int) $order->amount_refunded >= (int) $order->amount_paid
                    && $this->stateMachine->canTransition($from, 'refunded')) {
                    $itemIds = $order->items()->orderBy('id')->lockForUpdate()->pluck('id')->all();
                    if ($order->user_id !== null) {
                        $this->entitlements->revokeOrderGrants((int) $order->user_id, (int) $order->id, $itemIds, 'refund:cloudpayments');
                    }
                    $order->items()->whereIn('id', $itemIds)->update(['access_status' => 'revoked']);
                    $this->stateMachine->transition($order, 'refunded', 'revoked');
                }
            }
            $order->refresh();
            if ($from !== $order->payment_status || $type === 'refund') {
                $this->outbox->publishTransition($order, $attempt->id, $refund, $from, $order->payment_status, [
                    'source' => 'cloudpayments_'.$source.'_'.$type,
                    'provider_event_id' => $event->id,
                ]);
            }
            PaymentAuditLog::query()->create([
                'order_id' => $order->id,
                'attempt_id' => $attempt->id,
                'event_id' => $event->id,
                'refund_id' => $refund?->id,
                'action' => 'cloudpayments_'.$source.'_'.$type,
                'from_status' => $from,
                'to_status' => $order->payment_status,
                'actor_type' => 'provider',
                'meta' => [...$envelope, 'partial_service_refund_review' => $type === 'refund'
                    && (int) $order->amount_refunded < (int) $order->amount_paid],
            ]);
            $event->forceFill(['processed' => true, 'processed_at' => now()])->save();

            return 0;
        });
    }

    private function transactionId(mixed $value): ?string
    {
        return (is_string($value) || is_int($value))
            && preg_match('/^[1-9][0-9]{0,18}$/D', (string) $value) ? (string) $value : null;
    }

    private function amountCents(mixed $value): ?int
    {
        if (! is_string($value) && ! is_int($value) && ! is_float($value)) {
            return null;
        }
        if (! preg_match('/^([0-9]{1,12})(?:\.([0-9]{1,2}))?$/D', (string) $value, $parts)) {
            return null;
        }
        $cents = (int) $parts[1] * 100 + (int) str_pad($parts[2] ?? '', 2, '0');

        return $cents > 0 ? $cents : null;
    }
}
