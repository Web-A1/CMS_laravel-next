<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\AdminAssessmentReviewController;
use App\Models\Course;
use App\Models\Lesson;
use App\Models\LessonAssessment;
use App\Models\LessonAssessmentAttempt;
use App\Models\LessonAssessmentVersion;
use App\Models\Subject;
use App\Models\User;
use App\Services\Learning\LessonAssessmentAttemptService;
use Database\Seeders\LessonAssessmentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AdminLessonAssessmentReviewApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_full_lists_and_reads_every_review_with_private_no_store_contract(): void
    {
        $admin = User::factory()->create(['role' => 'admin_full']);
        [$first] = $this->pendingAttempt();
        [$second] = $this->pendingAttempt();
        Sanctum::actingAs($admin);

        $list = $this->getJson('/api/v1/admin/assessment-reviews?perPage=1&page=1');
        $detail = $this->getJson("/api/v1/admin/assessment-reviews/{$first->id}");

        $list->assertOk()
            ->assertJsonPath('meta.total', 2)
            ->assertJsonCount(1, 'data');
        $this->assertStringContainsString('private', (string) $list->headers->get('Cache-Control'));
        $this->assertStringContainsString('no-store', (string) $list->headers->get('Cache-Control'));
        $detail->assertOk()
            ->assertJsonPath('data.attempt.id', $first->id)
            ->assertJsonPath('data.items.0.question.prompt', 'Manual prompt')
            ->assertJsonPath('data.items.0.responseSnapshot.text', 'Student answer')
            ->assertJsonPath('data.items.0.rubricSnapshot.expectedAnswer.referenceAnswers.0', 'Expected answer')
            ->assertJsonPath('data.finalScore', null)
            ->assertJsonPath('data.failureReason', null)
            ->assertJsonMissingPath('data.items.0.answerKey')
            ->assertJsonMissingPath('data.audit');
        $this->assertStringContainsString('private', (string) $detail->headers->get('Cache-Control'));
        $this->assertStringContainsString('no-store', (string) $detail->headers->get('Cache-Control'));
        $this->getJson('/api/v1/admin/assessment-reviews/999999')
            ->assertNotFound()
            ->assertJsonPath('error.code', 'ADMIN_ASSESSMENT_REVIEW_NOT_FOUND');
        $this->assertNotSame($first->id, $second->id);
    }

    public function test_pending_decision_is_significant_but_identical_normalized_decision_is_noop_after_cas(): void
    {
        [$attempt] = $this->pendingAttempt();
        $review = $attempt->manualReviews()->firstOrFail();
        Sanctum::actingAs(User::factory()->create(['role' => 'admin_full']));
        $url = '/api/v1/admin/assessment-reviews/'.$attempt->id.'/items/'.$review->id;
        $first = $this->withHeader('Idempotency-Key', 'initial-zero-decision')->patchJson($url,
            ['revision' => 0, 'scoreBps' => 0, 'comment' => ' '])->assertOk()->json('data');
        $this->assertSame('decided', $review->fresh()->status);
        $this->assertSame(1, $review->fresh()->revision);
        $before = $review->fresh()->getAttributes();
        $attemptRevision = $attempt->fresh()->revision;
        $this->travel(10)->seconds();
        $this->withHeader('Idempotency-Key', 'identical-zero-decision')->patchJson($url,
            ['revision' => 1, 'scoreBps' => 0, 'comment' => null])->assertOk()
            ->assertJsonPath('data.item.revision', 1)->assertJsonPath('data.attempt.reviewRevision', $attemptRevision);
        $this->assertSame($before, $review->fresh()->getAttributes());
        $this->assertDatabaseCount('lesson_assessment_review_events', 1);
        $this->withHeader('Idempotency-Key', 'stale-zero-decision')->patchJson($url,
            ['revision' => 0, 'scoreBps' => 0, 'comment' => null])->assertConflict();
        $this->withHeader('Idempotency-Key', 'initial-zero-decision')->patchJson($url,
            ['revision' => 0, 'scoreBps' => 0, 'comment' => null])->assertOk()->assertJsonPath('data.item.revision', $first['item']['revision']);
        $this->assertNull($attempt->fresh()->finalized_at);
        $this->assertDatabaseCount('lesson_assessment_review_events', 1);
    }

    #[DataProvider('scopedReviewerRoles')]
    public function test_manager_and_teacher_are_limited_to_students_assigned_by_manager_id(string $role): void
    {
        $actor = User::factory()->create(['role' => $role]);
        [$assigned] = $this->pendingAttempt($actor->id);
        [$unassigned] = $this->pendingAttempt();
        Sanctum::actingAs($actor);

        $this->getJson('/api/v1/admin/assessment-reviews')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $assigned->id);
        $this->getJson("/api/v1/admin/assessment-reviews/{$assigned->id}")->assertOk();
        $this->getJson("/api/v1/admin/assessment-reviews/{$unassigned->id}")->assertForbidden();

        $unassignedReview = $unassigned->manualReviews()->firstOrFail();
        $this->withHeader('Idempotency-Key', "{$role}-unassigned-update")
            ->patchJson("/api/v1/admin/assessment-reviews/{$unassigned->id}/items/{$unassignedReview->id}", [
                'revision' => 0,
                'scoreBps' => 8000,
                'comment' => null,
            ])->assertForbidden();
        $this->withHeader('Idempotency-Key', "{$role}-unassigned-finalize")
            ->postJson("/api/v1/admin/assessment-reviews/{$unassigned->id}/finalizations", [
                'reviewRevision' => $unassigned->revision,
            ])->assertForbidden();

        $assignedReview = $assigned->manualReviews()->firstOrFail();
        $updated = $this->withHeader('Idempotency-Key', "{$role}-assigned-update")
            ->patchJson("/api/v1/admin/assessment-reviews/{$assigned->id}/items/{$assignedReview->id}", [
                'revision' => 0,
                'scoreBps' => 10000,
                'comment' => null,
            ])->assertOk();
        $this->withHeader('Idempotency-Key', "{$role}-assigned-finalize")
            ->postJson("/api/v1/admin/assessment-reviews/{$assigned->id}/finalizations", [
                'reviewRevision' => $updated->json('data.attempt.reviewRevision'),
            ])->assertOk()->assertJsonPath('data.attempt.status', 'passed');
    }

    public static function scopedReviewerRoles(): array
    {
        return [['manager'], ['teacher']];
    }

    #[DataProvider('scopedReviewerRoles')]
    public function test_queue_status_search_and_pagination_preserve_reviewer_scope(string $role): void
    {
        $actor = User::factory()->create(['role' => $role]);
        [$first] = $this->pendingAttempt($actor->id);
        [$second] = $this->pendingAttempt($actor->id);
        [$unassigned] = $this->pendingAttempt();
        $first->user->update(['name' => 'Queue match Alpha']);
        $second->user->update(['name' => 'Queue match Beta']);
        $unassigned->user->update(['name' => 'Queue match Hidden']);
        Sanctum::actingAs($actor);

        $this->getJson('/api/v1/admin/assessment-reviews?status=all&search=QUEUE%20MATCH&perPage=1&page=1')
            ->assertOk()->assertJsonPath('meta.total', 2)->assertJsonPath('meta.lastPage', 2)
            ->assertJsonPath('data.0.id', $second->id);
        $this->getJson('/api/v1/admin/assessment-reviews?status=all&search=Queue%20match&perPage=1&page=2')
            ->assertOk()->assertJsonPath('meta.currentPage', 2)->assertJsonPath('data.0.id', $first->id);
        $this->getJson('/api/v1/admin/assessment-reviews?status=all&search=Queue%20match&perPage=1&page=99')
            ->assertOk()->assertJsonPath('meta.currentPage', 2)->assertJsonPath('data.0.id', $first->id);
        $this->getJson('/api/v1/admin/assessment-reviews?search=Hidden')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/admin/assessment-reviews?search=%25')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/admin/assessment-reviews?status=invalid')->assertUnprocessable();
        $this->getJson('/api/v1/admin/assessment-reviews?search[]=invalid')->assertUnprocessable();

        $review = $second->manualReviews()->firstOrFail();
        $updated = $this->withHeader('Idempotency-Key', "{$role}-queue-update")
            ->patchJson("/api/v1/admin/assessment-reviews/{$second->id}/items/{$review->id}", ['revision' => 0, 'scoreBps' => $role === 'teacher' ? 0 : 10000]);
        $finalized = $this->withHeader('Idempotency-Key', "{$role}-queue-finalize")
            ->postJson("/api/v1/admin/assessment-reviews/{$second->id}/finalizations", ['reviewRevision' => $updated->json('data.attempt.reviewRevision')])
            ->assertOk();
        $finalized->assertJsonPath('data.finalScore.passed', $role !== 'teacher')
            ->assertJsonPath('data.failureReason', $role === 'teacher' ? 'manual_threshold_not_met' : null);
        $this->getJson('/api/v1/admin/assessment-reviews')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $first->id);
        $this->getJson('/api/v1/admin/assessment-reviews?status=finalized&search=Beta')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $second->id);
        $this->getJson('/api/v1/admin/assessment-reviews?status=all')->assertOk()->assertJsonPath('meta.total', 2);
        $this->getJson("/api/v1/admin/assessment-reviews/{$second->id}")->assertOk()->assertExactJson($finalized->json());
    }

    #[DataProvider('forbiddenRoles')]
    public function test_roles_without_review_permissions_are_forbidden(string $role): void
    {
        $actor = User::factory()->create(['role' => $role]);
        Sanctum::actingAs($actor);

        $this->getJson('/api/v1/admin/assessment-reviews')->assertForbidden();
    }

    public static function forbiddenRoles(): array
    {
        return [['admin_limited'], ['user_with_purchase'], ['user_no_purchase']];
    }

    public function test_anonymous_review_access_is_unauthenticated(): void
    {
        $this->getJson('/api/v1/admin/assessment-reviews')->assertUnauthorized();
    }

    public function test_review_update_validates_payload_and_key_then_replays_without_duplicate_event(): void
    {
        $admin = User::factory()->create(['role' => 'admin_full']);
        [$attempt] = $this->pendingAttempt();
        $review = $attempt->manualReviews()->firstOrFail();
        Sanctum::actingAs($admin);
        $url = "/api/v1/admin/assessment-reviews/{$attempt->id}/items/{$review->id}";

        $this->patchJson($url, ['revision' => 0, 'scoreBps' => 7000, 'comment' => null])
            ->assertUnprocessable();
        $this->withHeader('Idempotency-Key', '   ')
            ->patchJson($url, ['revision' => 0, 'scoreBps' => 7000, 'comment' => null])
            ->assertUnprocessable();
        $this->withHeader('Idempotency-Key', 'invalid-score-low')
            ->patchJson($url, ['revision' => 0, 'scoreBps' => -1, 'comment' => null])
            ->assertUnprocessable();
        $this->withHeader('Idempotency-Key', 'invalid-score-type')
            ->patchJson($url, ['revision' => 0, 'scoreBps' => '7000', 'comment' => null])
            ->assertUnprocessable();
        $this->patchJson($url, ['revision' => 0, 'scoreBps' => 10001, 'comment' => null])
            ->assertUnprocessable();
        $this->withHeader('Idempotency-Key', str_repeat('x', 161))
            ->patchJson($url, ['revision' => 0, 'scoreBps' => 7000, 'comment' => null])
            ->assertUnprocessable();
        $this->withHeader('Idempotency-Key', 'review-api-key')
            ->patchJson($url, ['revision' => 0, 'scoreBps' => 7000, 'comment' => str_repeat('x', 2001)])
            ->assertUnprocessable();
        $this->withHeader('Idempotency-Key', 'invalid-comment-type')
            ->patchJson($url, ['revision' => 0, 'scoreBps' => 7000, 'comment' => ['not-a-string']])
            ->assertUnprocessable();
        $this->withHeader('Idempotency-Key', 'unexpected-field')
            ->patchJson($url, ['revision' => 0, 'scoreBps' => 7000, 'unexpected' => true])
            ->assertUnprocessable();

        $payload = ['revision' => 0, 'scoreBps' => 7000, 'comment' => '  '];
        $first = $this->withHeader('Idempotency-Key', 'review-api-key')->patchJson($url, $payload);
        $replay = $this->withHeader('Idempotency-Key', 'review-api-key')->patchJson($url, [
            'revision' => 0,
            'scoreBps' => 7000,
            'comment' => null,
        ]);

        $first->assertOk()->assertJsonPath('data.item.comment', null);
        $replay->assertOk()->assertJsonPath('data.item.revision', 1);
        $this->assertDatabaseCount('lesson_assessment_review_events', 1);
        $this->withHeader('Idempotency-Key', 'review-api-key')->patchJson($url, [
            'revision' => 0,
            'scoreBps' => 7001,
            'comment' => null,
        ])->assertConflict();
        $this->withHeader('Idempotency-Key', 'stale-key')->patchJson($url, [
            'revision' => 0,
            'scoreBps' => 7000,
            'comment' => null,
        ])->assertConflict();
    }

    #[DataProvider('nullableCommentPayloads')]
    public function test_review_comment_is_optional_and_null_or_blank_values_normalize_to_null(array $commentPayload): void
    {
        $admin = User::factory()->create(['role' => 'admin_full']);
        [$attempt] = $this->pendingAttempt();
        $review = $attempt->manualReviews()->firstOrFail();
        Sanctum::actingAs($admin);

        $this->withHeader('Idempotency-Key', 'optional-comment')
            ->patchJson("/api/v1/admin/assessment-reviews/{$attempt->id}/items/{$review->id}", [
                'revision' => 0,
                'scoreBps' => 7000,
                ...$commentPayload,
            ])->assertOk()
            ->assertJsonPath('data.item.comment', null);
    }

    public static function nullableCommentPayloads(): array
    {
        return [
            'omitted' => [[]],
            'explicit null' => [['comment' => null]],
            'blank' => [['comment' => " \t "]],
        ];
    }

    public function test_review_update_rate_limit_is_per_actor_and_preserves_permission_and_validation_middleware(): void
    {
        $route = Route::getRoutes()->getByAction(AdminAssessmentReviewController::class.'@update');
        $this->assertNotNull($route);
        $this->assertContains('permission:assessment-reviews.review', $route->gatherMiddleware());
        $this->assertContains('throttle:assessment-review-update', $route->gatherMiddleware());

        $admin = User::factory()->create(['role' => 'admin_full']);
        [$attempt] = $this->pendingAttempt();
        $review = $attempt->manualReviews()->firstOrFail();
        $url = "/api/v1/admin/assessment-reviews/{$attempt->id}/items/{$review->id}";
        $key = "assessment-review-update:actor:{$admin->id}";
        RateLimiter::clear($key);
        Sanctum::actingAs($admin);

        for ($request = 0; $request < 120; $request++) {
            $this->withHeader('Idempotency-Key', "update-limit-{$request}")
                ->patchJson($url, ['revision' => 0, 'scoreBps' => -1])
                ->assertUnprocessable();
        }
        $this->withHeader('Idempotency-Key', 'update-limit-blocked')
            ->patchJson($url, ['revision' => 0, 'scoreBps' => -1])
            ->assertTooManyRequests();

        $otherAdmin = User::factory()->create(['role' => 'admin_full']);
        RateLimiter::clear("assessment-review-update:actor:{$otherAdmin->id}");
        Sanctum::actingAs($otherAdmin);
        $this->withHeader('Idempotency-Key', 'update-limit-other-actor')
            ->patchJson($url, ['revision' => 0, 'scoreBps' => -1])
            ->assertUnprocessable();
        $this->assertDatabaseCount('lesson_assessment_review_events', 0);
    }

    public function test_review_finalization_rate_limit_is_per_actor_and_preserves_permission_and_validation_middleware(): void
    {
        $route = Route::getRoutes()->getByAction(AdminAssessmentReviewController::class.'@finalize');
        $this->assertNotNull($route);
        $this->assertContains('permission:assessment-reviews.review', $route->gatherMiddleware());
        $this->assertContains('throttle:assessment-review-finalize', $route->gatherMiddleware());

        $admin = User::factory()->create(['role' => 'admin_full']);
        [$attempt] = $this->pendingAttempt();
        $url = "/api/v1/admin/assessment-reviews/{$attempt->id}/finalizations";
        $key = "assessment-review-finalize:actor:{$admin->id}";
        RateLimiter::clear($key);
        Sanctum::actingAs($admin);

        for ($request = 0; $request < 30; $request++) {
            $this->withHeader('Idempotency-Key', "finalize-limit-{$request}")
                ->postJson($url, ['reviewRevision' => 'invalid'])
                ->assertUnprocessable();
        }
        $this->withHeader('Idempotency-Key', 'finalize-limit-blocked')
            ->postJson($url, ['reviewRevision' => 'invalid'])
            ->assertTooManyRequests();

        $otherAdmin = User::factory()->create(['role' => 'admin_full']);
        RateLimiter::clear("assessment-review-finalize:actor:{$otherAdmin->id}");
        Sanctum::actingAs($otherAdmin);
        $this->withHeader('Idempotency-Key', 'finalize-limit-other-actor')
            ->postJson($url, ['reviewRevision' => 'invalid'])
            ->assertUnprocessable();
        $this->assertDatabaseCount('lesson_assessment_review_events', 0);
    }

    public function test_finalization_requires_all_items_and_current_review_revision_then_is_idempotent(): void
    {
        $admin = User::factory()->create(['role' => 'admin_full']);
        [$attempt] = $this->pendingAttempt();
        $review = $attempt->manualReviews()->firstOrFail();
        Sanctum::actingAs($admin);
        $finalizeUrl = "/api/v1/admin/assessment-reviews/{$attempt->id}/finalizations";

        $this->postJson($finalizeUrl, ['reviewRevision' => $attempt->revision])
            ->assertUnprocessable();
        $this->withHeader('Idempotency-Key', '   ')
            ->postJson($finalizeUrl, ['reviewRevision' => $attempt->revision])
            ->assertUnprocessable();
        $this->withHeader('Idempotency-Key', str_repeat('x', 161))
            ->postJson($finalizeUrl, ['reviewRevision' => $attempt->revision])
            ->assertUnprocessable();
        $this->withHeader('Idempotency-Key', 'invalid-final-revision')
            ->postJson($finalizeUrl, ['reviewRevision' => (string) $attempt->revision])
            ->assertUnprocessable();
        $this->withHeader('Idempotency-Key', 'too-early')
            ->postJson($finalizeUrl, ['reviewRevision' => $attempt->revision])
            ->assertConflict();
        $update = $this->withHeader('Idempotency-Key', 'review-before-final')
            ->patchJson("/api/v1/admin/assessment-reviews/{$attempt->id}/items/{$review->id}", [
                'revision' => 0,
                'scoreBps' => 10000,
                'comment' => 'Done',
            ])->assertOk();
        $revision = $update->json('data.attempt.reviewRevision');
        $this->withHeader('Idempotency-Key', 'stale-final')
            ->postJson($finalizeUrl, ['reviewRevision' => $revision - 1])
            ->assertConflict();

        $first = $this->withHeader('Idempotency-Key', 'final-api-key')
            ->postJson($finalizeUrl, ['reviewRevision' => $revision]);
        $replay = $this->withHeader('Idempotency-Key', 'final-api-key')
            ->postJson($finalizeUrl, ['reviewRevision' => $revision]);
        $first->assertOk()->assertJsonPath('data.attempt.status', 'passed');
        $replay->assertOk()->assertJsonPath('data.attempt.status', 'passed');
        $this->assertDatabaseCount('lesson_assessment_review_events', 2);
        $this->withHeader('Idempotency-Key', 'competing-final')
            ->postJson($finalizeUrl, ['reviewRevision' => $revision])
            ->assertConflict();
    }

    /** @return array{LessonAssessmentAttempt, User} */
    private function pendingAttempt(?int $managerId = null): array
    {
        $subject = Subject::query()->create([
            'slug' => 'admin-review-subject-'.uniqid(),
            'title' => 'Subject',
            'sort_order' => 1,
            'is_active' => true,
        ]);
        $course = Course::query()->create([
            'subject_id' => $subject->id,
            'slug' => 'admin-review-course-'.uniqid(),
            'title' => 'Course',
            'price_cents' => 1000,
            'sort_order' => 1,
            'is_active' => true,
        ]);
        $lesson = Lesson::query()->create([
            'course_id' => $course->id,
            'slug' => 'admin-review-lesson-'.uniqid(),
            'title' => 'Lesson',
            'sort_order' => 1,
            'is_active' => true,
        ]);
        $assessment = LessonAssessment::query()->create(['lesson_id' => $lesson->id]);
        LessonAssessmentVersion::query()->forceCreate([
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
            'questions' => [
                [
                    'id' => 'automatic-01',
                    'sectionId' => 'automatic',
                    'type' => 'single_choice',
                    'gradingMode' => 'automatic',
                    'prompt' => 'Automatic prompt',
                    'options' => [['id' => 'right', 'label' => 'Right']],
                ],
                [
                    'id' => 'manual-01',
                    'sectionId' => 'manual',
                    'type' => 'long_text',
                    'gradingMode' => 'manual',
                    'prompt' => 'Manual prompt',
                    'rows' => 4,
                ],
            ],
            'answer_key' => [
                'automatic-01' => ['response' => ['selectedOptionId' => 'right']],
                'manual-01' => ['response' => ['referenceAnswers' => ['Expected answer']]],
            ],
            'published_at' => now(),
        ]);
        $student = User::factory()->create(['manager_id' => $managerId]);
        $attempts = app(LessonAssessmentAttemptService::class);
        $attempt = $attempts->start($student, $assessment)['attempt'];
        $attempt = $attempts->save($student, $attempt, 0, [
            ['questionId' => 'automatic-01', 'response' => ['selectedOptionId' => 'right']],
            ['questionId' => 'manual-01', 'response' => ['text' => 'Student answer']],
        ], 'manual-01');
        $attempt = $attempts->submit($student, $attempt, 1)['attempt'];

        return [$attempt, $student];
    }
}
