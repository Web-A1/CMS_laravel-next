<?php

namespace App\Services\Bonuses;

use App\Models\Order;
use App\Models\PaymentAuditLog;
use App\Models\PromoCode;
use App\Models\PromoReservation;
use App\Models\Refund;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

class AutoPurchasePromoService
{
    public function __construct(private readonly PurchaseRewardService $rewards) {}

    /** Returns the historical issuance outcome, not a claim that the code is currently usable. */
    public function apply(int $orderId): array
    {
        return DB::transaction(function () use ($orderId): array {
            $order = Order::query()->lockForUpdate()->findOrFail($orderId);
            $user = User::query()->lockForUpdate()->findOrFail($order->user_id);
            $settlement = $this->rewards->confirmedSettlement($order);
            $fingerprint = $this->fingerprint([$orderId, (int) $user->id, (int) $settlement->id,
                (int) $settlement->amount, $settlement->currency, $settlement->confirmed_at->toISOString()]);
            $existing = PaymentAuditLog::query()->where('order_id', $orderId)->where('action', 'auto_purchase_promo_applied')->first();
            if ($existing !== null) {
                $this->assertMarker($existing, $fingerprint);

                return $existing->meta['result'];
            }
            $cash = (int) $settlement->amount;
            $bps = match (true) {
                $cash >= 1000000 => 2500,
                $cash >= 500000 => 1500,
                $cash >= 100000 => 1000,
                default => 0,
            };
            $expires = $settlement->confirmed_at->copy()->addDays(7);
            $result = ['status' => 'skipped_threshold', 'promo_code_id' => null, 'discount_bps' => 0];
            $secret = [];
            if ($order->payment_status === 'refunded') {
                $result['status'] = 'skipped_refunded';
            } elseif ($expires->lessThanOrEqualTo(now())) {
                $result['status'] = 'skipped_expired';
            } elseif (($settlement->metadata['grant_status'] ?? null) === 'blocked') {
                throw new DomainException('BONUS_PROMO_PURCHASE_REQUIRES_REVIEW');
            } elseif ($bps > 0) {
                if (! is_string($user->email) || ! filter_var($user->email, FILTER_VALIDATE_EMAIL)) {
                    throw new DomainException('BONUS_PROMO_EMAIL_REQUIRED');
                }
                $code = 'CMS_'.strtoupper(bin2hex(random_bytes(16)));
                $promo = PromoCode::query()->create(['code_hash' => PromoReservationService::codeHash($code),
                    'status' => 'active', 'discount_kind' => 'percent_bps', 'discount_bps' => $bps, 'currency' => 'RUB',
                    'starts_at' => $settlement->confirmed_at, 'expires_at' => $expires,
                    'activation_limit' => 1, 'per_user_limit' => 1,
                    'bound_email_hash' => hash('sha256', mb_strtolower(trim($user->email))), 'rules_version' => 'auto-cash-promo-v1']);
                $secret = ['encrypted_code' => Crypt::encryptString($code), 'code_hash' => $promo->code_hash];
                $result = ['status' => 'issued', 'promo_code_id' => (int) $promo->id,
                    'discount_bps' => $bps, 'expires_at' => $expires->toIso8601String()];
            }
            PaymentAuditLog::query()->create(['order_id' => $orderId, 'action' => 'auto_purchase_promo_applied',
                'actor_type' => 'system', 'meta' => ['version' => 1, 'fingerprint' => $fingerprint,
                    'settlement_id' => (int) $settlement->id, 'cash_minor' => $cash, 'result' => $result, ...$secret]]);

            return $result;
        }, 3);
    }

    public function revokeForRefund(int $refundId): array
    {
        $reference = Refund::query()->findOrFail($refundId);

        return DB::transaction(function () use ($reference, $refundId): array {
            $order = Order::query()->lockForUpdate()->findOrFail($reference->order_id);
            User::query()->lockForUpdate()->findOrFail($order->user_id);
            $refund = Refund::query()->findOrFail($refundId);
            $settlement = $this->rewards->confirmedSettlement($order);
            $refunds = Refund::query()->where('order_id', $order->id)->where('status', 'completed')->get();
            if ((int) $refund->order_id !== (int) $order->id || $refund->status !== 'completed' || $refund->completed_at === null
                || $refund->policy_access_action !== 'revoke' || $order->payment_status !== 'refunded'
                || (int) $order->amount_refunded !== (int) $settlement->amount
                || (int) $refunds->sum('amount') !== (int) $settlement->amount
                || $refunds->contains(fn ($row) => $row->currency !== $order->currency || (int) $row->amount < 0
                    || $row->completed_at === null || $row->attempt_id !== $settlement->payment_attempt_id)) {
                throw new DomainException('BONUS_PROMO_FULL_REFUND_REQUIRED');
            }
            $fingerprint = $this->fingerprint([(int) $order->id, (int) $order->user_id, $refundId,
                (int) $refund->amount, $refund->currency, (int) $settlement->id, (int) $settlement->amount]);
            $existing = PaymentAuditLog::query()->where('order_id', $order->id)->where('refund_id', $refundId)
                ->where('action', 'auto_purchase_promo_refund_applied')->first();
            if ($existing !== null) {
                $this->assertMarker($existing, $fingerprint);

                return $existing->meta['result'];
            }
            $issuance = PaymentAuditLog::query()->where('order_id', $order->id)->where('action', 'auto_purchase_promo_applied')->first();
            $promoId = $issuance?->meta['result']['promo_code_id'] ?? null;
            $result = ['status' => 'skipped_not_issued', 'promo_code_id' => null];
            if ($promoId !== null) {
                $promo = PromoCode::query()->lockForUpdate()->find($promoId);
                if ($promo === null || ($issuance->meta['version'] ?? null) !== 1
                    || ($issuance->meta['settlement_id'] ?? null) !== (int) $settlement->id
                    || ($issuance->meta['code_hash'] ?? null) !== $promo->code_hash
                    || $promo->rules_version !== 'auto-cash-promo-v1') {
                    throw new DomainException('BONUS_PROMO_EVIDENCE_CONFLICT');
                }
                // Never acquire the order that used the promotion while holding this source order/user.
                $used = PromoReservation::query()->where('promo_code_id', $promo->id)->where('status', 'committed')->exists();
                $status = $used ? 'promotion_violation_review' : 'revoked';
                $promo->update(['status' => $status]);
                $result = ['status' => $status, 'promo_code_id' => (int) $promo->id];
            }
            PaymentAuditLog::query()->create(['order_id' => $order->id, 'refund_id' => $refundId,
                'action' => 'auto_purchase_promo_refund_applied', 'actor_type' => 'system',
                'meta' => ['version' => 1, 'fingerprint' => $fingerprint, 'result' => $result]]);

            return $result;
        }, 3);
    }

    private function fingerprint(array $values): string
    {
        return hash('sha256', json_encode($values, JSON_THROW_ON_ERROR));
    }

    private function assertMarker(PaymentAuditLog $marker, string $fingerprint): void
    {
        if (($marker->meta['version'] ?? null) !== 1 || ($marker->meta['fingerprint'] ?? null) !== $fingerprint
            || ! is_array($marker->meta['result'] ?? null)) {
            throw new DomainException('BONUS_PROMO_EVIDENCE_CONFLICT');
        }
    }
}
