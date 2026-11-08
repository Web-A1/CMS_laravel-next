<?php

namespace Tests\Feature;

use App\Models\LessonAssessmentAttempt;
use App\Models\LessonAssessmentManualReview;
use App\Models\LessonAssessmentReviewEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class LessonAssessmentReviewEventSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_sqlite_schema_and_model_expose_attempt_level_idempotent_audit_fields(): void
    {
        $this->assertTrue(Schema::hasColumns('lesson_assessment_review_events', [
            'lesson_assessment_attempt_id',
            'lesson_assessment_manual_review_id',
            'previous_review_score_bps',
            'review_score_bps',
            'previous_attempt_status',
            'attempt_status',
            'automatic_bps',
            'manual_bps',
            'final_bps',
            'failure_reason',
            'idempotency_key_hash',
            'request_fingerprint_sha256',
        ]));

        $event = new LessonAssessmentReviewEvent;

        $this->assertContains('lesson_assessment_attempt_id', $event->getFillable());
        $this->assertContains('request_fingerprint_sha256', $event->getFillable());
        $this->assertSame('integer', $event->getCasts()['final_bps']);
        $this->assertSame('integer', $event->getCasts()['review_score_bps']);
        $this->assertSame('lesson_assessment_attempt_id', $event->attempt()->getForeignKeyName());

        $review = new LessonAssessmentManualReview;
        $attempt = new LessonAssessmentAttempt;

        $this->assertContains('score_bps', $review->getFillable());
        $this->assertContains('reviewer_id', $review->getFillable());
        $this->assertSame('lesson_assessment_attempt_id', $attempt->reviewEvents()->getForeignKeyName());
    }
}
