<?php

namespace App\Services\Payments;

use App\Models\Order;
use App\Models\Refund;
use App\Models\SideEffectExecution;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class FiscalReceiptService
{
    public function __construct(private readonly CloudkassirReceiptAdapter $adapter) {}

    public function execute(int $id): void
    {
        $claim = DB::transaction(function () use ($id): ?array {
            $effect = SideEffectExecution::query()->lockForUpdate()->findOrFail($id);
            $meta = $effect->meta ?? [];
            if ($effect->status === 'completed' || in_array($effect->status, ['unknown', 'manual_review', 'failed'], true)) {
                return null;
            }
            if (isset($meta['claim_until']) && now()->timestamp < (int) $meta['claim_until']) {
                return null;
            }
            if ($effect->status === 'submitting' && empty($meta['provider_receipt_id'])) {
                unset($meta['claim_token'], $meta['claim_until']);
                $this->save($effect, 'unknown', $meta + ['diagnostic' => 'FISCAL_EXPIRED_SUBMIT_CLAIM']);

                return null;
            }
            if (! $this->configured()) {
                $this->save($effect, 'blocked_configuration', $meta);

                return null;
            }
            if (! isset($meta['payload'])) {
                $payload = $this->payload($effect);
                if (is_string($payload)) {
                    $this->save($effect, $payload, $meta);

                    return null;
                }
                $meta['payload'] = $payload;
                $meta['payload_hash'] = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
                $meta['config_version'] = (string) config('fiscal.version');
                $meta['request_key'] = 'fiscal-'.Str::uuid();
            }
            if (! hash_equals($meta['payload_hash'], hash('sha256', json_encode($meta['payload'], JSON_THROW_ON_ERROR)))) {
                $this->save($effect, 'manual_review', $meta + ['diagnostic' => 'FISCAL_SNAPSHOT_MISMATCH']);

                return null;
            }
            $meta['claim_token'] = (string) Str::uuid();
            $meta['claim_until'] = now()->addMinute()->timestamp;
            $meta['first_dispatch_at'] ??= now()->toIso8601String();
            $this->save($effect, empty($meta['provider_receipt_id']) ? 'submitting' : 'submitted', $meta);

            return $meta;
        });
        if ($claim === null) {
            return;
        }

        $status = 'submitted';
        try {
            if (empty($claim['provider_receipt_id'])) {
                $response = $this->adapter->create($claim['payload'], $claim['request_key']);
                if ($response['Success'] === false) {
                    $status = 'failed';
                    $claim['diagnostic'] = 'FISCAL_CREATE_REJECTED';
                } elseif (! is_string($response['Model']['Id'] ?? null) || $response['Model']['Id'] === '') {
                    $status = 'unknown';
                    $claim['diagnostic'] = 'FISCAL_CREATE_ID_MISSING';
                } else {
                    $claim['provider_receipt_id'] = $response['Model']['Id'];
                }
            } else {
                $response = $this->adapter->status($claim['provider_receipt_id']);
                $providerStatus = $response['Model'] ?? null;
                if ($response['Success'] !== true || ! in_array($providerStatus, ['Queued', 'Processed'], true)) {
                    $status = 'manual_review';
                    $claim['diagnostic'] = 'FISCAL_STATUS_UNRESOLVED';
                } elseif ($providerStatus === 'Processed') {
                    $details = $this->adapter->details($claim['provider_receipt_id']);
                    $evidence = $details['Model']['AdditionalData'] ?? [];
                    if ($details['Success'] !== true || ! $this->validEvidence($claim, $evidence)) {
                        $status = 'manual_review';
                        $claim['diagnostic'] = 'FISCAL_EVIDENCE_MISMATCH';
                    } else {
                        $status = 'completed';
                        $claim['verified'] = true;
                        $claim['evidence'] = array_intersect_key($evidence, array_flip(['Id', 'InvoiceId', 'OrganizationInn', 'Type', 'Amount', 'DocumentNumber', 'FiscalNumber', 'FiscalSign']));
                    }
                }
            }
        } catch (Throwable) {
            $status = empty($claim['provider_receipt_id']) ? 'unknown' : 'submitted';
            $claim['diagnostic'] = 'FISCAL_TRANSPORT_UNCERTAIN';
        }

        DB::transaction(function () use ($id, $claim, $status): void {
            $effect = SideEffectExecution::query()->lockForUpdate()->findOrFail($id);
            if (($effect->meta['claim_token'] ?? null) !== $claim['claim_token']) {
                return;
            }
            unset($claim['claim_until']);
            $this->save($effect, $status, $claim);
        });
    }

    private function configured(): bool
    {
        if (! config('fiscal.enabled') || ! config('fiscal.exclusive_issuer_confirmed')) {
            return false;
        }
        foreach (['public_id', 'api_secret', 'inn', 'version', 'taxation_system', 'vat', 'method', 'object'] as $key) {
            if (config('fiscal.'.$key) === null || config('fiscal.'.$key) === '') {
                return false;
            }
        }

        return preg_match('/^\d{10}(\d{2})?$/D', (string) config('fiscal.inn')) === 1
            && in_array((string) config('fiscal.taxation_system'), ['0', '1', '2', '3', '4', '5'], true)
            && in_array((string) config('fiscal.vat'), ['none', '0', '5', '7', '10', '20', '22', '105', '107', '110', '120', '122'], true)
            && in_array((string) config('fiscal.method'), ['1', '2', '3', '4', '5', '6', '7'], true)
            && ctype_digit((string) config('fiscal.object')) && (int) config('fiscal.object') > 0 && (int) config('fiscal.object') <= 33;
    }

    private function payload(SideEffectExecution $effect): array|string
    {
        $order = Order::query()->with(['items', 'settlements', 'user'])->findOrFail($effect->order_id);
        $items = $order->items;
        if ($order->currency !== 'RUB' || $order->settlement_type !== 'provider_payment' || (int) $order->amount_paid <= 0
            || $items->count() !== 1 || (int) $items[0]->quantity !== 1
            || (int) $items[0]->final_amount !== (int) $order->amount_paid
            || $order->settlements->where('status', 'confirmed')->where('settlement_type', 'provider_payment')->sum('amount') !== (int) $order->amount_paid
            || $order->settlements->where('settlement_type', '!=', 'provider_payment')->isNotEmpty()
            || ! empty($items[0]->benefit_snapshot['coins_writeoff']) || ($items[0]->benefit_snapshot['discount_type'] ?? '') === 'coins') {
            return 'blocked_fiscal_qualification';
        }
        if ($effect->handler === 'receipt.refund.issue') {
            $refund = Refund::query()->find($effect->refund_id);
            $income = SideEffectExecution::query()->where('dedup_key', "order:{$order->id}:receipt:income")->first();
            if ($refund === null || $refund->status !== 'completed' || (int) $refund->order_id !== $order->id
                || (int) $refund->amount !== (int) $order->amount_paid) {
                return 'blocked_fiscal_qualification';
            }
            if ($income === null || (! empty($income->meta['delivery_version'])
                && in_array($income->status, ['pending', 'submitting', 'submitted', 'blocked_configuration'], true))) {
                return 'waiting_income_receipt';
            }
            if ($income->status !== 'completed' || empty($income->meta['verified']) || empty($income->meta['provider_receipt_id']) || empty($income->meta['payload'])) {
                return 'manual_review';
            }
            $payload = $income->meta['payload'];
            if (! hash_equals((string) ($income->meta['payload_hash'] ?? ''), hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR)))
                || ($payload['InvoiceId'] ?? null) !== (string) $order->id || ($payload['Type'] ?? null) !== 'Income'
                || (float) ($payload['CustomerReceipt']['Amounts']['Electronic'] ?? -1) !== (float) ((int) $refund->amount / 100)) {
                return 'manual_review';
            }
            $payload['Type'] = 'IncomeReturn';

            return $payload;
        }
        if (! in_array($order->payment_status, ['paid', 'refunded'], true) || trim((string) $items[0]->display_title) === '') {
            return 'blocked_fiscal_qualification';
        }
        $amount = (int) $order->amount_paid / 100;

        return [
            'Inn' => (string) config('fiscal.inn'), 'Type' => 'Income', 'InvoiceId' => (string) $order->id,
            'AccountId' => (string) $order->user_id,
            'CustomerReceipt' => [
                'Items' => [[
                    'Label' => $items[0]->display_title, 'Price' => $amount, 'Quantity' => 1, 'Amount' => $amount,
                    'Vat' => config('fiscal.vat') === 'none' ? null : (int) config('fiscal.vat'),
                    'Method' => (int) config('fiscal.method'), 'Object' => (int) config('fiscal.object'),
                ]],
                'TaxationSystem' => (int) config('fiscal.taxation_system'),
                'Amounts' => ['Electronic' => $amount], 'Email' => $order->user?->email,
            ],
        ];
    }

    private function validEvidence(array $claim, mixed $evidence): bool
    {
        if (! is_array($evidence)) {
            return false;
        }
        foreach (['DocumentNumber', 'FiscalNumber', 'FiscalSign'] as $key) {
            if (! is_scalar($evidence[$key] ?? null) || (string) $evidence[$key] === '') {
                return false;
            }
        }
        $payload = $claim['payload'];

        return (string) ($evidence['Id'] ?? '') === $claim['provider_receipt_id']
            && (string) ($evidence['InvoiceId'] ?? '') === $payload['InvoiceId']
            && (string) ($evidence['OrganizationInn'] ?? '') === $payload['Inn']
            && ($evidence['Type'] ?? null) === $payload['Type']
            && is_numeric($evidence['Amount'] ?? null)
            && (float) $evidence['Amount'] === (float) $payload['CustomerReceipt']['Amounts']['Electronic'];
    }

    private function save(SideEffectExecution $effect, string $status, array $meta): void
    {
        $effect->forceFill(['status' => $status, 'meta' => $meta])->save();
    }
}
