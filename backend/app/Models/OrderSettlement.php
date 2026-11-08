<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderSettlement extends Model
{
    use HasFactory;

    public const STATUS_CONFIRMED = 'confirmed';

    public const TYPE_BACKEND_CONFIRMATION = 'backend_confirmation';

    public const TYPE_PROVIDER_PAYMENT = 'provider_payment';

    public const TYPE_SETTLED_ZERO_CASH = 'settled_zero_cash';

    protected $fillable = [
        'order_id',
        'payment_attempt_id',
        'provider_event_id',
        'settlement_type',
        'idempotency_key',
        'amount',
        'currency',
        'status',
        'fingerprint_hash',
        'confirmed_at',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'confirmed_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function paymentAttempt(): BelongsTo
    {
        return $this->belongsTo(PaymentAttempt::class);
    }

    public function providerEvent(): BelongsTo
    {
        return $this->belongsTo(ProviderEvent::class);
    }
}
