<?php

namespace App\Services\Payments;

use App\Models\Order;
use App\Models\PaymentAttempt;
use App\Models\PaymentAuditLog;
use App\Models\Refund;
use App\Services\EntitlementGrantService;
use App\Services\PaymentStateMachine;
use DomainException;
use Illuminate\Support\Facades\DB;

class PodeliRefundService
{
    public function __construct(
        private readonly PodeliClient $client,
        private readonly EntitlementGrantService $entitlements,
        private readonly PaymentStateMachine $stateMachine,
        private readonly PaymentOutboxService $outbox,
    ) {}

    public function request(Order $order, array $input, string $key, int $actorId): Refund
    {
        if (! $this->client->configured()) {
            throw new DomainException('PAYMENT_PROVIDER_NOT_CONFIGURED');
        }
        if (! preg_match('/^[A-Za-z0-9-]{16,36}$/D', $key)) {
            throw new DomainException('PAYMENT_REFUND_IDEMPOTENCY_KEY_REQUIRED');
        }
        if (($input['refund_type'] ?? null) !== 'full' || ($input['policy_access_action'] ?? null) !== 'revoke') {
            throw new DomainException('PAYMENT_REFUND_WHOLE_SERVICE_REQUIRED');
        }
        [$refund, $attempt, $itemId, $created] = DB::transaction(function () use ($order, $input, $key, $actorId): array {
            $locked = Order::query()->lockForUpdate()->findOrFail($order->id);
            $attempt = $locked->activeAttempt()->lockForUpdate()->first();
            if (! $attempt) {
                throw new DomainException('PAYMENT_REFUND_PAYMENT_MISSING');
            }
            $this->assertAttempt($attempt);
            $items = $locked->items()->lockForUpdate()->get();
            if ($items->count() !== 1 || (int) $items[0]->quantity !== 1) {
                throw new DomainException('PAYMENT_REFUND_WHOLE_SERVICE_REQUIRED');
            }
            $itemId = PodeliClient::itemId((string) $items[0]->sellable_sku);
            $old = Refund::query()->where('request_key', $key)->first();
            if ($old) {
                if ((int) $old->order_id !== (int) $locked->id || (int) $old->attempt_id !== (int) $attempt->id
                    || $old->refund_type !== 'full' || $old->reason !== ($input['reason'] ?? '') || $old->policy_access_action !== 'revoke') {
                    throw new DomainException('PAYMENT_REFUND_IDEMPOTENCY_CONFLICT');
                }

                return [$old, $attempt, $itemId, false];
            }
            if ($locked->payment_status !== 'paid' || (int) $locked->amount_refunded !== 0) {
                // A pre-existing partial external return needs item/quantity reconciliation,
                // not an automatic second request returning a whole item again.
                throw new DomainException('PAYMENT_PODELI_REFUND_REVIEW_REQUIRED');
            }
            if (Refund::query()->where('order_id', $locked->id)->whereIn('status', ['pending', 'submitted', 'unknown'])->exists()) {
                throw new DomainException('PAYMENT_REFUND_CONFIRMATION_PENDING');
            }
            if ((int) $locked->amount_paid < 1 || (int) $locked->amount_paid !== (int) $attempt->amount) {
                throw new DomainException('PAYMENT_REFUND_AMOUNT_EXCEEDED');
            }
            $refund = Refund::query()->createOrFirst(['request_key' => $key], [
                'order_id' => $locked->id, 'attempt_id' => $attempt->id, 'refund_type' => 'full',
                'amount' => $locked->amount_paid, 'currency' => 'RUB', 'status' => 'pending',
                'reason' => $input['reason'], 'policy_access_action' => 'revoke', 'created_by' => $actorId,
                'provider_refund_id' => $key,
            ]);
            if (! $refund->wasRecentlyCreated) {
                throw new DomainException('PAYMENT_REFUND_IDEMPOTENCY_CONFLICT');
            }
            PaymentAuditLog::query()->create([
                'order_id' => $locked->id, 'attempt_id' => $attempt->id, 'refund_id' => $refund->id,
                'action' => 'refund_requested', 'actor_type' => 'staff', 'actor_id' => $actorId,
                'reason' => $input['reason'], 'meta' => ['provider' => 'podeli', 'amount' => $refund->amount, 'item_id' => $itemId],
            ]);

            return [$refund, $attempt, $itemId, true];
        }, 3);
        if (! $created) {
            return $refund;
        }
        try {
            $response = $this->client->refund((string) $attempt->provider_payment_id, $key, [['id' => $itemId, 'refundedQuantity' => '1']]);
            if (($response['order']['id'] ?? null) !== $attempt->provider_payment_id || ($response['refund']['id'] ?? null) !== $key) {
                throw new DomainException('PAYMENT_PODELI_REFUND_MISMATCH');
            }
            // POST acknowledges processing only; even its REFUNDED order status
            // cannot replace PROCESSED evidence from a subsequent authenticated INFO.
            Refund::query()->whereKey($refund->id)->where('status', 'pending')->update(['status' => 'submitted']);
        } catch (DomainException) {
            Refund::query()->whereKey($refund->id)->where('status', 'pending')->update(['status' => 'unknown']);
        }

        return $refund->fresh();
    }

