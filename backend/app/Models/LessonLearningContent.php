<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LessonLearningContent extends Model
{
    use HasFactory;

    public const FORMAT_LESSON_BLOCKS_V1 = 'lesson_blocks_v1';

    public const IMPORT_STATUS_IMPORTED = 'imported';

    public const IMPORT_STATUS_MANUAL = 'manual';

    public const IMPORT_STATUS_MANUAL_REVIEW = 'manual_review';

    public const STATUS_ARCHIVED = 'archived';

    public const STATUS_DRAFT = 'draft';

    public const STATUS_PUBLISHED = 'published';

    protected $fillable = [
        'lesson_id',
        'status',
        'format',
        'version',
        'body',
        'published_at',
        'source_table',
        'source_id',
        'source_fingerprint_hash',
        'import_status',
        'manual_review_reason',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'body' => 'array',
            'metadata' => 'array',
            'published_at' => 'datetime',
        ];
    }

    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class, 'lesson_id');
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query
            ->where('status', self::STATUS_PUBLISHED)
            ->where('format', self::FORMAT_LESSON_BLOCKS_V1);
    }
}
