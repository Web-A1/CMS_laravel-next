<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LessonAssessmentManualReview extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';

    public const STATUS_DECIDED = 'decided';

    protected $fillable = [
        'lesson_assessment_attempt_id',
        'lesson_assessment_attempt_response_id',
        'question_id',
        'response_snapshot',
        'response_snapshot_sha256',
        'rubric_snapshot',
        'rubric_snapshot_sha256',
        'status',
        'score_bps',
        'comment',
        'reviewer_id',
        'revision',
        'decided_at',
    ];

    protected function casts(): array
    {
        return [
            'response_snapshot' => 'array',
            'rubric_snapshot' => 'array',
            'score_bps' => 'integer',
            'revision' => 'integer',
            'decided_at' => 'immutable_datetime',
        ];
    }

    public function attempt(): BelongsTo
    {
        return $this->belongsTo(LessonAssessmentAttempt::class, 'lesson_assessment_attempt_id');
    }

    public function response(): BelongsTo
    {
        return $this->belongsTo(LessonAssessmentAttemptResponse::class, 'lesson_assessment_attempt_response_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(LessonAssessmentReviewEvent::class);
    }
}
