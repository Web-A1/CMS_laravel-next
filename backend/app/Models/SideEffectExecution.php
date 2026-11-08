<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SideEffectExecution extends Model
{
    use HasFactory;

    protected $fillable = [
        'outbox_event_id',
        'order_id',
        'attempt_id',
        'refund_id',
        'handler',
        'dedup_key',
        'status',
        'meta',
        'processed_at',
    ];

    protected function casts(): array
    {
        return [
            'meta' => 'array',
            'processed_at' => 'datetime',
        ];
    }
}
