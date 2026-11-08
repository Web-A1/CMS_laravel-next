<?php

namespace App\Models;

use DomainException;
use Illuminate\Database\Eloquent\Model;

class CoinLedgerEntry extends Model
{
    protected $fillable = ['user_id', 'order_id', 'reservation_id', 'lot_id', 'event_type', 'units', 'idempotency_key', 'expires_at'];

    protected function casts(): array
    {
        return ['expires_at' => 'immutable_datetime', 'units' => 'integer'];
    }

    protected static function booted(): void
    {
        static::creating(function (self $entry): void {
            if (! is_int($entry->units) || $entry->units < 1) {
                throw new DomainException('BONUS_UNITS_INVALID');
            }
            $allocationEvents = ['coins_reserve', 'coins_commit', 'coins_release', 'coins_refund'];
            $accountingEvents = ['coins_revoke', 'coins_debt', 'coins_debt_repay'];
            if (! in_array($entry->event_type, ['coins_accrual', ...$allocationEvents, ...$accountingEvents], true)
                || ($entry->event_type === 'coins_accrual' && ($entry->lot_id !== null || $entry->reservation_id !== null))
                || ($entry->event_type !== 'coins_accrual' && ($entry->lot_id === null || $entry->order_id === null))
                || (in_array($entry->event_type, $allocationEvents, true) && $entry->reservation_id === null)
                || (in_array($entry->event_type, $accountingEvents, true) && $entry->reservation_id !== null)) {
                throw new DomainException('BONUS_LEDGER_EVENT_INVALID');
            }
        });
        static::updating(static fn () => throw new DomainException('BONUS_LEDGER_IMMUTABLE'));
        static::deleting(static fn () => throw new DomainException('BONUS_LEDGER_IMMUTABLE'));
    }
}
