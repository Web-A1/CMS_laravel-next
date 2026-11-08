<?php

namespace App\Models;

use DomainException;
use Illuminate\Database\Eloquent\Model;

class CoinReservationAllocation extends Model
{
    protected $fillable = ['reservation_id', 'lot_id', 'units'];

    protected function casts(): array
    {
        return ['units' => 'integer'];
    }

    protected static function booted(): void
    {
        static::creating(function (self $entry): void {
            if (! is_int($entry->units) || $entry->units < 1) {
                throw new DomainException('BONUS_UNITS_INVALID');
            }
        });
        static::updating(static fn () => throw new DomainException('BONUS_LEDGER_IMMUTABLE'));
        static::deleting(static fn () => throw new DomainException('BONUS_LEDGER_IMMUTABLE'));
    }
}
