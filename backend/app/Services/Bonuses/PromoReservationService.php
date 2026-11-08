<?php

namespace App\Services\Bonuses;

use App\Models\Order;
use App\Models\PromoCode;
use App\Models\PromoReservation;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;

class PromoReservationService
{
    public function __construct(private readonly BonusReservationContext $context) {}

    public static function codeHash(string $code): string
    {
        if (! preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $code)) {
            throw new DomainException('BONUS_PROMO_INVALID');
        }

        return hash('sha256', $code);
    }

    public function quote(int $userId, int $orderId, string $code): array
    {
        $hash = self::codeHash($code);

        return DB::transaction(function () use ($userId, $orderId, $hash): array {
            [$order, $user] = $this->context->lock($userId, $orderId);
            $promo = PromoCode::query()->where('code_hash', $hash)->lockForUpdate()->first();
            $this->context->assertReservable($order);
            $this->context->assertNoDiscountReservation($orderId);

            return $this->proof($order, $user, $promo);
        }, 3);
    }

    public function reserve(int $userId, int $orderId, string $code, string $idempotencyKey): PromoReservation
    {
        return $this->reserveInternal($userId, $orderId, $code, $idempotencyKey, false, null);
    }

    public function quotePriced(int $userId, int $orderId, string $code): array
    {
        return $this->pricedProof($this->quote($userId, $orderId, $code));
    }

    public function reservePriced(int $userId, int $orderId, string $code, string $idempotencyKey, ?string $expectedQuoteHash = null): PromoReservation
    {
        return $this->reserveInternal($userId, $orderId, $code, $idempotencyKey, true, $expectedQuoteHash);
    }

    private function reserveInternal(int $userId, int $orderId, string $code, string $idempotencyKey, bool $priced, ?string $expectedQuoteHash): PromoReservation
    {
        $codeHash = self::codeHash($code);
        $this->context->assertKey($idempotencyKey);

        return DB::transaction(function () use ($userId, $orderId, $codeHash, $idempotencyKey, $priced, $expectedQuoteHash): PromoReservation {
            [$order, $user] = $this->context->lock($userId, $orderId);
            $promo = PromoCode::query()->where('code_hash', $codeHash)->lockForUpdate()->first();
            $purchase = $this->context->purchase($order);
            $hash = hash('sha256', json_encode($priced ? ['promo-priced-v1', $purchase, $codeHash] : [$purchase, $codeHash], JSON_THROW_ON_ERROR));
            $existing = PromoReservation::query()->where('idempotency_key', $idempotencyKey)->lockForUpdate()->first();
            if ($existing) {
                if (! hash_equals($existing->intent_hash, $hash)
                    || ($expectedQuoteHash !== null && ($existing->proof['quote_hash'] ?? null) !== $expectedQuoteHash)) {
                    throw new DomainException('BONUS_IDEMPOTENCY_CONFLICT');
                }

                return $existing;
            }
            $this->context->assertReservable($order);
            $this->context->assertNoDiscountReservation($orderId);
            $proof = $this->proof($order, $user, $promo);
            if ($priced) {
                $proof = $this->pricedProof($proof);
                if ($expectedQuoteHash !== null && ! hash_equals($proof['quote_hash'], $expectedQuoteHash)) {
                    throw new DomainException('BONUS_QUOTE_CHANGED');
                }
            }

            $reservation = PromoReservation::query()->createOrFirst(['idempotency_key' => $idempotencyKey], [
                'user_id' => $userId, 'order_id' => $orderId, 'promo_code_id' => $promo->id,
                'intent_hash' => $hash,
                'discount_minor' => $proof['promo_discount'], 'status' => 'reserved',
                'expires_at' => $proof['expires_at'], 'proof' => $proof,
            ]);
            if (! $reservation->wasRecentlyCreated && (! hash_equals($reservation->intent_hash, $hash)
                || ($expectedQuoteHash !== null && ($reservation->proof['quote_hash'] ?? null) !== $expectedQuoteHash))) {
                throw new DomainException('BONUS_IDEMPOTENCY_CONFLICT');
            }

            return $reservation;
        }, 3);
    }

    public function commit(int $userId, int $reservationId): PromoReservation
    {
        return $this->transition($userId, $reservationId, true, null);
    }

    /** Caller holds ORDER then USER; validates the reservation without consuming it. */
    public function assertReserved(Order $order, int $reservationId): void
    {
        $reference = PromoReservation::query()->find($reservationId);
        if ($reference === null || (int) $reference->order_id !== (int) $order->id
            || (int) $reference->user_id !== (int) $order->user_id) {
            throw new DomainException('BONUS_ORDER_OWNER_MISMATCH');
        }
        $promo = PromoCode::withTrashed()->lockForUpdate()->find($reference->promo_code_id);
        $reservation = PromoReservation::query()->lockForUpdate()->find($reservationId);
        if ($promo === null || $reservation->status !== 'reserved' || $reservation->expires_at->lessThanOrEqualTo(now())) {
            throw new DomainException('BONUS_RESERVATION_EXPIRED');
        }
        $this->context->assertPurchase($order, $reservation->proof);
        $this->assertPromo($promo, User::query()->findOrFail($order->user_id), $order);
        if (($reservation->proof['promo_terms_hash'] ?? null) !== $this->termsHash($promo)) {
            throw new DomainException('BONUS_PROMO_TERMS_CHANGED');
        }
    }

    private function pricedProof(array $proof): array
    {
        $proof['operation'] = 'promo-priced-v1';
        $proof['monetary_pricing_verified'] = true;
        $quote = $proof;
        unset($quote['expires_at'], $quote['calculated_at']);
        $proof['quote_hash'] = hash('sha256', json_encode($quote, JSON_THROW_ON_ERROR));
        $proof['proof_hash'] = hash('sha256', json_encode($proof, JSON_THROW_ON_ERROR));

        return $proof;
    }

    public function release(int $userId, int $reservationId, string $reason): PromoReservation
    {
        if (! in_array($reason, ['cancelled', 'failed', 'expired', 'abandoned'], true)) {
            throw new DomainException('BONUS_RELEASE_REASON_INVALID');
        }

        return $this->transition($userId, $reservationId, false, $reason);
    }

    private function transition(int $userId, int $id, bool $commit, ?string $reason): PromoReservation
    {
        $reference = PromoReservation::query()->findOrFail($id);

        return DB::transaction(function () use ($userId, $id, $reference, $commit, $reason): PromoReservation {
            [$order, $user] = $this->context->lock($userId, (int) $reference->order_id);
            $promo = PromoCode::withTrashed()->lockForUpdate()->findOrFail($reference->promo_code_id);
            $reservation = PromoReservation::query()->lockForUpdate()->findOrFail($id);
            if ((int) $reservation->user_id !== $userId || (int) $reservation->order_id !== (int) $order->id
                || (int) $reservation->promo_code_id !== (int) $promo->id) {
                throw new DomainException('BONUS_ORDER_OWNER_MISMATCH');
            }
            $target = $commit ? 'committed' : 'released';
            if ($reservation->status === $target) {
                if (! $commit && $reservation->release_reason !== $reason) {
                    throw new DomainException('BONUS_IDEMPOTENCY_CONFLICT');
                }

                return $reservation;
            }
            if ($reservation->status !== 'reserved') {
                throw new DomainException('BONUS_RESERVATION_TERMINAL');
            }
            if ($commit) {
                $this->context->assertPurchase($order, $reservation->proof);
                if ($reservation->expires_at->lessThanOrEqualTo(now())) {
                    throw new DomainException('BONUS_RESERVATION_EXPIRED');
                }
                if (! in_array($order->payment_status, ['draft', 'pending_payment'], true)) {
                    throw new DomainException('BONUS_ORDER_ALREADY_SETTLED');
                }
                $this->assertPromo($promo, $user, $order);
                $currentHash = $this->termsHash($promo);
                if (($reservation->proof['promo_terms_hash'] ?? null) !== $currentHash) {
                    throw new DomainException('BONUS_PROMO_TERMS_CHANGED');
                }
            } elseif ($reason === 'expired' && $reservation->expires_at->greaterThan(now())) {
                throw new DomainException('BONUS_RESERVATION_NOT_EXPIRED');
            }
            $reservation->update(['status' => $target, $commit ? 'committed_at' : 'released_at' => now(),
                'release_reason' => $reason]);

            return $reservation->fresh();
        }, 3);
    }

    private function proof(Order $order, User $user, ?PromoCode $promo): array
    {
        if ($promo === null) {
            throw new DomainException('BONUS_PROMO_INVALID');
        }
        $this->assertPromo($promo, $user, $order);
        $active = PromoReservation::query()->where('promo_code_id', $promo->id)
            ->where(fn ($q) => $q->where('status', 'committed')->orWhere(fn ($q) => $q->where('status', 'reserved')->where('expires_at', '>', now())));
        if ((clone $active)->count() >= $promo->activation_limit
            || (clone $active)->where('user_id', $user->id)->count() >= $promo->per_user_limit) {
            throw new DomainException('BONUS_PROMO_LIMIT_REACHED');
        }
        $purchase = $this->context->purchase($order);
        if ($promo->discount_kind === 'fixed_minor' && is_int($promo->discount_minor) && $promo->discount_minor > 0) {
            $discount = min($purchase['subtotal'], $promo->discount_minor);
        } elseif ($promo->discount_kind === 'percent_bps' && is_int($promo->discount_bps)
            && $promo->discount_bps >= 1 && $promo->discount_bps <= 10000) {
            // Round to nearest minor unit, half up. Split before multiplying to avoid overflow.
            $discount = intdiv($purchase['subtotal'], 10000) * $promo->discount_bps
                + intdiv(($purchase['subtotal'] % 10000) * $promo->discount_bps + 5000, 10000);
        } else {
            throw new DomainException('BONUS_PROMO_INVALID');
        }
        $expires = now()->addMinutes(30)->min($promo->expires_at);

        return [...$purchase, 'discount_type' => 'promo', 'promo_code_id' => (int) $promo->id,
            'promo_discount' => $discount, 'coins_writeoff' => 0, 'payable_total' => $purchase['subtotal'] - $discount,
            'promo_rules_version' => $promo->rules_version, 'promo_terms_hash' => $this->termsHash($promo),
            'discount_rounding' => $promo->discount_kind === 'percent_bps' ? 'minor_half_up_v1' : 'exact_minor',
            'expires_at' => $expires->toIso8601String(), 'calculated_at' => now()->toIso8601String(),
            'calculated_by' => 'backend', 'reservation_required' => true];
    }

    private function assertPromo(PromoCode $promo, User $user, Order $order): void
    {
        if ($promo->status !== 'active' || $promo->currency !== $order->currency
            || $promo->expires_at === null || $promo->expires_at->lessThanOrEqualTo(now())
            || ($promo->starts_at !== null && $promo->starts_at->greaterThan(now()))
            || $promo->activation_limit < 1 || $promo->per_user_limit < 1 || ! $promo->rules_version
            || ($promo->bound_email_hash !== null && (! is_string($user->email) || $user->email === ''
                || ! hash_equals($promo->bound_email_hash, hash('sha256', mb_strtolower(trim($user->email))))))) {
            throw new DomainException('BONUS_PROMO_INVALID');
        }
    }

    private function termsHash(PromoCode $promo): string
    {
        return hash('sha256', json_encode($promo->only(['id', 'code_hash', 'discount_kind', 'discount_minor',
            'discount_bps', 'currency', 'starts_at', 'expires_at', 'activation_limit', 'per_user_limit',
            'bound_email_hash', 'rules_version']), JSON_THROW_ON_ERROR));
    }
}
