<?php

namespace App\Services\Bonuses;

use App\Models\CoinLedgerEntry;
use App\Models\CoinReservation;
use App\Models\CoinReservationAllocation;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;

class CoinLedgerService
{
    public function __construct(private readonly BonusReservationContext $context) {}

    public function balance(int $userId): int
    {
        return DB::transaction(function () use ($userId): int {
            User::query()->lockForUpdate()->findOrFail($userId);

            return array_sum(array_column($this->lots($userId), 'available'));
        });
    }

    public function quote(int $userId, int $orderId, int $units): array
    {
        return DB::transaction(function () use ($userId, $orderId, $units): array {
            [$order] = $this->context->lock($userId, $orderId);
            $this->context->assertReservable($order);
            $this->context->assertNoDiscountReservation($orderId);
            $purchase = $this->context->purchase($order);
            $this->assertUnits($units);
            $price = $this->price($purchase, $units);
            $allocations = $this->allocate($userId, $units);
            $expires = now()->addMinutes(30);
            foreach ($allocations as $allocation) {
                if ($allocation['lot']->expires_at !== null && $allocation['lot']->expires_at->lessThan($expires)) {
                    $expires = $allocation['lot']->expires_at;
                }
            }

            return [...$price,
                'expires_at' => $expires->toIso8601String(),
                'calculated_at' => now()->toIso8601String(), 'calculated_by' => 'backend', 'reservation_required' => true];
        });
    }

    /** Unit reservation is not monetary settlement proof without a priced quote. */
    public function reserve(int $userId, int $orderId, int $units, string $idempotencyKey): CoinReservation
    {
        return $this->reserveInternal($userId, $orderId, $units, $idempotencyKey, false, null);
    }

    /** Persists a priced reserve, not a payment or an order discount mutation. */
    public function reservePriced(int $userId, int $orderId, int $units, string $idempotencyKey, ?string $expectedQuoteHash = null): CoinReservation
    {
        if ($expectedQuoteHash !== null && ! preg_match('/^[a-f0-9]{64}$/D', $expectedQuoteHash)) {
            throw new DomainException('BONUS_QUOTE_HASH_INVALID');
        }

        return $this->reserveInternal($userId, $orderId, $units, $idempotencyKey, true, $expectedQuoteHash);
    }

    private function reserveInternal(int $userId, int $orderId, int $units, string $idempotencyKey, bool $priced, ?string $expectedQuoteHash): CoinReservation
    {
        $this->assertUnits($units);
        $this->context->assertKey($idempotencyKey);

        return DB::transaction(function () use ($userId, $orderId, $units, $idempotencyKey, $priced, $expectedQuoteHash): CoinReservation {
            [$order] = $this->context->lock($userId, $orderId);
            $purchase = $this->context->purchase($order);
            $hash = hash('sha256', json_encode($priced ? ['priced-v1', $purchase, $units] : [$purchase, $units], JSON_THROW_ON_ERROR));
            $existing = CoinReservation::query()->where('idempotency_key', $idempotencyKey)->lockForUpdate()->first();
            if ($existing) {
                if (! hash_equals($existing->intent_hash, $hash)) {
                    throw new DomainException('BONUS_IDEMPOTENCY_CONFLICT');
                }
                $this->assertExpectedQuote($existing->proof, $expectedQuoteHash);

                return $existing;
            }
            $this->context->assertReservable($order);
            $this->context->assertNoDiscountReservation($orderId);
            $price = $priced ? $this->price($purchase, $units) : null;
            if ($priced) {
                $this->assertExpectedQuote($price, $expectedQuoteHash);
            }
            $allocations = $this->allocate($userId, $units);
            $expires = now()->addMinutes(30);
            foreach ($allocations as $allocation) {
                if ($allocation['lot']->expires_at !== null && $allocation['lot']->expires_at->lessThan($expires)) {
                    $expires = $allocation['lot']->expires_at;
                }
            }
            $proof = $priced
                ? [...$price, 'operation' => 'priced-v1', 'monetary_pricing_verified' => true,
                    'expires_at' => $expires->toIso8601String(), 'calculated_at' => now()->toIso8601String(),
                    'calculated_by' => 'backend', 'reservation_required' => false, 'settlement_confirmed' => false]
                : [...$purchase, 'discount_type' => 'coins', 'coins_units' => $units,
                    'bonus_rules_version' => 'coin-reservation-v1', 'monetary_pricing_verified' => false];
            if ($priced) {
                $proof['proof_hash'] = hash('sha256', json_encode($proof, JSON_THROW_ON_ERROR));
            }
            $reservation = CoinReservation::query()->createOrFirst(['idempotency_key' => $idempotencyKey], [
                'user_id' => $userId, 'order_id' => $orderId, 'units' => $units,
                'intent_hash' => $hash, 'status' => 'reserved',
                'expires_at' => $expires,
                'proof' => $proof,
            ]);
            if (! $reservation->wasRecentlyCreated) {
                if (! hash_equals($reservation->intent_hash, $hash)) {
                    throw new DomainException('BONUS_IDEMPOTENCY_CONFLICT');
                }
                $this->assertExpectedQuote($reservation->proof, $expectedQuoteHash);

                return $reservation;
            }
            foreach ($allocations as $allocation) {
                CoinReservationAllocation::query()->create(['reservation_id' => $reservation->id,
                    'lot_id' => $allocation['lot']->id, 'units' => $allocation['units']]);
                $this->entry($reservation, (int) $allocation['lot']->id, $allocation['units'], 'coins_reserve');
            }

            return $reservation;
        }, 3);
    }

