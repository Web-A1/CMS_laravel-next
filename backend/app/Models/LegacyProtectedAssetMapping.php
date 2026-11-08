<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LegacyProtectedAssetMapping extends Model
{
    use HasFactory;

    public const ASSET_KIND_FILE = 'legacy_file';

    public const ASSET_KIND_VIDEO = 'legacy_video';

    public const READINESS_BLOCKED = 'blocked';

    public const READINESS_IGNORED = 'ignored';

    public const READINESS_MAPPED_INTERNAL = 'mapped_internal';

    public const READINESS_MANUAL_REVIEW = 'manual_review';

    public const READINESS_NEEDS_MAPPING = 'needs_mapping';

    public const VALIDATION_FAILED = 'failed';

    public const VALIDATION_MANUAL_REVIEW = 'manual_review';

    public const VALIDATION_PASSED = 'passed';

    public const VALIDATION_PENDING = 'pending';

    protected $fillable = [
        'lesson_learning_content_id',
        'lesson_id',
        'source_table',
        'source_id',
        'source_scope_type',
        'source_scope_id',
        'asset_kind',
        'legacy_asset_id',
        'reference_count',
        'first_block_index',
        'readiness_status',
        'validation_status',
        'internal_asset_type',
        'internal_asset_id',
        'manual_review_reason',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'legacy_asset_id' => 'integer',
            'reference_count' => 'integer',
            'first_block_index' => 'integer',
            'internal_asset_id' => 'integer',
            'metadata' => 'array',
        ];
    }

    public function content(): BelongsTo
    {
        return $this->belongsTo(LessonLearningContent::class, 'lesson_learning_content_id');
    }

    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class, 'lesson_id');
    }
}