    public function reconcile(Refund $refund): Refund
    {
        $attempt = PaymentAttempt::query()->findOrFail($refund->attempt_id);
        $this->assertAttempt($attempt);
        $item = $attempt->order->items()->sole();
        $data = $this->client->getOrder((string) $attempt->provider_payment_id);
        $this->client->assertOrder($data, (string) $attempt->provider_payment_id, (int) $attempt->amount, PodeliClient::itemId((string) $item->sellable_sku));
        foreach ($data['refund'] ?? [] as $entry) {
            if (is_array($entry) && ($entry['id'] ?? null) === $refund->provider_refund_id) {
                $this->applyRefund($attempt, $entry);

                return $refund->fresh();
            }
        }

        // Missing refund does not prove that the uncertain POST failed.
        return $refund->fresh();
    }

    /** Only call with an entry from an authenticated, validated INFO response. */
    public function applyRefund(PaymentAttempt $attempt, array $entry): void
    {
        $this->assertAttempt($attempt);
        $id = $entry['id'] ?? null;
        if (! is_string($id) || $id === '' || strlen($id) > 36) {
            throw new DomainException('PAYMENT_PODELI_REFUND_MISMATCH');
        }
        if (($entry['status'] ?? null) !== 'PROCESSED') {
            return;
        }
        $amount = PodeliClient::minor($entry['refundedAmountCredit'] ?? null);
        if ($amount < 1 || PodeliClient::minor($entry['totalRefundedAmount'] ?? null) !== $amount
            || PodeliClient::minor($entry['refundedPrepaidAmount'] ?? null) !== 0
            || ! is_array($entry['refundedItems'] ?? null) || count($entry['refundedItems']) !== 1) {
            throw new DomainException('PAYMENT_PODELI_REFUND_MISMATCH');
        }
        $item = $attempt->order->items()->sole();
        $returned = $entry['refundedItems'][0];
        if (($returned['id'] ?? null) !== PodeliClient::itemId((string) $item->sellable_sku)
            || PodeliClient::minor($returned['itemRefundedAmount'] ?? null) !== $amount
            || PodeliClient::minor($returned['itemRefundedPrepaidAmount'] ?? null) !== 0
            || ! is_string($returned['refundedQuantity'] ?? null)
            || str_replace(['0', '.'], '', $returned['refundedQuantity']) === ''
            || ! preg_match('/^(?:0\.[0-9]{1,3}|1(?:\.0{1,3})?)$/D', $returned['refundedQuantity'])) {
            throw new DomainException('PAYMENT_PODELI_REFUND_MISMATCH');
        }
        DB::transaction(function () use ($attempt, $id, $amount): void {
            $order = Order::query()->lockForUpdate()->findOrFail($attempt->order_id);
            $refund = Refund::query()->where('attempt_id', $attempt->id)->where('provider_refund_id', $id)->first();
            if ($refund && ((int) $refund->order_id !== (int) $order->id || (int) $refund->amount !== $amount || $refund->currency !== 'RUB')) {
                throw new DomainException('PAYMENT_PODELI_REFUND_MISMATCH');
            }
            if ($refund?->status === 'completed') {
                return;
            }
            if (! in_array($order->payment_status, ['paid', 'refunded'], true)
                || $amount > (int) $order->amount_paid - (int) $order->amount_refunded) {
                throw new DomainException('PAYMENT_REFUND_AMOUNT_EXCEEDED');
            }
            $full = (int) $order->amount_refunded + $amount === (int) $order->amount_paid;
            if (! $refund) {
                $refund = Refund::query()->create([
                    'order_id' => $order->id, 'attempt_id' => $attempt->id,
                    'refund_type' => $full ? 'full' : 'partial', 'amount' => $amount, 'currency' => 'RUB',
                    'status' => 'submitted', 'reason' => 'Provider initiated refund', 'provider_refund_id' => $id,
                    'policy_access_action' => $full ? 'revoke' : 'keep',
                ]);
            }
            $from = $order->payment_status;
            $order->amount_refunded = (int) $order->amount_refunded + $amount;
            $order->save();
            if ($full) {
                $itemIds = $order->items()->orderBy('id')->lockForUpdate()->pluck('id')->all();
                if ($order->user_id !== null) {
                    $this->entitlements->revokeOrderGrants((int) $order->user_id, (int) $order->id, $itemIds, 'refund:podeli:'.$id);
                }
                $order->items()->update(['access_status' => 'revoked']);
                $this->stateMachine->transition($order, 'refunded', 'revoked');
            }
            $refund->update(['status' => 'completed', 'completed_at' => now()]);
            $this->outbox->publishTransition($order->fresh(), $attempt->id, $refund, $from, $order->payment_status, [
                'source' => 'podeli_refund', 'access_allocation' => $full ? 'whole_service' : 'manual_review',
            ]);
            PaymentAuditLog::query()->create([
                'order_id' => $order->id, 'attempt_id' => $attempt->id, 'refund_id' => $refund->id,
                'action' => $full ? 'refund_confirmed' : 'partial_service_refund_review', 'actor_type' => 'provider',
                'meta' => ['provider' => 'podeli', 'refund_id' => $id, 'amount' => $amount],
            ]);
        }, 3);
    }

    private function assertAttempt(PaymentAttempt $attempt): void
    {
        if ($attempt->provider !== 'podeli' || $attempt->currency !== 'RUB'
            || $attempt->provider_payment_id !== PodeliClient::orderId((int) $attempt->order_id, (int) $attempt->id)
            || ($attempt->provider_substatus['sandbox'] ?? null) !== (bool) config('podeli.sandbox', true)
            || ($attempt->provider_substatus['account_fingerprint'] ?? null) !== $this->client->accountFingerprint()
            || ($attempt->provider_substatus['capture_mode'] ?? null) !== config('podeli.capture_mode')) {
            throw new DomainException('PAYMENT_PODELI_ORDER_MISMATCH');
        }
    }
}
