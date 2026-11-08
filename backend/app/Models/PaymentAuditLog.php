<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PaymentAuditLog extends Model
{
    use HasFactory;

    public const UPDATED_AT = null;

    protected $table = 'payment_audit_log';

    protected $fillable = [
        'order_id',
        'attempt_id',
        'event_id',
        'refund_id',
        'action',
        'from_status',
        'to_status',
        'actor_type',
        'actor_id',
        'reason',
        'meta',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'meta' => 'array',
            'created_at' => 'datetime',
        ];
    }
}
