<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LessonAssessmentVersion extends Model
{
    use HasFactory;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_PUBLISHED = 'published';

    public const STATUS_RETIRED = 'retired';

    protected $fillable = [
        'lesson_assessment_id',
        'schema_version',
        'content_version',
        'scoring_policy',
        'source_assignment_sha256',
        'source_key_sha256',
        'sections',
        'questions',
        'answer_key',
        'published_at',
    ];

    protected function casts(): array
    {
        return [
            'schema_version' => 'integer',
            'content_version' => 'integer',
            'scoring_policy' => 'array',
            'sections' => 'array',
            'questions' => 'array',
            'answer_key' => 'array',
            'published_at' => 'immutable_datetime',
        ];
    }

    public function assessment(): BelongsTo
    {
        return $this->belongsTo(LessonAssessment::class, 'lesson_assessment_id');
    }

    public function assets(): HasMany
    {
        return $this->hasMany(LessonAssessmentAsset::class);
    }
}
