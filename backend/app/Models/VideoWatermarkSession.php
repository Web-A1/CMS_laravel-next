<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class VideoWatermarkSession extends Model
{
    public const END_REASON_LOGOUT = 'logout';

    public const END_REASON_IDLE_TIMEOUT = 'idle_timeout';

    public const END_REASON_REVOKED = 'revoked';

    public const END_REASON_ORPHAN_EXPIRED = 'orphan_expired';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_ENDED = 'ended';

    public const STATUS_EXPIRED = 'expired';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'id',
        'code',
        'auth_session_hash',
        'user_id',
        'started_at',
        'last_used_at',
        'ended_at',
        'end_reason',
        'purge_after',
    ];

    protected $hidden = ['auth_session_hash'];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'last_used_at' => 'datetime',
            'ended_at' => 'datetime',
            'purge_after' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function computedStatus(): string
    {
        if ($this->ended_at === null) {
            return self::STATUS_ACTIVE;
        }

        if ($this->purge_after !== null && ! $this->purge_after->isFuture()) {
            return self::STATUS_EXPIRED;
        }

        return self::STATUS_ENDED;
    }
}
