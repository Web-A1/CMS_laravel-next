<?php

namespace Tests\Feature;

use App\Exceptions\LessonAssessmentException;
use App\Models\Course;
use App\Models\Lesson;
use App\Models\LessonAssessment;
use App\Models\LessonAssessmentAttempt;
use App\Models\LessonAssessmentVersion;
use App\Models\Subject;
use App\Models\User;
use App\Services\Learning\LessonAssessmentAttemptService;
use App\Services\Learning\LessonAssessmentManualReviewService;
use Database\Seeders\LessonAssessmentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LessonAssessmentHybridSubmissionTest extends TestCase
{
    use RefreshDatabase;

    public function test_automatic_gate_failure_is_terminal_locks_every_response_and_never_queues_manual_review(): void
    {
        [$assessment] = $this->createHybridAssessment();
        $student = User::factory()->create();
        $service = app(LessonAssessmentAttemptService::class);
        $attempt = $service->start($student, $assessment)['attempt'];
        $attempt = $service->save($student, $attempt, 0, $this->responses(automaticCorrect: 3), 'manual-01');

        $result = $service->submit($student, $attempt, 1);

        $this->assertFalse($result['automaticGate']['passed']);
        $this->assertSame(LessonAssessmentAttempt::STATUS_FAILED, $result['attempt']->status);
        $this->assertSame('automatic_threshold_not_met', $result['attempt']->failure_reason);
        $this->assertSame(8000, $result['automaticGate']['overall']['requiredBps']);
        $this->assertSame(6, $result['attempt']->responses()->where('is_locked', true)->count());
        $this->assertDatabaseCount('lesson_assessment_manual_reviews', 0);
        $this->assertDatabaseCount('lesson_assessment_completions', 0);
    }

    public function test_automatic_gate_pass_enters_pending_review_and_snapshots_one_row_per_manual_response(): void
    {
        [$assessment] = $this->createHybridAssessment();
        $student = User::factory()->create();
        $service = app(LessonAssessmentAttemptService::class);
        $attempt = $service->start($student, $assessment)['attempt'];
        $attempt = $service->save($student, $attempt, 0, $this->responses(automaticCorrect: 5), 'manual-01');

        $result = $service->submit($student, $attempt, 1);

        $this->assertTrue($result['automaticGate']['passed']);
        $this->assertSame(LessonAssessmentAttempt::STATUS_PENDING_REVIEW, $result['attempt']->status);
        $this->assertSame(6, $result['attempt']->responses()->where('is_locked', true)->count());
        $this->assertDatabaseHas('lesson_assessment_manual_reviews', [
            'lesson_assessment_attempt_id' => $attempt->id,
            'question_id' => 'manual-01',
            'status' => 'pending',
        ]);
        $review = $result['attempt']->manualReviews()->firstOrFail();
        $this->assertSame(['text' => 'Развёрнутый ответ'], $review->response_snapshot);
        $this->assertArrayHasKey('expectedAnswer', $review->rubric_snapshot);
        $this->assertSame(64, strlen($review->response_snapshot_sha256));
        $this->assertSame(64, strlen($review->rubric_snapshot_sha256));
        $this->assertDatabaseCount('lesson_assessment_completions', 0);

        try {
            $service->save($student, $result['attempt'], 2, [], null);
            $this->fail('Pending attempts must be read-only.');
        } catch (LessonAssessmentException $exception) {
            $this->assertSame(LessonAssessmentException::ATTEMPT_CONFLICT, $exception->errorCode);
        }
    }

    public function test_review_update_is_revision_safe_idempotent_and_audited_without_protected_snapshots(): void
    {
        [$attempt] = $this->pendingAttempt();
        $reviewer = User::factory()->create(['role' => 'admin_full']);
        $service = app(LessonAssessmentManualReviewService::class);
        $review = $attempt->manualReviews()->firstOrFail();

        $first = $service->update($reviewer, $attempt->id, $review->id, 0, 7500, '  ', 'review-key');
        $replay = $service->update($reviewer, $attempt->id, $review->id, 0, 7500, null, 'review-key');

        $this->assertSame(1, $first['item']['revision']);
        $this->assertNull($first['item']['comment']);
        $this->assertSame($first, $replay);
        $this->assertDatabaseCount('lesson_assessment_review_events', 1);
        $event = $attempt->reviewEvents()->firstOrFail()->toArray();
        $this->assertArrayNotHasKey('response_snapshot', $event);
        $this->assertArrayNotHasKey('rubric_snapshot', $event);
        $this->assertSame(hash('sha256', 'review-key'), $event['idempotency_key_hash']);

        $this->expectReviewConflict(fn () => $service->update(
            $reviewer,
            $attempt->id,
            $review->id,
            0,
            7501,
            null,
            'review-key',
        ));
        $this->expectReviewConflict(fn () => $service->update(
            $reviewer,
            $attempt->id,
            $review->id,
            0,
            7500,
            null,
            'other-key',
        ));
    }

    public function test_explicit_finalization_replays_same_command_blocks_competitors_and_writes_completion_only_for_pass(): void
    {
        [$attempt, $student] = $this->pendingAttempt();
        $reviewer = User::factory()->create(['role' => 'admin_full']);
        $service = app(LessonAssessmentManualReviewService::class);
        $review = $attempt->manualReviews()->firstOrFail();
        $updated = $service->update($reviewer, $attempt->id, $review->id, 0, 10000, 'Отлично', 'review-pass');
        $reviewRevision = $updated['attempt']['reviewRevision'];

        $first = $service->finalize($reviewer, $attempt->id, $reviewRevision, 'final-pass');
        $replay = $service->finalize($reviewer, $attempt->id, $reviewRevision, 'final-pass');

        $this->assertSame(LessonAssessmentAttempt::STATUS_PASSED, $first['attempt']['status']);
        $this->assertSame(10000, $first['finalScore']['overall']['percentBps']);
        $this->assertSame($first, $replay);
        $this->assertDatabaseHas('lesson_assessment_completions', [
            'user_id' => $student->id,
            'passed_attempt_id' => $attempt->id,
        ]);
        $this->assertDatabaseCount('lesson_assessment_review_events', 2);

        $this->expectReviewConflict(fn () => $service->finalize(
            $reviewer,
            $attempt->id,
            $reviewRevision,
            'competing-finalization',
        ));
        $this->expectReviewConflict(fn () => $service->update(
            $reviewer,
            $attempt->id,
            $review->id,
            1,
            9000,
            null,
            'post-final-edit',
        ));
    }

    public function test_manual_threshold_failure_takes_precedence_and_never_writes_completion(): void
    {
        [$attempt, $student] = $this->pendingAttempt();
        $reviewer = User::factory()->create(['role' => 'admin_full']);
        $service = app(LessonAssessmentManualReviewService::class);
        $review = $attempt->manualReviews()->firstOrFail();
        $updated = $service->update($reviewer, $attempt->id, $review->id, 0, 5000, null, 'review-manual-fail');

        $result = $service->finalize(
            $reviewer,
            $attempt->id,
            $updated['attempt']['reviewRevision'],
            'final-manual-fail',
        );

        $this->assertSame(LessonAssessmentAttempt::STATUS_FAILED, $result['attempt']['status']);
        $this->assertSame('manual_threshold_not_met', $result['failureReason']);
        $this->assertSame(8500, $result['finalScore']['overall']['percentBps']);
        $this->assertDatabaseMissing('lesson_assessment_completions', ['user_id' => $student->id]);
    }

    public function test_combined_threshold_failure_is_distinct_and_incomplete_finalization_conflicts(): void
    {
        [$attempt, $student] = $this->pendingAttempt(automaticCorrect: 4);
        $reviewer = User::factory()->create(['role' => 'admin_full']);
        $service = app(LessonAssessmentManualReviewService::class);
        $review = $attempt->manualReviews()->firstOrFail();

        try {
            $service->finalize($reviewer, $attempt->id, $attempt->revision, 'too-early');
            $this->fail('Finalization must require every item to be decided.');
        } catch (LessonAssessmentException $exception) {
            $this->assertSame(LessonAssessmentException::REVIEW_INCOMPLETE, $exception->errorCode);
        }

        $updated = $service->update($reviewer, $attempt->id, $review->id, 0, 6000, null, 'review-combined-fail');
        $result = $service->finalize(
            $reviewer,
            $attempt->id,
            $updated['attempt']['reviewRevision'],
            'final-combined-fail',
        );

        $this->assertSame('combined_threshold_not_met', $result['failureReason']);
        $this->assertSame(7400, $result['finalScore']['overall']['percentBps']);
        $this->assertDatabaseMissing('lesson_assessment_completions', ['user_id' => $student->id]);
    }

    private function createHybridAssessment(): array
    {
        $subject = Subject::query()->create([
            'slug' => 'hybrid-subject-'.uniqid(),
            'title' => 'Hybrid subject',
            'sort_order' => 1,
            'is_active' => true,
        ]);
        $course = Course::query()->create([
            'subject_id' => $subject->id,
            'slug' => 'hybrid-course-'.uniqid(),
            'title' => 'Hybrid course',
            'price_cents' => 1000,
            'sort_order' => 1,
            'is_active' => true,
        ]);
        $lesson = Lesson::query()->create([
            'course_id' => $course->id,
            'slug' => 'hybrid-lesson-'.uniqid(),
            'title' => 'Hybrid lesson',
            'sort_order' => 1,
            'is_active' => true,
        ]);
        $assessment = LessonAssessment::query()->create(['lesson_id' => $lesson->id]);
        $questions = [];
        $answerKey = [];
        foreach (range(1, 5) as $number) {
            $id = sprintf('automatic-%02d', $number);
            $questions[] = [
                'id' => $id,
                'sectionId' => 'automatic',
                'type' => 'single_choice',
                'gradingMode' => 'automatic',
                'prompt' => "Automatic {$number}",
                'options' => [
                    ['id' => 'right', 'label' => 'Right'],
                    ['id' => 'wrong', 'label' => 'Wrong'],
                ],
            ];
            $answerKey[$id] = ['response' => ['selectedOptionId' => 'right']];
        }
        $questions[] = [
            'id' => 'manual-01',
            'sectionId' => 'manual',
            'type' => 'long_text',
            'gradingMode' => 'manual',
            'prompt' => 'Explain the answer',
            'rows' => 4,
        ];
        $answerKey['manual-01'] = ['response' => ['referenceAnswers' => ['Expected answer']]];

        $version = LessonAssessmentVersion::query()->forceCreate([
            'lesson_assessment_id' => $assessment->id,
            'status' => LessonAssessmentVersion::STATUS_PUBLISHED,
            'schema_version' => 2,
            'content_version' => 37,
            'scoring_policy' => LessonAssessmentSeeder::validatedFixture()['scoringPolicy'],
            'source_assignment_sha256' => str_repeat('a', 64),
            'source_key_sha256' => str_repeat('b', 64),
            'sections' => [
                ['id' => 'automatic', 'title' => 'Automatic'],
                ['id' => 'manual', 'title' => 'Manual'],
            ],
            'questions' => $questions,
            'answer_key' => $answerKey,
            'published_at' => now(),
        ]);

        return [$assessment, $version, $lesson, $subject, $course];
    }

    /** @return list<array{questionId:string,response:array<string,mixed>}> */
    private function responses(int $automaticCorrect): array
    {
        $responses = [];
        foreach (range(1, 5) as $number) {
            $responses[] = [
                'questionId' => sprintf('automatic-%02d', $number),
                'response' => ['selectedOptionId' => $number <= $automaticCorrect ? 'right' : 'wrong'],
            ];
        }
        $responses[] = [
            'questionId' => 'manual-01',
            'response' => ['text' => 'Развёрнутый ответ'],
        ];

        return $responses;
    }

    /** @return array{LessonAssessmentAttempt, User} */
    private function pendingAttempt(int $automaticCorrect = 5): array
    {
        [$assessment] = $this->createHybridAssessment();
        $student = User::factory()->create();
        $attempts = app(LessonAssessmentAttemptService::class);
        $attempt = $attempts->start($student, $assessment)['attempt'];
        $attempt = $attempts->save($student, $attempt, 0, $this->responses($automaticCorrect), 'manual-01');
        $attempt = $attempts->submit($student, $attempt, 1)['attempt'];

        return [$attempt, $student];
    }

    private function expectReviewConflict(callable $callback): void
    {
        try {
            $callback();
            $this->fail('Expected a review conflict.');
        } catch (LessonAssessmentException $exception) {
            $this->assertSame(LessonAssessmentException::REVIEW_CONFLICT, $exception->errorCode);
            $this->assertSame(409, $exception->httpStatus);
        }
    }
}
