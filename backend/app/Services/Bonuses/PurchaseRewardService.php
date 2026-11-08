<?php

namespace App\Services\Bonuses;

use App\Models\CoinLedgerEntry;
use App\Models\Order;
use App\Models\OrderSettlement;
use App\Models\PaymentAuditLog;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;

class PurchaseRewardService
{
    public function apply(int $orderId): array
    {
        return DB::transaction(function () use ($orderId): array {
            $order = Order::query()->lockForUpdate()->findOrFail($orderId);
            User::query()->lockForUpdate()->findOrFail($order->user_id);
            $settlement = $this->confirmedSettlement($order);
            $fingerprint = hash('sha256', json_encode([$order->id, $order->user_id, $settlement->id,
                (int) $settlement->amount, $settlement->currency, $settlement->confirmed_at->toISOString()], JSON_THROW_ON_ERROR));
            $existing = PaymentAuditLog::query()->where('order_id', $orderId)->where('action', 'purchase_reward_applied')->first();
            if ($existing) {
                if (($existing->meta['fingerprint'] ?? null) !== $fingerprint || ($existing->meta['version'] ?? null) !== 1) {
                    throw new DomainException('BONUS_REWARD_EVIDENCE_CONFLICT');
                }

                return $existing->meta['result'];
            }
            $cash = (int) $settlement->amount;
            // Five percent in whole coins: cash minor units / 2000, half up, without multiplication overflow.
            $units = intdiv($cash, 2000) + ($cash % 2000 >= 1000 ? 1 : 0);
            $refunded = $order->payment_status === 'refunded';
            $result = ['status' => $refunded ? 'skipped_refunded' : 'applied', 'reward_units' => 0, 'debt_repaid' => 0];
            if (! $refunded && $units > 0) {
                $expiry = $settlement->confirmed_at->copy()->addDays(60);
                $lot = CoinLedgerEntry::query()->create(['user_id' => $order->user_id, 'order_id' => $orderId,
                    'event_type' => 'coins_accrual', 'units' => $units, 'expires_at' => $expiry,
                    'idempotency_key' => "purchase:{$orderId}:accrual:v1"]);
                $debt = (int) CoinLedgerEntry::query()->where('user_id', $order->user_id)->where('event_type', 'coins_debt')->sum('units')
                    - (int) CoinLedgerEntry::query()->where('user_id', $order->user_id)->where('event_type', 'coins_debt_repay')->sum('units');
                if ($debt < 0) {
                    throw new DomainException('BONUS_LEDGER_INCONSISTENT');
                }
                $repay = $expiry->isFuture() ? min($debt, $units) : 0;
                if ($repay > 0) {
                    CoinLedgerEntry::query()->create(['user_id' => $order->user_id, 'order_id' => $orderId,
                        'lot_id' => $lot->id, 'event_type' => 'coins_debt_repay', 'units' => $repay,
                        'idempotency_key' => "purchase:{$orderId}:debt-repay:v1"]);
                }
                $result = ['status' => 'applied', 'reward_units' => $units, 'debt_repaid' => $repay];
            }
            PaymentAuditLog::query()->create(['order_id' => $orderId, 'action' => 'purchase_reward_applied',
                'actor_type' => 'system', 'meta' => ['version' => 1, 'fingerprint' => $fingerprint, 'result' => $result]]);

            return $result;
        }, 3);
    }

    /** Caller holds this order's lock. Never locks another order while holding its user. */
    public function confirmedSettlement(Order $order): OrderSettlement
    {
        $settlements = OrderSettlement::query()->where('order_id', $order->id)->where('status', OrderSettlement::STATUS_CONFIRMED)->get();
        if ($settlements->count() !== 1 || ! in_array($order->payment_status, ['paid', 'partially_refunded', 'refunded'], true)) {
            throw new DomainException('BONUS_CONFIRMED_SETTLEMENT_REQUIRED');
        }
        $settlement = $settlements->sole();
        if ($settlement->confirmed_at === null || $order->currency !== 'RUB' || $settlement->currency !== 'RUB'
            || (int) $settlement->amount < 0 || (int) $settlement->amount !== (int) $order->amount_paid
            || (int) $settlement->amount !== (int) $order->amount_final
            || ((int) $settlement->amount === 0 && $settlement->settlement_type !== OrderSettlement::TYPE_SETTLED_ZERO_CASH)) {
            throw new DomainException('BONUS_CONFIRMED_SETTLEMENT_REQUIRED');
        }

        return $settlement;
    }
}
