<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class LessonAssessmentAttemptResponse extends Model
{
    use HasFactory;

    protected $fillable = [
        'lesson_assessment_attempt_id',
        'question_id',
        'response',
        'is_complete',
    ];

    protected function casts(): array
    {
        return [
            'response' => 'array',
            'is_complete' => 'boolean',
            'is_locked' => 'boolean',
            'revision' => 'integer',
            'checked_at' => 'immutable_datetime',
        ];
    }

    public function manualReview(): HasOne
    {
        return $this->hasOne(LessonAssessmentManualReview::class);
    }

    public function attempt(): BelongsTo
    {
        return $this->belongsTo(LessonAssessmentAttempt::class, 'lesson_assessment_attempt_id');
    }
}
