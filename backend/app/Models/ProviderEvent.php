<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProviderEvent extends Model
{
    use HasFactory;

    protected $fillable = [
        'provider',
        'provider_event_id',
        'event_type',
        'order_id',
        'attempt_id',
        'signature_valid',
        'processed',
        'payload_raw',
        'received_at',
        'processed_at',
    ];

    protected function casts(): array
    {
        return [
            'payload_raw' => 'array',
            'signature_valid' => 'boolean',
            'processed' => 'boolean',
            'received_at' => 'datetime',
            'processed_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
