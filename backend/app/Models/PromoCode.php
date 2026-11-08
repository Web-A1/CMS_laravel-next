<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class PromoCode extends Model
{
    use SoftDeletes;

    protected static function booted(): void
    {
        static::updating(function (self $promo): void {
            if ($promo->isDirty('created_by')) {
                throw new \LogicException('Promo creator cannot change.');
            }
        });
    }

    protected $fillable = ['code_hash', 'status', 'discount_kind', 'discount_minor', 'discount_bps', 'currency', 'starts_at', 'expires_at', 'activation_limit', 'per_user_limit', 'bound_email_hash', 'rules_version'];

    protected function casts(): array
    {
        return ['created_by' => 'integer', 'starts_at' => 'immutable_datetime', 'expires_at' => 'immutable_datetime', 'discount_minor' => 'integer', 'discount_bps' => 'integer', 'activation_limit' => 'integer', 'per_user_limit' => 'integer'];
    }

    public function reservations(): HasMany
    {
        return $this->hasMany(PromoReservation::class);
    }
}