    /** Hash covers economic terms only; quote is not a capacity or TTL reservation. */
    private function price(array $purchase, int $units): array
    {
        $rate = config('bonuses.coin_minor_units');
        if ($rate !== 100) {
            throw new DomainException('BONUS_COIN_CONVERSION_UNCONFIGURED');
        }
        if ($units > intdiv($purchase['subtotal'], $rate)) {
            throw new DomainException('BONUS_COINS_EXCEED_ORDER');
        }
        $price = [...$purchase, 'discount_type' => 'coins', 'coins_units' => $units,
            'coins_writeoff' => $units * $rate, 'promo_discount' => 0,
            'payable_total' => $purchase['subtotal'] - $units * $rate,
            'coin_minor_units' => $rate, 'bonus_rules_version' => 'coin-priced-v1',
            'settlement_type' => $purchase['subtotal'] === $units * $rate ? 'settled_zero_cash' : 'provider_payment'];

        return [...$price, 'quote_hash' => hash('sha256', json_encode($price, JSON_THROW_ON_ERROR))];
    }

    private function assertExpectedQuote(array $proof, ?string $expected): void
    {
        if ($expected !== null && (! is_string($proof['quote_hash'] ?? null) || ! hash_equals($proof['quote_hash'], $expected))) {
            throw new DomainException('BONUS_QUOTE_CHANGED');
        }
    }

    public function commit(int $userId, int $reservationId): CoinReservation
    {
        return $this->transition($userId, $reservationId, true, null);
    }

    public function release(int $userId, int $reservationId, string $reason): CoinReservation
    {
        if (! in_array($reason, ['cancelled', 'failed', 'expired', 'abandoned'], true)) {
            throw new DomainException('BONUS_RELEASE_REASON_INVALID');
        }

        return $this->transition($userId, $reservationId, false, $reason);
    }

