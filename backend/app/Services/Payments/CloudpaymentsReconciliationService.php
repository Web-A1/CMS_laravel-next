<?php

namespace App\Services\Payments;

use App\Models\Order;
use App\Models\PaymentAttempt;
use App\Models\PaymentAuditLog;
use App\Models\Refund;
use DomainException;
use Illuminate\Support\Facades\DB;

class CloudpaymentsReconciliationService
{
    public function __construct(
        private readonly PaymentProviderRegistry $registry,
        private readonly CloudpaymentsWebhookHandler $handler,
    ) {}

    /** @return array{transaction_id:string,provider_status:string,result:string,code?:int} */
    public function payment(PaymentAttempt $attempt, bool $apply = false): array
    {
        $adapter = $this->registry->forProvider('cloudpayments');
        if ($attempt->provider !== 'cloudpayments' || ! $attempt->provider_payment_id
            || ! $adapter instanceof CloudpaymentsPaymentProviderAdapter) {
            throw new DomainException('PAYMENT_RECONCILIATION_PAYMENT_MISSING');
        }
        $model = $adapter->transaction((string) $attempt->provider_payment_id);
        $invoice = $attempt->provider_substatus['invoice_id'] ?? (string) $attempt->order_id;
        if ((string) ($model['InvoiceId'] ?? '') !== (string) $invoice
            || ($model['OriginalTransactionId'] ?? null) !== null || ($model['Type'] ?? null) !== 0) {
            throw new DomainException('PAYMENT_RECONCILIATION_IDENTITY_INVALID');
        }
        $status = (string) ($model['Status'] ?? '');
        $result = ['transaction_id' => (string) $attempt->provider_payment_id, 'provider_status' => $status, 'result' => 'pending'];
        if (! in_array($status, ['Completed', 'Declined'], true)) {
            return $result;
        }
        $payload = [
            ...$model, 'TestMode' => $model['TestMode'] ? 1 : 0, 'OperationType' => 'Payment',
        ];
        $amount = $model['Amount'] ?? null;
        $validAmount = (is_string($amount) || is_int($amount) || is_float($amount))
            && preg_match('/^([0-9]{1,12})(?:\.([0-9]{1,2}))?$/D', (string) $amount, $parts);
        $cents = $validAmount ? (int) $parts[1] * 100 + (int) str_pad($parts[2] ?? '', 2, '0') : null;
        $order = $attempt->order()->firstOrFail();
        if ($cents !== (int) $attempt->amount || $cents !== (int) ($order->amount_final > 0 ? $order->amount_final : $order->amount_total)
            || ($model['Currency'] ?? null) !== $attempt->currency || ($model['Currency'] ?? null) !== $order->currency
            || (int) $order->active_attempt_id !== (int) $attempt->id) {
            return [...$result, 'result' => 'rejected', 'code' => 12];
        }
        if (! $apply) {
            return [...$result, 'result' => 'verified_provider_result'];
        }
        // Authenticated provider read uses the same money/identity/transition checks
        // and event identity as the signed notification; no fabricated HMAC request.
        $code = $this->handler->handle($status === 'Completed' ? 'pay' : 'fail', $payload, 'reconciliation');

        return [...$result, 'result' => $code === 0 ? 'applied' : 'rejected', 'code' => $code];
    }

    /** @return array{transaction_id:string,provider_status:string,result:string,code?:int} */
    public function refund(Refund $refund, bool $apply = false): array
    {
        $attempt = PaymentAttempt::query()->find($refund->attempt_id);
        $adapter = $this->registry->forProvider('cloudpayments');
        if (! $attempt || $attempt->provider !== 'cloudpayments' || ! $refund->provider_refund_id
            || ! $adapter instanceof CloudpaymentsPaymentProviderAdapter) {
            throw new DomainException('PAYMENT_REFUND_RECONCILIATION_IDENTITY_MISSING');
        }
        if (! PaymentAuditLog::query()->where('refund_id', $refund->id)
            ->whereIn('action', ['refund_provider_submitted', 'cloudpayments_webhook_refund', 'cloudpayments_reconciliation_refund'])
            ->where('meta->transaction_id', $refund->provider_refund_id)->exists()) {
            throw new DomainException('PAYMENT_REFUND_RECONCILIATION_PROVENANCE_MISSING');
        }
        $model = $adapter->transaction($refund->provider_refund_id);
        $invoice = $attempt->provider_substatus['invoice_id'] ?? (string) $attempt->order_id;
        $amount = $model['Amount'] ?? null;
        $validAmount = (is_string($amount) || is_int($amount) || is_float($amount))
            && preg_match('/^([0-9]{1,12})(?:\.([0-9]{1,2}))?$/D', (string) $amount, $parts);
        $cents = $validAmount ? (int) $parts[1] * 100 + (int) str_pad($parts[2] ?? '', 2, '0') : null;
        if ((string) ($model['InvoiceId'] ?? '') !== (string) $invoice
            || (string) ($model['OriginalTransactionId'] ?? '') !== (string) $attempt->provider_payment_id
            || ($model['Currency'] ?? null) !== $refund->currency
            || $cents !== (int) $refund->amount) {
            throw new DomainException('PAYMENT_RECONCILIATION_IDENTITY_INVALID');
        }
        $status = (string) ($model['Status'] ?? '');
        $result = ['transaction_id' => $refund->provider_refund_id, 'provider_status' => $status, 'result' => 'pending'];
        if (! in_array($status, ['Completed', 'Declined'], true)) {
            return $result;
        }
        if (! $apply) {
            return [...$result, 'result' => 'verified_provider_result'];
        }
        if ($status === 'Declined') {
            DB::transaction(function () use ($refund): void {
                $order = Order::query()->lockForUpdate()->findOrFail($refund->order_id);
                $locked = Refund::query()->lockForUpdate()->findOrFail($refund->id);
                if (! in_array($locked->status, ['pending', 'submitted', 'unknown'], true)) {
                    return;
                }
                $locked->update(['status' => 'failed']);
                PaymentAuditLog::query()->create([
                    'order_id' => $order->id, 'attempt_id' => $locked->attempt_id, 'refund_id' => $locked->id,
                    'action' => 'refund_provider_declined', 'actor_type' => 'provider',
                    'from_status' => $order->payment_status, 'to_status' => $order->payment_status,
                ]);
            });

            return [...$result, 'result' => 'applied'];
        }
        $code = $this->handler->handle('refund', [
            ...$model, 'OperationType' => 'Refund', 'PaymentTransactionId' => $model['OriginalTransactionId'],
        ], 'reconciliation');

        return [...$result, 'result' => $code === 0 ? 'applied' : 'rejected', 'code' => $code];
    }
}
