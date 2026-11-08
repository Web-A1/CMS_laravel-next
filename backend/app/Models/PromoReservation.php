<?php

namespace App\Models;

use DomainException;
use Illuminate\Database\Eloquent\Model;

class PromoReservation extends Model
{
    protected $fillable = ['user_id', 'order_id', 'promo_code_id', 'idempotency_key', 'intent_hash', 'discount_minor', 'status', 'expires_at', 'committed_at', 'released_at', 'release_reason', 'proof'];

    protected function casts(): array
    {
        return ['expires_at' => 'immutable_datetime', 'committed_at' => 'immutable_datetime', 'released_at' => 'immutable_datetime', 'discount_minor' => 'integer', 'proof' => 'array'];
    }

    protected static function booted(): void
    {
        static::updating(function (self $reservation): void {
            if ($reservation->isDirty(['user_id', 'order_id', 'promo_code_id', 'idempotency_key', 'intent_hash', 'discount_minor', 'proof', 'expires_at', 'created_at'])) {
                throw new DomainException('BONUS_RESERVATION_INTENT_IMMUTABLE');
            }
        });
        static::deleting(static fn () => throw new DomainException('BONUS_RESERVATION_INTENT_IMMUTABLE'));
    }
}
