<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class LessonAssessmentReviewEvent extends Model
{
    use HasFactory;

    public const UPDATED_AT = null;

    protected $fillable = [
        'lesson_assessment_attempt_id',
        'lesson_assessment_manual_review_id',
        'actor_id',
        'event_type',
        'previous_review_score_bps',
        'review_score_bps',
        'comment_changed',
        'previous_attempt_status',
        'attempt_status',
        'automatic_bps',
        'manual_bps',
        'final_bps',
        'failure_reason',
        'revision',
        'idempotency_key_hash',
        'request_fingerprint_sha256',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'previous_review_score_bps' => 'integer',
            'review_score_bps' => 'integer',
            'comment_changed' => 'boolean',
            'automatic_bps' => 'integer',
            'manual_bps' => 'integer',
            'final_bps' => 'integer',
            'revision' => 'integer',
            'created_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(static fn (): never => throw new LogicException('Assessment review events are append-only.'));
        static::deleting(static fn (): never => throw new LogicException('Assessment review events are append-only.'));
    }

    public function review(): BelongsTo
    {
        return $this->belongsTo(LessonAssessmentManualReview::class, 'lesson_assessment_manual_review_id');
    }

    public function attempt(): BelongsTo
    {
        return $this->belongsTo(LessonAssessmentAttempt::class, 'lesson_assessment_attempt_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
