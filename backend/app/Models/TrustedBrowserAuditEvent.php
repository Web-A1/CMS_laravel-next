<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class TrustedBrowserAuditEvent extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'account_id',
        'user_id',
        'actor_user_id',
        'slot_id',
        'credential_id',
        'challenge_id',
        'event_type',
        'mode',
        'state',
        'session_hash',
        'ip_hash',
        'metadata',
        'created_at',
    ];

    protected $hidden = [
        'session_hash',
        'ip_hash',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(static function (): never {
            throw new LogicException('Trusted browser audit events are append-only.');
        });

        static::deleting(static function (): never {
            throw new LogicException('Trusted browser audit events are append-only.');
        });
    }
}
