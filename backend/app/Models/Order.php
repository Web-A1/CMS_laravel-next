<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Order extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'course_id',
        'currency',
        'amount_total',
        'amount_discount',
        'amount_final',
        'amount_paid',
        'amount_refunded',
        'payment_status',
        'access_status',
        'settlement_type',
        'active_attempt_id',
        'paid_at',
        'cancelled_at',
        'refunded_at',
        'settled_at',
    ];

    protected function casts(): array
    {
        return [
            'paid_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'refunded_at' => 'datetime',
            'settled_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function paymentAttempts(): HasMany
    {
        return $this->hasMany(PaymentAttempt::class);
    }

    public function activeAttempt(): BelongsTo
    {
        return $this->belongsTo(PaymentAttempt::class, 'active_attempt_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function settlements(): HasMany
    {
        return $this->hasMany(OrderSettlement::class);
    }
}
