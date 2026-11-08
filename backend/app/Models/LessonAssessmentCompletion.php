<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LessonAssessmentCompletion extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'lesson_id',
        'lesson_assessment_id',
        'passed_attempt_id',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'completed_at' => 'immutable_datetime',
        ];
    }

    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class);
    }

    public function assessment(): BelongsTo
    {
        return $this->belongsTo(LessonAssessment::class, 'lesson_assessment_id');
    }

    public function passedAttempt(): BelongsTo
    {
        return $this->belongsTo(LessonAssessmentAttempt::class, 'passed_attempt_id');
    }
}
