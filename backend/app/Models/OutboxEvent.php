<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OutboxEvent extends Model
{
    use HasFactory;

    protected $fillable = [
        'event_type',
        'order_id',
        'attempt_id',
        'refund_id',
        'status',
        'retry_count',
        'available_at',
        'processed_at',
        'payload',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'available_at' => 'datetime',
            'processed_at' => 'datetime',
        ];
    }
}
