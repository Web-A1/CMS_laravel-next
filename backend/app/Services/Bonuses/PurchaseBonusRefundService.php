<?php

namespace App\Services\Bonuses;

use App\Models\CoinLedgerEntry;
use App\Models\CoinReservation;
use App\Models\Order;
use App\Models\PaymentAuditLog;
use App\Models\Refund;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;

class PurchaseBonusRefundService
{
    public function __construct(private readonly PurchaseRewardService $rewards) {}

    public function apply(int $refundId): array
    {
        $reference = Refund::query()->findOrFail($refundId);

        return DB::transaction(function () use ($refundId, $reference): array {
            $order = Order::query()->lockForUpdate()->findOrFail($reference->order_id);
            User::query()->lockForUpdate()->findOrFail($order->user_id);
            $refund = Refund::query()->lockForUpdate()->findOrFail($refundId);
            $settlement = $this->rewards->confirmedSettlement($order);
            $chain = Refund::query()->where('order_id', $order->id)->where('status', 'completed')->orderBy('id')->get();
            $terminals = $chain->where('policy_access_action', 'revoke');
            $terminal = $terminals->count() === 1 ? $terminals->sole() : null;
            if ((int) $refund->order_id !== (int) $order->id || $refund->status !== 'completed'
                || $refund->completed_at === null || $refund->currency !== 'RUB'
                || $order->payment_status !== 'refunded' || $terminal === null || (int) $terminal->id !== $refundId
                || (int) $order->amount_refunded !== (int) $order->amount_paid
                || (int) $chain->sum('amount') !== (int) $order->amount_paid
                || ((int) $order->amount_paid === 0 && ($chain->count() !== 1 || $refund->refund_type !== 'full'))
                || $chain->contains(fn (Refund $row) => $row->currency !== $order->currency
                    || $row->attempt_id !== $settlement->payment_attempt_id || $row->completed_at === null
                    || ((int) $order->amount_paid > 0 ? (int) $row->amount <= 0 : (int) $row->amount !== 0)
                    || $row->completed_at->lessThan($settlement->confirmed_at)
                    || $row->completed_at->greaterThan($refund->completed_at))) {
                throw new DomainException('BONUS_FULL_REFUND_EVIDENCE_REQUIRED');
            }
            $fingerprint = hash('sha256', json_encode([$order->id, $order->user_id, $refundId,
                (int) $order->amount_paid, $refund->currency, $chain->map(fn (Refund $row) => [
                    $row->id, (int) $row->amount, $row->attempt_id, $row->completed_at->toISOString(), $row->policy_access_action,
                ])->all()], JSON_THROW_ON_ERROR));
            $existing = PaymentAuditLog::query()->where('order_id', $order->id)->where('action', 'purchase_bonus_refund_applied')->first();
            if ($existing) {
                if (($existing->meta['version'] ?? null) !== 1 || ($existing->meta['fingerprint'] ?? null) !== $fingerprint) {
                    throw new DomainException('BONUS_REFUND_EVIDENCE_CONFLICT');
                }

                return $existing->meta['result'];
            }
            $result = ['status' => 'applied', 'restored_units' => 0, 'revoked_units' => 0, 'debt_units' => 0];
            $commits = CoinLedgerEntry::query()->where('order_id', $order->id)->where('event_type', 'coins_commit')->get();
            foreach ($commits as $commit) {
                $lot = CoinLedgerEntry::query()->findOrFail($commit->lot_id);
                $reservation = CoinReservation::query()->findOrFail($commit->reservation_id);
                if ((int) $commit->user_id !== (int) $order->user_id || (int) $lot->user_id !== (int) $order->user_id
                    || $lot->event_type !== 'coins_accrual' || $reservation->status !== 'committed'
                    || (int) $reservation->order_id !== (int) $order->id || (int) $reservation->user_id !== (int) $order->user_id) {
                    throw new DomainException('BONUS_LEDGER_INCONSISTENT');
                }
                if ((int) $commits->where('reservation_id', $reservation->id)->sum('units') !== $reservation->units) {
                    throw new DomainException('BONUS_LEDGER_INCONSISTENT');
                }
                if (CoinLedgerEntry::query()->where('lot_id', $lot->id)->where('event_type', 'coins_revoke')->exists()) {
                    // Cash refund is already complete. Only bonus compensation needs review.
                    throw new DomainException('BONUS_REVOKED_LOT_REFUND_REVIEW');
                }
                CoinLedgerEntry::query()->create(['user_id' => $order->user_id, 'order_id' => $order->id,
                    'reservation_id' => $commit->reservation_id, 'lot_id' => $lot->id, 'event_type' => 'coins_refund',
                    'units' => $commit->units, 'expires_at' => $lot->expires_at,
                    'idempotency_key' => "refund:{$refundId}:spent:{$lot->id}"]);
                $result['restored_units'] += $commit->units;
            }
            $lot = CoinLedgerEntry::query()->where('user_id', $order->user_id)->where('order_id', $order->id)
                ->where('event_type', 'coins_accrual')->where('idempotency_key', "purchase:{$order->id}:accrual:v1")->first();
            if ($lot) {
                $spent = (int) CoinLedgerEntry::query()->where('lot_id', $lot->id)->where('event_type', 'coins_commit')->sum('units');
                $returned = (int) CoinLedgerEntry::query()->where('lot_id', $lot->id)->where('event_type', 'coins_refund')->sum('units');
                $repaid = (int) CoinLedgerEntry::query()->where('lot_id', $lot->id)->where('event_type', 'coins_debt_repay')->sum('units');
                $consumed = $spent - $returned + $repaid;
                if ($consumed < 0 || $consumed > $lot->units) {
                    throw new DomainException('BONUS_LEDGER_INCONSISTENT');
                }
                CoinLedgerEntry::query()->create(['user_id' => $order->user_id, 'order_id' => $order->id,
                    'lot_id' => $lot->id, 'event_type' => 'coins_revoke', 'units' => $lot->units,
                    'idempotency_key' => "refund:{$refundId}:revoke:{$lot->id}"]);
                if ($consumed > 0) {
                    CoinLedgerEntry::query()->create(['user_id' => $order->user_id, 'order_id' => $order->id,
                        'lot_id' => $lot->id, 'event_type' => 'coins_debt', 'units' => $consumed,
                        'idempotency_key' => "refund:{$refundId}:debt:{$lot->id}"]);
                }
                $result['revoked_units'] = $lot->units;
                $result['debt_units'] = $consumed;
            }
            PaymentAuditLog::query()->create(['order_id' => $order->id, 'action' => 'purchase_bonus_refund_applied',
                'refund_id' => $refundId, 'actor_type' => 'system', 'meta' => ['version' => 1, 'refund_id' => $refundId,
                    'fingerprint' => $fingerprint, 'result' => $result]]);

            return $result;
        }, 3);
    }
}
