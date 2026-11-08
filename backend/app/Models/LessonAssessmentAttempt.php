<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LessonAssessmentAttempt extends Model
{
    use HasFactory;

    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_FAILED = 'failed';

    public const STATUS_PENDING_REVIEW = 'pending_review';

    public const STATUS_PASSED = 'passed';

    public const FAILURE_AUTOMATIC_THRESHOLD = 'automatic_threshold_not_met';

    public const FAILURE_MANUAL_THRESHOLD = 'manual_threshold_not_met';

    public const FAILURE_COMBINED_THRESHOLD = 'combined_threshold_not_met';

    protected $fillable = [
        'lesson_assessment_id',
        'lesson_assessment_version_id',
        'last_question_id',
    ];

    protected function casts(): array
    {
        return [
            'correct_count' => 'integer',
            'revision' => 'integer',
            'check_count' => 'integer',
            'automatic_score' => 'array',
            'manual_score' => 'array',
            'final_score' => 'array',
            'submitted_at' => 'immutable_datetime',
            'finalized_at' => 'immutable_datetime',
            'checked_at' => 'immutable_datetime',
            'passed_at' => 'immutable_datetime',
        ];
    }

    public function assessment(): BelongsTo
    {
        return $this->belongsTo(LessonAssessment::class, 'lesson_assessment_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(LessonAssessmentVersion::class, 'lesson_assessment_version_id');
    }

    public function responses(): HasMany
    {
        return $this->hasMany(LessonAssessmentAttemptResponse::class);
    }

    public function manualReviews(): HasMany
    {
        return $this->hasMany(LessonAssessmentManualReview::class);
    }

    public function reviewEvents(): HasMany
    {
        return $this->hasMany(LessonAssessmentReviewEvent::class);
    }
}
