<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TrustedBrowserChallenge extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'account_id',
        'user_id',
        'purpose',
        'target_slot_id',
        'initiated_by_user_id',
        'approved_by_user_id',
        'phone_verification_code_id',
        'session_hash',
        'email_hash',
        'code_hash',
        'attempts_left',
        'max_attempts',
        'status',
        'expires_at',
        'verified_at',
        'used_at',
        'approved_at',
        'phone_proof_bound_at',
        'send_ip_hash',
        'last_verify_ip_hash',
        'case_id',
        'reason',
        'idempotency_key_hash',
        'metadata',
        'result_payload',
    ];

    protected $hidden = [
        'code_hash',
        'email_hash',
        'session_hash',
        'idempotency_key_hash',
        'send_ip_hash',
        'last_verify_ip_hash',
    ];

    protected function casts(): array
    {
        return [
            'attempts_left' => 'integer',
            'max_attempts' => 'integer',
            'expires_at' => 'datetime',
            'verified_at' => 'datetime',
            'used_at' => 'datetime',
            'approved_at' => 'datetime',
            'phone_proof_bound_at' => 'datetime',
            'metadata' => 'array',
            'result_payload' => 'array',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(TrustedBrowserAccount::class, 'account_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function targetSlot(): BelongsTo
    {
        return $this->belongsTo(TrustedBrowserSlot::class, 'target_slot_id');
    }
}
