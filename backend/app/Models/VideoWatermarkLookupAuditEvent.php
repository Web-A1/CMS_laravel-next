<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

final class VideoWatermarkLookupAuditEvent extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'actor_user_id',
        'watermark_session_id',
        'normalized_code',
        'result',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        self::updating(static fn (): never => throw new LogicException('Video watermark lookup audit events are append-only.'));
        self::deleting(static fn (): never => throw new LogicException('Video watermark lookup audit events are append-only.'));
    }
}
