<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TrustedBrowserSlot extends Model
{
    public const OCCUPIED_STATUSES = ['active', 'revoked_locked', 'risk_locked'];

    protected $fillable = [
        'account_id',
        'slot_number',
        'status',
        'current_generation',
        'locked_at',
        'released_at',
    ];

    protected function casts(): array
    {
        return [
            'slot_number' => 'integer',
            'current_generation' => 'integer',
            'locked_at' => 'datetime',
            'released_at' => 'datetime',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(TrustedBrowserAccount::class, 'account_id');
    }

    public function credentials(): HasMany
    {
        return $this->hasMany(TrustedBrowserCredential::class, 'slot_id');
    }
}
