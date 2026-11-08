<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProtectedFileDelivery extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_READY = 'ready';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'public_id',
        'user_id',
        'lesson_material_file_id',
        'source_sha256',
        'copy_code',
        'status',
        'attempt_count',
        'lease_token',
        'lease_expires_at',
        'output_object_key',
        'output_sha256',
        'output_size_bytes',
        'last_error_code',
        'ready_at',
        'failed_at',
    ];

    protected $hidden = [
        'user_id',
        'lesson_material_file_id',
        'source_sha256',
        'copy_code',
        'lease_token',
        'lease_expires_at',
        'output_object_key',
        'output_sha256',
        'output_size_bytes',
        'last_error_code',
    ];

    protected $attributes = [
        'status' => self::STATUS_PENDING,
        'attempt_count' => 0,
    ];

    protected function casts(): array
    {
        return [
            'attempt_count' => 'integer',
            'lease_expires_at' => 'immutable_datetime',
            'output_size_bytes' => 'integer',
            'ready_at' => 'immutable_datetime',
            'failed_at' => 'immutable_datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(LessonMaterialFile::class, 'lesson_material_file_id');
    }

    public function isReady(): bool
    {
        return $this->status === self::STATUS_READY;
    }
}