    private function transition(int $userId, int $id, bool $commit, ?string $reason): CoinReservation
    {
        $reference = CoinReservation::query()->findOrFail($id);

        return DB::transaction(function () use ($userId, $id, $reference, $commit, $reason): CoinReservation {
            [$order] = $this->context->lock($userId, (int) $reference->order_id);
            $reservation = CoinReservation::query()->lockForUpdate()->findOrFail($id);
            if ((int) $reservation->user_id !== $userId || (int) $reservation->order_id !== (int) $order->id) {
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
            } elseif ($reason === 'expired' && $reservation->expires_at->greaterThan(now())) {
                throw new DomainException('BONUS_RESERVATION_NOT_EXPIRED');
            }
            $allocations = CoinReservationAllocation::query()->where('reservation_id', $id)->orderBy('id')->get();
            if ((int) $allocations->sum('units') !== $reservation->units) {
                throw new DomainException('BONUS_LEDGER_INCONSISTENT');
            }
            foreach ($allocations as $allocation) {
                $lot = CoinLedgerEntry::query()->findOrFail($allocation->lot_id);
                if ((int) $lot->user_id !== $userId || $lot->event_type !== 'coins_accrual'
                    || ($commit && CoinLedgerEntry::query()->where('lot_id', $lot->id)->where('event_type', 'coins_revoke')->exists())
                    || ($commit && $lot->expires_at !== null && $lot->expires_at->lessThanOrEqualTo(now()))) {
                    throw new DomainException('BONUS_LEDGER_INCONSISTENT');
                }
                $this->entry($reservation, (int) $allocation->lot_id, $allocation->units, $commit ? 'coins_commit' : 'coins_release');
            }
            $reservation->update(['status' => $target, $commit ? 'committed_at' : 'released_at' => now(),
                'release_reason' => $reason]);

            return $reservation->fresh();
        }, 3);
    }

    private function lots(int $userId): array
    {
        $lots = CoinLedgerEntry::query()->where('user_id', $userId)->where('event_type', 'coins_accrual')
            ->whereNotIn('id', CoinLedgerEntry::query()->select('lot_id')->where('event_type', 'coins_revoke')->whereNotNull('lot_id'))
            ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->orderBy('created_at')->orderBy('id')->get();
        $result = [];
        foreach ($lots as $lot) {
            $committed = (int) CoinLedgerEntry::query()->where('lot_id', $lot->id)->where('event_type', 'coins_commit')->sum('units');
            $reserved = (int) CoinLedgerEntry::query()->where('lot_id', $lot->id)->where('event_type', 'coins_reserve')
                ->whereIn('reservation_id', CoinReservation::query()->select('id')->where('user_id', $userId)
                    ->where('status', 'reserved')->where('expires_at', '>', now()))->sum('units');
            $returned = (int) CoinLedgerEntry::query()->where('lot_id', $lot->id)->where('event_type', 'coins_refund')->sum('units');
            $debtRepaid = (int) CoinLedgerEntry::query()->where('lot_id', $lot->id)->where('event_type', 'coins_debt_repay')->sum('units');
            if ($returned > $committed) {
                throw new DomainException('BONUS_LEDGER_INCONSISTENT');
            }
            $available = $lot->units - $committed + $returned - $debtRepaid - $reserved;
            if ($available < 0) {
                throw new DomainException('BONUS_LEDGER_INCONSISTENT');
            }
            $result[] = ['lot' => $lot, 'available' => $available];
        }

        return $result;
    }

    private function allocate(int $userId, int $units): array
    {
        $remaining = $units;
        $allocations = [];
        foreach ($this->lots($userId) as $row) {
            $take = min($remaining, $row['available']);
            if ($take > 0) {
                $allocations[] = ['lot' => $row['lot'], 'units' => $take];
                $remaining -= $take;
            }
            if ($remaining === 0) {
                return $allocations;
            }
        }
        throw new DomainException('BONUS_INSUFFICIENT_COINS');
    }

    private function entry(CoinReservation $reservation, int $lotId, int $units, string $type): void
    {
        CoinLedgerEntry::query()->create(['user_id' => $reservation->user_id, 'order_id' => $reservation->order_id,
            'reservation_id' => $reservation->id, 'lot_id' => $lotId, 'event_type' => $type, 'units' => $units,
            'idempotency_key' => "reservation:{$reservation->id}:lot:{$lotId}:{$type}"]);
    }

    private function assertUnits(int $units): void
    {
        if ($units < 1) {
            throw new DomainException('BONUS_UNITS_INVALID');
        }
    }
}
