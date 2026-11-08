<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TrustedBrowserSessionLink extends Model
{
    protected $fillable = [
        'account_id',
        'slot_id',
        'credential_id',
        'session_id',
        'bound_at',
        'last_seen_at',
        'revoked_at',
    ];

    protected $hidden = [
        'session_id',
    ];

    protected function casts(): array
    {
        return [
            'bound_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }
}
