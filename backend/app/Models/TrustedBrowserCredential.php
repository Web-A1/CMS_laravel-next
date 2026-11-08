<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TrustedBrowserCredential extends Model
{
    protected $fillable = [
        'slot_id',
        'generation',
        'selector',
        'secret_hash',
        'expires_at',
        'last_refreshed_at',
        'last_seen_at',
        'invalidated_at',
        'invalidation_reason',
        'browser_label',
        'os_label',
        'device_label',
        'user_agent_hash',
        'issued_ip_hash',
        'last_ip_hash',
    ];

    protected $hidden = [
        'secret_hash',
        'selector',
    ];

    protected function casts(): array
    {
        return [
            'generation' => 'integer',
            'expires_at' => 'datetime',
            'last_refreshed_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'invalidated_at' => 'datetime',
        ];
    }

    public function slot(): BelongsTo
    {
        return $this->belongsTo(TrustedBrowserSlot::class, 'slot_id');
    }
}
