<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\LearningAssessmentAssetController;
use App\Http\Controllers\Api\LearningAssessmentController;
use App\Models\Course;
use App\Models\Lesson;
use App\Models\LessonAssessment;
use App\Models\LessonAssessmentAttempt;
use App\Models\LessonAssessmentAttemptResponse;
use App\Models\LessonAssessmentCompletion;
use App\Models\LessonAssessmentVersion;
use App\Models\Subject;
use App\Models\User;
use App\Models\UserEntitlementGrant;
use App\Services\Learning\LessonAssessmentAttemptService;
use App\Services\Learning\LessonAssessmentManualReviewService;
use App\Services\Learning\LessonAssessmentProgressService;
use Database\Seeders\LessonAssessmentSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class LessonAssessmentApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_assessment_routes_use_scoped_slug_bindings_and_exact_throttles(): void
    {
        $routes = [
            'show' => Route::getRoutes()->getByAction(LearningAssessmentController::class.'@show'),
            'store' => Route::getRoutes()->getByAction(LearningAssessmentController::class.'@store'),
            'update' => Route::getRoutes()->getByAction(LearningAssessmentController::class.'@update'),
            'suggest' => Route::getRoutes()->getByAction(LearningAssessmentController::class.'@suggest'),
            'submission' => Route::getRoutes()->getByAction(LearningAssessmentController::class.'@submit'),
            'developmentCalibrationKey' => Route::getRoutes()->getByAction(LearningAssessmentController::class.'@showDevelopmentCalibrationKey'),
            'developmentReset' => Route::getRoutes()->getByAction(LearningAssessmentController::class.'@resetDevelopmentState'),
            'asset' => Route::getRoutes()->getByAction(LearningAssessmentAssetController::class),
        ];

        foreach ($routes as $route) {
            $this->assertNotNull($route);
            $this->assertTrue($route->enforcesScopedBindings());
            $this->assertSame(
                ['subject' => 'slug', 'course' => 'slug', 'lesson' => 'slug'],
                $route->bindingFields(),
            );
        }

        $this->assertContains('throttle:30,1', $routes['store']->gatherMiddleware());
        $this->assertContains('throttle:10,1', $routes['submission']->gatherMiddleware());
        $this->assertContains('throttle:120,1', $routes['suggest']->gatherMiddleware());
        $this->assertContains('throttle:60,1', $routes['developmentCalibrationKey']->gatherMiddleware());
        $this->assertContains('throttle:10,1', $routes['developmentReset']->gatherMiddleware());
        $this->assertContains('throttle:120,1', $routes['asset']->gatherMiddleware());
        $this->assertContains('throttle:120,1', $routes['update']->gatherMiddleware());
    }

    public function test_routes_require_authentication_and_lesson_access(): void
    {
        [$subject, $course, $lesson, $assessment] = $this->createAssessmentChain();
        $assessmentUrl = $this->assessmentUrl($subject, $course, $lesson);
        $requests = [
            ['GET', $assessmentUrl, []],
            ['POST', "{$assessmentUrl}/attempts", []],
            ['POST', "{$assessmentUrl}/attempts", ['retake' => true]],
            ['PATCH', "{$assessmentUrl}/attempts/999", [
                'revision' => 0,
                'lastQuestionId' => null,
                'responses' => [],
            ]],
            ['GET', "{$assessmentUrl}/attempts/999/suggestions", [
                'questionId' => 'fill-01',
                'partId' => 'p01',
                'prefix' => 'жел',
            ]],
            ['POST', "{$assessmentUrl}/attempts/999/submissions", ['revision' => 0]],
            ['GET', "{$assessmentUrl}/development-calibration-key", ['questionId' => 'diagram-01']],
            ['DELETE', "{$assessmentUrl}/development-state", []],
        ];

        foreach ($requests as [$method, $url, $payload]) {
            $this->json($method, $url, $payload)->assertUnauthorized();
        }

        $this->postJson("{$assessmentUrl}/attempts/999/checks", ['revision' => 0])->assertNotFound();

        $user = User::factory()->create();
        Sanctum::actingAs($user);

        foreach ($requests as [$method, $url, $payload]) {
            $this->json($method, $url, $payload)
                ->assertForbidden()
                ->assertExactJson($this->accessDenied());
        }

        $lesson->forceFill(['is_free_preview' => true])->save();

        $this->getJson($this->assessmentUrl($subject, $course, $lesson))
            ->assertOk()
            ->assertJsonPath('data.assessment.id', $assessment->id);
    }

    public function test_suggestions_require_an_owned_attempt_and_do_not_leak_with_the_question_payload(): void
    {
        $user = User::factory()->create();
        [$subject, $course, $lesson, $assessment, $version] = $this->createAssessmentChain();
        $version->forceFill([
            'questions' => [
                [
                    'id' => 'fill-01',
                    'sectionId' => 'fundamentals',
                    'type' => 'text_fields',
                    'gradingMode' => 'automatic',
                    'prompt' => 'Заполните строку.',
                    'source' => ['page' => 1, 'item' => '1'],
                    'parts' => [['id' => 'p01', 'prompt' => 'Строка 1']],
                ],
                [
                    'id' => 'expanded-01',
                    'sectionId' => 'fundamentals',
                    'type' => 'long_text',
                    'gradingMode' => 'automatic',
                    'prompt' => 'Дайте развёрнутый ответ.',
                    'source' => ['page' => 1, 'item' => '2'],
                    'rows' => 3,
                ],
                [
                    'id' => 'fill-02',
                    'sectionId' => 'fundamentals',
                    'type' => 'text_fields',
                    'gradingMode' => 'automatic',
                    'prompt' => 'Заполните вторую строку.',
                    'source' => ['page' => 1, 'item' => '3'],
                    'parts' => [['id' => 'p01', 'prompt' => 'Строка 1']],
                ],
                [
                    'id' => 'manual-01',
                    'sectionId' => 'fundamentals',
                    'type' => 'long_text',
                    'gradingMode' => 'manual',
                    'prompt' => 'Обоснуйте ответ.',
                    'rows' => 3,
                ],
            ],
            'answer_key' => [
                'fill-01' => ['response' => ['acceptedTexts' => [
                    'p01' => ['Жёлтая связка', 'Желтые связки'],
                ]]],
                'expanded-01' => ['response' => [
                    'requiredFragments' => [['шейный отдел']],
                    'referenceAnswers' => ['Шейный отдел позвоночника.'],
                ]],
                'fill-02' => ['response' => ['acceptedTexts' => [
                    'p01' => ['Межкостная мембрана'],
                ]]],
                'manual-01' => ['response' => [
                    'referenceAnswers' => ['Эталонное обоснование.'],
                ]],
            ],
        ])->save();
        $attempt = $this->createAttempt($user, $assessment, $version);
        $this->grant($user, $lesson);
        Sanctum::actingAs($user);
        $url = $this->assessmentUrl($subject, $course, $lesson);

        $show = $this->getJson($url)->assertOk();
        $this->assertStringNotContainsString('Жёлтая связка', (string) $show->getContent());

        $this->getJson("{$url}/attempts/{$attempt->id}/suggestions?".http_build_query([
            'questionId' => 'fill-01',
            'partId' => 'p01',
            'prefix' => ' жел ',
        ]))->assertOk()->assertExactJson([
            'data' => ['suggestions' => []],
        ])->assertHeader('Cache-Control', 'no-store, private');

        $this->getJson("{$url}/attempts/{$attempt->id}/suggestions?".http_build_query([
            'questionId' => 'fill-01',
            'partId' => 'p01',
            'prefix' => ' желта ',
        ]))->assertOk()->assertExactJson([
            'data' => ['suggestions' => ['Жёлтая связка']],
        ]);

        $this->getJson("{$url}/attempts/{$attempt->id}/suggestions?".http_build_query([
            'questionId' => 'fill-01',
            'partId' => 'p01',
            'prefix' => 'мем',
        ]))->assertOk()->assertExactJson([
            'data' => ['suggestions' => []],
        ]);

        $this->getJson("{$url}/attempts/{$attempt->id}/suggestions?".http_build_query([
            'questionId' => 'fill-01',
            'partId' => 'p01',
            'prefix' => 'дру',
        ]))->assertOk()->assertExactJson(['data' => ['suggestions' => []]]);

        $this->getJson("{$url}/attempts/{$attempt->id}/suggestions?".http_build_query([
            'questionId' => 'expanded-01',
            'partId' => 'answer',
            'prefix' => 'шей',
        ]))->assertOk()->assertExactJson(['data' => ['suggestions' => []]]);

        $this->getJson("{$url}/attempts/{$attempt->id}/suggestions?".http_build_query([
            'questionId' => 'manual-01',
            'partId' => 'answer',
            'prefix' => 'эта',
        ]))->assertForbidden()->assertExactJson(
            $this->domainError('LEARNING_ASSESSMENT_SUGGESTION_FORBIDDEN'),
        );
    }

    public function test_show_returns_safe_published_contract_and_uses_scoped_active_chain(): void
    {
        $user = User::factory()->create();
        [$subject, $course, $lesson, $assessment, $version] = $this->createAssessmentChain();
        [, $otherCourse] = $this->createCatalogChain('other-subject', 'other-course', 'other-lesson');
        $version->sections = [
            ...$version->sections,
            [
                'id' => 'unsafe',
                'title' => 'Unsafe metadata must be rejected',
                'storage' => 'section-storage-sentinel',
            ],
        ];
        $version->save();
        $this->grant($user, $lesson);
        Sanctum::actingAs($user);

        $response = $this->getJson($this->assessmentUrl($subject, $course, $lesson))
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('data.assessment.id', $assessment->id)
            ->assertJsonPath('data.assessment.contentVersion', 1)
            ->assertJsonPath('data.assessment.title', $lesson->title)
            ->assertJsonPath('data.assessment.totalCount', 2)
            ->assertJsonPath('data.assessment.retakeAvailable', true)
            ->assertJsonCount(2, 'data.assessment.sections')
            ->assertJsonPath('data.assessment.sections.0.id', 'fundamentals')
            ->assertJsonPath('data.assessment.sections.0.title', 'Version one fundamentals')
            ->assertJsonPath('data.assessment.questions.1.assetPath', $this->assessmentUrl($subject, $course, $lesson).'/assets/diagram-01')
            ->assertJsonPath('data.assessment.questions.1.width', 640)
            ->assertJsonPath('data.assessment.questions.1.height', 480)
            ->assertJsonPath('data.assessment.questions.1.callouts.0.targetId', 'p01-1')
            ->assertJsonPath('data.assessment.questions.1.callouts.0.labelSide', 'top')
            ->assertJsonPath('data.assessment.questions.1.callouts.0.labelWidth', 0.24)
            ->assertJsonPath('data.assessment.questions.1.callouts.0.leaderStyle', 'straight')
            ->assertJsonPath('data.assessment.questions.1.callouts.0.targetMarker', 'arrow')
            ->assertJsonPath('data.attempt', null);

        $payload = (string) $response->getContent();
        $this->assertDoesNotMatchRegularExpression(
            '/answer[_-]?key|is[_-]?correct|correctness|distractor|source|storage|object[_-]?key|disk/i',
            $payload,
        );
        $this->assertStringNotContainsString((string) $version->source_key_sha256, $payload);

        $this->getJson($this->assessmentUrl($subject, $otherCourse, $lesson))
            ->assertNotFound()
            ->assertExactJson($this->lessonNotFound());

        foreach ([$subject, $course, $lesson] as $inactiveModel) {
            $inactiveModel->forceFill(['is_active' => false])->save();
            $this->getJson($this->assessmentUrl($subject, $course, $lesson))
                ->assertNotFound()
                ->assertExactJson($this->lessonNotFound());
            $inactiveModel->forceFill(['is_active' => true])->save();
        }
    }

    public function test_show_and_store_use_active_attempt_before_completion_then_current_publication(): void
    {
        $user = User::factory()->create();
        [$subject, $course, $lesson, $assessment, $versionOne] = $this->createAssessmentChain();
        $versionOne->forceFill(['status' => LessonAssessmentVersion::STATUS_RETIRED])->save();
        $versionTwo = $this->createVersion(
            $assessment,
            2,
            LessonAssessmentVersion::STATUS_PUBLISHED,
            'Version two',
            [
                ['id' => 'fundamentals', 'title' => 'Version two fundamentals'],
                ['id' => 'diagrams', 'title' => 'Version two diagrams'],
            ],
        );
        $activeAttempt = $this->createAttempt($user, $assessment, $versionOne);
        $this->grant($user, $lesson);
        Sanctum::actingAs($user);

        $this->getJson($this->assessmentUrl($subject, $course, $lesson))
            ->assertOk()
            ->assertJsonPath('data.assessment.contentVersion', 1)
            ->assertJsonPath('data.assessment.sections.0.title', 'Version one fundamentals')
            ->assertJsonPath('data.attempt.id', $activeAttempt->id);

        $this->postJson($this->assessmentUrl($subject, $course, $lesson).'/attempts')
            ->assertOk()
            ->assertJsonPath('data.assessment.contentVersion', 1)
            ->assertJsonPath('data.assessment.sections.0.title', 'Version one fundamentals')
            ->assertJsonPath('data.attempt.id', $activeAttempt->id);

        $activeAttempt->forceFill([
            'status' => LessonAssessmentAttempt::STATUS_PASSED,
            'passed_at' => now(),
        ])->save();
        LessonAssessmentCompletion::query()->forceCreate([
            'user_id' => $user->id,
            'lesson_id' => $lesson->id,
            'lesson_assessment_id' => $assessment->id,
            'passed_attempt_id' => $activeAttempt->id,
            'completed_at' => now(),
        ]);
        $newerAttempt = $this->createAttempt($user, $assessment, $versionTwo);

        $this->getJson($this->assessmentUrl($subject, $course, $lesson))
            ->assertOk()
            ->assertJsonPath('data.assessment.contentVersion', 2)
            ->assertJsonPath('data.assessment.sections.0.title', 'Version two fundamentals')
            ->assertJsonPath('data.attempt.id', $newerAttempt->id);

        $this->postJson($this->assessmentUrl($subject, $course, $lesson).'/attempts')
            ->assertOk()
            ->assertJsonPath('data.assessment.contentVersion', 2)
            ->assertJsonPath('data.attempt.id', $newerAttempt->id);
    }

    public function test_show_keeps_failed_attempt_on_its_historical_version_after_new_publication(): void
    {
        $user = User::factory()->create();
        [$subject, $course, $lesson, $assessment, $versionOne] = $this->createAssessmentChain();
        $versionOne->forceFill(['status' => LessonAssessmentVersion::STATUS_RETIRED])->save();
        $this->createVersion(
            $assessment,
            2,
            LessonAssessmentVersion::STATUS_PUBLISHED,
            'Version two',
            [
                ['id' => 'fundamentals', 'title' => 'Version two fundamentals'],
                ['id' => 'diagrams', 'title' => 'Version two diagrams'],
            ],
        );
        $attempt = $this->createAttempt($user, $assessment, $versionOne);
        $attempt->forceFill([
            'status' => LessonAssessmentAttempt::STATUS_FAILED,
            'correct_count' => 1,
            'revision' => 1,
            'check_count' => 1,
            'checked_at' => now(),
        ])->save();
        LessonAssessmentAttemptResponse::query()->forceCreate([
            'lesson_assessment_attempt_id' => $attempt->id,
            'question_id' => 'question-01',
            'response' => ['selectedOptionId' => 'o1'],
            'is_complete' => true,
        ]);
        LessonAssessmentAttemptResponse::query()->forceCreate([
            'lesson_assessment_attempt_id' => $attempt->id,
            'question_id' => 'diagram-01',
            'response' => ['selections' => ['name' => 'o1', 'p01' => 'o1']],
            'is_complete' => true,
        ]);
        $this->grant($user, $lesson);
        Sanctum::actingAs($user);

        $this->getJson($this->assessmentUrl($subject, $course, $lesson))
            ->assertOk()
            ->assertJsonPath('data.assessment.contentVersion', 1)
            ->assertJsonPath('data.assessment.sections.0.title', 'Version one fundamentals')
            ->assertJsonPath('data.attempt.id', $attempt->id)
            ->assertJsonPath('data.attempt.status', LessonAssessmentAttempt::STATUS_FAILED);
    }

    public function test_completed_user_can_start_one_blank_retake_on_the_current_publication(): void
    {
        $user = User::factory()->create();
        [$subject, $course, $lesson, $assessment, $versionOne] = $this->createAssessmentChain();
        $original = $this->createAttempt($user, $assessment, $versionOne);
        $original->forceFill([
            'status' => LessonAssessmentAttempt::STATUS_PASSED,
            'correct_count' => 48,
            'passed_at' => now()->subDay(),
        ])->save();
        LessonAssessmentCompletion::query()->forceCreate([
            'user_id' => $user->id,
            'lesson_id' => $lesson->id,
            'lesson_assessment_id' => $assessment->id,
            'passed_attempt_id' => $original->id,
            'completed_at' => now()->subDay(),
        ]);
        $versionOne->forceFill(['status' => LessonAssessmentVersion::STATUS_RETIRED])->save();
        $versionTwo = $this->createVersion(
            $assessment,
            2,
            LessonAssessmentVersion::STATUS_PUBLISHED,
            'Version two',
            [
                ['id' => 'fundamentals', 'title' => 'Version two fundamentals'],
                ['id' => 'diagrams', 'title' => 'Version two diagrams'],
            ],
        );
        $this->grant($user, $lesson);
        Sanctum::actingAs($user);
        $attemptsUrl = $this->assessmentUrl($subject, $course, $lesson).'/attempts';

        $created = $this->postJson($attemptsUrl, ['retake' => true])
            ->assertCreated()
            ->assertJsonPath('data.assessment.contentVersion', 2)
            ->assertJsonPath('data.assessment.retakeAvailable', false)
            ->assertJsonPath('data.attempt.status', LessonAssessmentAttempt::STATUS_IN_PROGRESS)
            ->assertJsonCount(0, 'data.attempt.responses');
        $retakeId = (int) $created->json('data.attempt.id');

        $this->assertNotSame($original->id, $retakeId);
        $this->assertSame($versionTwo->id, LessonAssessmentAttempt::query()->findOrFail($retakeId)->lesson_assessment_version_id);
        $this->postJson($attemptsUrl, ['retake' => true])
            ->assertOk()
            ->assertJsonPath('data.attempt.id', $retakeId);
        $this->getJson($this->assessmentUrl($subject, $course, $lesson))
            ->assertOk()
            ->assertJsonPath('data.assessment.contentVersion', 2)
            ->assertJsonPath('data.attempt.id', $retakeId);
        $this->assertSame($original->id, LessonAssessmentCompletion::query()->firstOrFail()->passed_attempt_id);

        foreach ([
            ['retake' => 'true'],
            ['retake' => true, 'status' => 'passed'],
        ] as $invalidPayload) {
            $this->postJson($attemptsUrl, $invalidPayload)
                ->assertUnprocessable()
                ->assertHeader('Cache-Control', 'no-store, private');
        }
    }

    public function test_presenter_and_progress_agree_on_retake_availability_for_every_attempt_state(): void
    {
        $student = User::factory()->create();
        $attempts = app(LessonAssessmentAttemptService::class);
        $reviews = app(LessonAssessmentManualReviewService::class);
        $states = [];

        [$subject, $course, $lesson] = $this->createAssessmentChain();
        $this->grant($student, $lesson);
        $states['no_attempt'] = [$subject, $course, $lesson, null, 'not_started', true];

        [$subject, $course, $lesson, $assessment, $version] = $this->createAssessmentChain();
        $inProgress = $this->createAttempt($student, $assessment, $version);
        $this->grant($student, $lesson);
        $states['in_progress'] = [$subject, $course, $lesson, $inProgress->status, $inProgress->status, false];

        [$subject, $course, $lesson, $assessment, $version] = $this->createAssessmentChain();
        $pending = $this->createAttempt($student, $assessment, $version);
        $pending = $attempts->save($student, $pending, 0, [
            ['questionId' => 'question-01', 'response' => ['selectedOptionId' => 'o1']],
            ['questionId' => 'diagram-01', 'response' => ['selections' => ['name' => 'o1', 'p01' => 'o2']]],
        ], 'diagram-01');
        $pending = $attempts->submit($student, $pending, 1)['attempt'];
        $this->grant($student, $lesson);
        $states['pending_review'] = [$subject, $course, $lesson, $pending->status, $pending->status, false];

        [$subject, $course, $lesson, $assessment, $version] = $this->createAssessmentChain();
        $failed = $this->createAttempt($student, $assessment, $version);
        $failed = $attempts->save($student, $failed, 0, [
            ['questionId' => 'question-01', 'response' => ['selectedOptionId' => 'o2']],
            ['questionId' => 'diagram-01', 'response' => ['selections' => ['name' => 'o1', 'p01' => 'o2']]],
        ], 'diagram-01');
        $failed = $attempts->submit($student, $failed, 1)['attempt'];
        $this->grant($student, $lesson);
        $states['failed'] = [$subject, $course, $lesson, $failed->status, $failed->status, true];

        [$subject, $course, $lesson, $assessment, $version] = $this->createAssessmentChain();
        $passed = $this->createAttempt($student, $assessment, $version);
        $passed = $attempts->save($student, $passed, 0, [
            ['questionId' => 'question-01', 'response' => ['selectedOptionId' => 'o1']],
            ['questionId' => 'diagram-01', 'response' => ['selections' => ['name' => 'o1', 'p01' => 'o2']]],
        ], 'diagram-01');
        $passed = $attempts->submit($student, $passed, 1)['attempt'];
        $review = $passed->manualReviews()->firstOrFail();
        $reviewer = User::factory()->create(['role' => 'admin_full']);
        $reviews->update($reviewer, $passed->id, $review->id, 0, 10000, null, 'retake-matrix-review');
        $passed->refresh();
        $reviews->finalize($reviewer, $passed->id, $passed->revision, 'retake-matrix-finalize');
        $passed->refresh();
        $this->grant($student, $lesson);
        $states['passed'] = [$subject, $course, $lesson, $passed->status, $passed->status, true];

        Sanctum::actingAs($student);
        foreach ($states as $label => [$subject, $course, $lesson, $presenterStatus, $progressStatus, $expected]) {
            $response = $this->getJson($this->assessmentUrl($subject, $course, $lesson))->assertOk();
            $progress = app(LessonAssessmentProgressService::class)->forLessons($student->id, [$lesson->id]);

            $this->assertSame($presenterStatus, $response->json('data.attempt.status'), "{$label} presenter status");
            $this->assertSame($progressStatus, $progress[$lesson->id]['testProgress']['status'], "{$label} progress status");
            $this->assertSame($expected, $response->json('data.assessment.retakeAvailable'), "{$label} presenter retake");
            $this->assertSame($expected, $progress[$lesson->id]['testProgress']['retakeAvailable'], "{$label} progress retake");
        }
    }

    public function test_show_returns_assessment_not_found_without_a_published_or_pinned_version(): void
    {
        $user = User::factory()->create();
        [$subject, $course, $lesson, , $version] = $this->createAssessmentChain();
        $version->forceFill([
            'status' => LessonAssessmentVersion::STATUS_DRAFT,
            'published_at' => null,
        ])->save();
        $this->grant($user, $lesson);
        Sanctum::actingAs($user);

        $this->getJson($this->assessmentUrl($subject, $course, $lesson))
            ->assertNotFound()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertExactJson($this->domainError('LEARNING_ASSESSMENT_NOT_FOUND'));
    }

    public function test_completed_attempt_stays_visible_without_a_current_retake_version(): void
    {
        $user = User::factory()->create();
        [$subject, $course, $lesson, $assessment, $version] = $this->createAssessmentChain();
        $attempt = $this->createAttempt($user, $assessment, $version);
        $attempt->forceFill([
            'status' => LessonAssessmentAttempt::STATUS_PASSED,
            'correct_count' => 48,
            'passed_at' => now(),
        ])->save();
        LessonAssessmentCompletion::query()->forceCreate([
            'user_id' => $user->id,
            'lesson_id' => $lesson->id,
            'lesson_assessment_id' => $assessment->id,
            'passed_attempt_id' => $attempt->id,
            'completed_at' => now(),
        ]);
        $version->forceFill(['status' => LessonAssessmentVersion::STATUS_RETIRED])->save();
        $this->grant($user, $lesson);
        Sanctum::actingAs($user);
        $assessmentUrl = $this->assessmentUrl($subject, $course, $lesson);

        $this->getJson($assessmentUrl)
            ->assertOk()
            ->assertJsonPath('data.attempt.id', $attempt->id)
            ->assertJsonPath('data.assessment.retakeAvailable', false);
        $this->postJson("{$assessmentUrl}/attempts", ['retake' => true])
            ->assertNotFound()
            ->assertExactJson($this->domainError('LEARNING_ASSESSMENT_NOT_FOUND'));
    }

    public function test_store_creates_once_and_patch_enforces_exact_validated_payload_and_revision(): void
    {
        $user = User::factory()->create();
        [$subject, $course, $lesson] = $this->createAssessmentChain();
        $this->grant($user, $lesson);
        Sanctum::actingAs($user);
        $attemptsUrl = $this->assessmentUrl($subject, $course, $lesson).'/attempts';

        $created = $this->postJson($attemptsUrl)
            ->assertCreated()
            ->assertJsonPath('data.attempt.revision', 0);
        $attemptId = (int) $created->json('data.attempt.id');

        $this->postJson($attemptsUrl)
            ->assertOk()
            ->assertJsonPath('data.attempt.id', $attemptId);

        $attemptUrl = "{$attemptsUrl}/{$attemptId}";
        $this->patchJson($attemptUrl, [
            'revision' => 0,
            'lastQuestionId' => 'question-01',
            'responses' => [[
                'questionId' => 'question-01',
                'response' => ['selectedOptionId' => 'o1'],
            ]],
        ])->assertOk()
            ->assertJsonPath('data.attempt.revision', 1)
            ->assertJsonPath('data.attempt.answeredCount', 1)
            ->assertJsonPath('data.attempt.responses.0.response.selectedOptionId', 'o1');

        $this->patchJson($attemptUrl, [
            'revision' => 0,
            'lastQuestionId' => null,
            'responses' => [],
        ])->assertConflict()
            ->assertExactJson($this->domainError('LEARNING_ASSESSMENT_ATTEMPT_CONFLICT'));

        $this->patchJson($attemptUrl, [
            'revision' => 1,
            'lastQuestionId' => null,
            'responses' => [[
                'questionId' => 'question-02',
                'response' => ['selectedOptionId' => 'unknown'],
            ]],
        ])->assertUnprocessable()
            ->assertExactJson($this->domainError('LEARNING_ASSESSMENT_RESPONSE_INVALID'));

        $this->patchJson($attemptUrl, [
            'revision' => 1,
            'lastQuestionId' => null,
            'responses' => [],
            'lockQuestionIds' => ['question-01'],
        ])->assertOk()
            ->assertJsonPath('data.attempt.revision', 2)
            ->assertJsonPath('data.attempt.lockedQuestionIds.0', 'question-01');

        $this->patchJson($attemptUrl, [
            'revision' => 2,
            'lastQuestionId' => null,
            'responses' => [[
                'questionId' => 'question-01',
                'response' => ['selectedOptionId' => 'o2'],
            ]],
        ])->assertConflict()
            ->assertExactJson($this->domainError('LEARNING_ASSESSMENT_LOCKED_RESPONSE'));

        foreach ([
            [
                'revision' => 2,
                'lastQuestionId' => null,
                'responses' => [],
                'status' => 'passed',
            ],
            [
                'revision' => 2,
                'lastQuestionId' => null,
                'responses' => [
                    ['questionId' => 'question-01', 'response' => ['selectedOptionId' => 'o1']],
                    ['questionId' => 'question-01', 'response' => ['selectedOptionId' => 'o1']],
                ],
            ],
            [
                'revision' => 2,
                'lastQuestionId' => null,
                'responses' => [[
                    'questionId' => 'question-02',
                    'response' => ['o1'],
                ]],
            ],
            [
                'revision' => 2,
                'lastQuestionId' => null,
                'responses' => [],
                'lockQuestionIds' => ['question-01', 'question-01'],
            ],
            [
                'revision' => 2,
                'lastQuestionId' => null,
                'responses' => [],
                'lockQuestionIds' => ['question-unknown'],
            ],
        ] as $invalidPayload) {
            $this->patchJson($attemptUrl, $invalidPayload)
                ->assertUnprocessable()
                ->assertHeader('Cache-Control', 'no-store, private');
        }

        $tooMany = array_map(
            fn (int $number): array => [
                'questionId' => "question-{$number}",
                'response' => ['selectedOptionId' => 'o1'],
            ],
            range(1, 49),
        );
        $this->patchJson($attemptUrl, [
            'revision' => 2,
            'lastQuestionId' => null,
            'responses' => $tooMany,
        ])->assertUnprocessable();
    }

    public function test_ordered_choice_partial_draft_is_saved_but_not_counted_and_complete_answer_can_pass(): void
    {
        $user = User::factory()->create();
        [$subject, $course, $lesson, , $version] = $this->createAssessmentChain();
        $version->forceFill([
            'questions' => [
                [
                    'id' => 'question-01',
                    'sectionId' => 'fundamentals',
                    'type' => 'ordered_choice',
                    'gradingMode' => 'automatic',
                    'prompt' => 'Order every option.',
                    'options' => [
                        ['id' => 'o1', 'label' => 'Option 1'],
                        ['id' => 'o2', 'label' => 'Option 2'],
                        ['id' => 'o3', 'label' => 'Option 3'],
                    ],
                ],
                [
                    'id' => 'manual-01',
                    'sectionId' => 'fundamentals',
                    'type' => 'long_text',
                    'gradingMode' => 'manual',
                    'prompt' => 'Explain.',
                    'rows' => 3,
                ],
            ],
            'answer_key' => [
                'question-01' => ['response' => ['selectedOptionIds' => ['o1', 'o2', 'o3']]],
                'manual-01' => ['response' => ['referenceAnswers' => ['Expected']]],
            ],
        ])->save();
        $this->grant($user, $lesson);
        Sanctum::actingAs($user);
        $attemptsUrl = $this->assessmentUrl($subject, $course, $lesson).'/attempts';
        $attemptId = (int) $this->postJson($attemptsUrl)
            ->assertCreated()
            ->json('data.attempt.id');
        $attemptUrl = "{$attemptsUrl}/{$attemptId}";

        $this->patchJson($attemptUrl, [
            'revision' => 0,
            'lastQuestionId' => 'question-01',
            'responses' => [[
                'questionId' => 'question-01',
                'response' => ['selectedOptionIds' => ['o1', 'o2']],
            ]],
        ])->assertOk()
            ->assertJsonPath('data.attempt.revision', 1)
            ->assertJsonPath('data.attempt.answeredCount', 0)
            ->assertJsonPath('data.attempt.responses.0.response.selectedOptionIds', ['o1', 'o2']);

        $this->postJson("{$attemptUrl}/submissions", ['revision' => 1])
            ->assertUnprocessable()
            ->assertExactJson($this->domainError('LEARNING_ASSESSMENT_INCOMPLETE'));

        $this->patchJson($attemptUrl, [
            'revision' => 1,
            'lastQuestionId' => 'question-01',
            'responses' => [[
                'questionId' => 'question-01',
                'response' => ['selectedOptionIds' => ['o1', 'o2', 'o3']],
            ], [
                'questionId' => 'manual-01',
                'response' => ['text' => 'Answer'],
            ]],
        ])->assertOk()
            ->assertJsonPath('data.attempt.revision', 2)
            ->assertJsonPath('data.attempt.answeredCount', 2);

        $this->postJson("{$attemptUrl}/submissions", ['revision' => 2])
            ->assertOk()
            ->assertJsonPath('data.attempt.status', 'pending_review')
            ->assertJsonPath('data.submission.automaticGate.passed', true)
            ->assertJsonPath('data.submission.automaticGate.questionResults.0.correct', true);
    }

    public function test_it_saves_all_responses_for_a_version_with_more_than_forty_eight_questions(): void
    {
        $user = User::factory()->create();
        [$subject, $course, $lesson, , $version] = $this->createAssessmentChain();
        $questions = [];
        $answerKey = [];
        $responses = [];

        foreach (range(1, 49) as $number) {
            $questionId = sprintf('question-%02d', $number);
            $questions[] = [
                'id' => $questionId,
                'sectionId' => 'fundamentals',
                'type' => 'single_choice',
                'gradingMode' => 'automatic',
                'prompt' => "Question {$number}",
                'options' => [
                    ['id' => 'o1', 'label' => 'Option 1'],
                    ['id' => 'o2', 'label' => 'Option 2'],
                ],
            ];
            $answerKey[$questionId] = ['response' => ['selectedOptionId' => 'o1']];
            $responses[] = [
                'questionId' => $questionId,
                'response' => ['selectedOptionId' => 'o1'],
            ];
        }

        $version->forceFill([
            'questions' => $questions,
            'answer_key' => $answerKey,
        ])->save();
        $this->grant($user, $lesson);
        Sanctum::actingAs($user);
        $attemptsUrl = $this->assessmentUrl($subject, $course, $lesson).'/attempts';
        $attemptId = (int) $this->postJson($attemptsUrl)
            ->assertCreated()
            ->json('data.attempt.id');

        $this->patchJson("{$attemptsUrl}/{$attemptId}", [
            'revision' => 0,
            'lastQuestionId' => 'question-49',
            'responses' => $responses,
        ])->assertOk()
            ->assertJsonPath('data.attempt.answeredCount', 49)
            ->assertJsonCount(49, 'data.attempt.responses');
    }

    public function test_attempt_lookup_is_scoped_to_current_user_assessment_and_version(): void
    {
        $owner = User::factory()->create();
        $attacker = User::factory()->create();
        [$subject, $course, $lesson, $assessment, $version] = $this->createAssessmentChain();
        $attempt = $this->createAttempt($owner, $assessment, $version);
        $this->grant($attacker, $lesson);
        Sanctum::actingAs($attacker);

        $payload = ['revision' => 0, 'lastQuestionId' => null, 'responses' => []];
        $url = $this->assessmentUrl($subject, $course, $lesson)."/attempts/{$attempt->id}";

        $this->patchJson($url, $payload)
            ->assertNotFound()
            ->assertExactJson($this->domainError('LEARNING_ASSESSMENT_ATTEMPT_NOT_FOUND'));
        $this->postJson("{$url}/submissions", ['revision' => 0])
            ->assertNotFound()
            ->assertExactJson($this->domainError('LEARNING_ASSESSMENT_ATTEMPT_NOT_FOUND'));
    }

    public function test_check_returns_only_safe_results_and_exact_domain_errors(): void
    {
        $user = User::factory()->create();
        [$subject, $course, $lesson, $assessment, $version] = $this->createAssessmentChain();
        $attempt = $this->createAttempt($user, $assessment, $version);
        $this->grant($user, $lesson);
        Sanctum::actingAs($user);
        $url = $this->assessmentUrl($subject, $course, $lesson)."/attempts/{$attempt->id}";

        $this->postJson("{$url}/submissions", ['revision' => 0])
            ->assertUnprocessable()
            ->assertExactJson($this->domainError('LEARNING_ASSESSMENT_INCOMPLETE'));

        LessonAssessmentAttemptResponse::query()->forceCreate([
            'lesson_assessment_attempt_id' => $attempt->id,
            'question_id' => 'question-01',
            'response' => ['selectedOptionId' => 'o2'],
            'is_complete' => true,
        ]);
        LessonAssessmentAttemptResponse::query()->forceCreate([
            'lesson_assessment_attempt_id' => $attempt->id,
            'question_id' => 'diagram-01',
            'response' => ['selections' => ['name' => 'o1', 'p01' => 'o1']],
            'is_complete' => true,
        ]);

        $response = $this->postJson("{$url}/submissions", ['revision' => 0])
            ->assertOk()
            ->assertJsonPath('data.attempt.status', LessonAssessmentAttempt::STATUS_FAILED)
            ->assertJsonPath('data.attempt.failureReason', 'automatic_threshold_not_met')
            ->assertJsonPath('data.submission.number', 1)
            ->assertJsonPath('data.submission.automaticGate.passed', false)
            ->assertJsonPath('data.submission.automaticGate.overall.percentBps', 0)
            ->assertJsonPath('data.submission.automaticGate.questionResults.0.questionId', 'question-01')
            ->assertJsonPath('data.submission.automaticGate.questionResults.0.correct', false)
            ->assertJsonMissingPath('data.submission.automaticGate.checked')
            ->assertJsonMissingPath('data.submission.automaticGate.mastered')
            ->assertJsonPath('data.attempt.automaticGate.questionResults.0.questionId', 'question-01')
            ->assertJsonPath('data.attempt.automaticGate.questionResults.0.correct', false)
            ->assertJsonMissingPath('data.attempt.score')
            ->assertJsonMissingPath('data.attempt.manualResults');

        $payload = (string) $response->getContent();
        $this->assertDoesNotMatchRegularExpression('/answer[_-]?key|rubric|storage|object[_-]?key|reviewer|audit/i', $payload);

        $this->postJson("{$url}/submissions", ['revision' => 0, 'userId' => $user->id])
            ->assertUnprocessable()
            ->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_failed_attempt_is_read_only_reloadable_and_retake_starts_clean(): void
    {
        $user = User::factory()->create();
        [$subject, $course, $lesson, $assessment, $version] = $this->createAssessmentChain();
        $attempt = $this->createAttempt($user, $assessment, $version);
        $this->grant($user, $lesson);
        Sanctum::actingAs($user);
        $url = $this->assessmentUrl($subject, $course, $lesson)."/attempts/{$attempt->id}";

        LessonAssessmentAttemptResponse::query()->forceCreate([
            'lesson_assessment_attempt_id' => $attempt->id,
            'question_id' => 'question-01',
            'response' => ['selectedOptionId' => 'o2'],
            'is_complete' => true,
        ]);
        LessonAssessmentAttemptResponse::query()->forceCreate([
            'lesson_assessment_attempt_id' => $attempt->id,
            'question_id' => 'diagram-01',
            'response' => ['selections' => ['name' => 'o1', 'p01' => 'o1']],
            'is_complete' => true,
        ]);

        $this->postJson("{$url}/submissions", ['revision' => 0])
            ->assertOk()
            ->assertJsonPath('data.attempt.status', LessonAssessmentAttempt::STATUS_FAILED)
            ->assertJsonPath('data.attempt.revision', 1)
            ->assertJsonPath('data.attempt.automaticGate.passed', false)
            ->assertJsonMissingPath('data.attempt.score');

        $this->patchJson($url, [
            'revision' => 1,
            'lastQuestionId' => 'diagram-01',
            'responses' => [[
                'questionId' => 'diagram-01',
                'response' => ['selections' => ['name' => 'o1', 'p01' => 'o2']],
            ]],
        ])->assertConflict()
            ->assertExactJson($this->domainError('LEARNING_ASSESSMENT_ATTEMPT_CONFLICT'));

        $this->postJson("{$url}/submissions", ['revision' => 1])
            ->assertConflict()
            ->assertExactJson($this->domainError('LEARNING_ASSESSMENT_ATTEMPT_CONFLICT'));

        $this->getJson($this->assessmentUrl($subject, $course, $lesson))
            ->assertOk()
            ->assertJsonPath('data.attempt.id', $attempt->id)
            ->assertJsonPath('data.attempt.status', LessonAssessmentAttempt::STATUS_FAILED)
            ->assertJsonPath('data.attempt.automaticGate.passed', false)
            ->assertJsonPath('data.attempt.automaticGate.questionResults.0.correct', false)
            ->assertJsonMissingPath('data.attempt.score')
            ->assertJsonMissingPath('data.attempt.manualResults');

        $failedProgress = app(LessonAssessmentProgressService::class)->forLessons($user->id, [$lesson->id]);
        $this->assertSame(LessonAssessmentAttempt::STATUS_FAILED, $failedProgress[$lesson->id]['testProgress']['status']);
        $this->assertNull($failedProgress[$lesson->id]['testProgress']['score']);
        $this->assertSame(0, $failedProgress[$lesson->id]['testProgress']['automaticGate']['overall']['percentBps']);
        $this->assertFalse($failedProgress[$lesson->id]['testProgress']['automaticGate']['passed']);
        $this->assertTrue($failedProgress[$lesson->id]['testProgress']['retakeAvailable']);

        $retake = $this->postJson($this->assessmentUrl($subject, $course, $lesson).'/attempts', [
            'retake' => true,
        ])->assertCreated()
            ->assertJsonPath('data.attempt.status', LessonAssessmentAttempt::STATUS_IN_PROGRESS)
            ->assertJsonPath('data.attempt.submissionCount', 0)
            ->assertJsonMissingPath('data.attempt.score')
            ->assertJsonMissingPath('data.attempt.automaticGate')
            ->assertJsonCount(0, 'data.attempt.responses');

        $this->assertNotSame($attempt->id, $retake->json('data.attempt.id'));

        $attempt->refresh();
        $this->assertSame(LessonAssessmentAttempt::STATUS_FAILED, $attempt->status);
        $this->assertSame(1, $attempt->check_count);
        $this->assertNotNull($attempt->checked_at);
        $progress = app(LessonAssessmentProgressService::class)->forLessons($user->id, [$lesson->id]);
        $this->assertNull($progress[$lesson->id]['testProgress']['score']);
        $this->assertFalse($progress[$lesson->id]['testProgress']['mastered']);
    }

    public function test_pending_and_finalized_student_contracts_separate_automatic_and_final_results_without_protected_review_data(): void
    {
        $student = User::factory()->create();
        [$subject, $course, $lesson, $assessment, $version] = $this->createAssessmentChain();
        $attempt = $this->createAttempt($student, $assessment, $version);
        $this->grant($student, $lesson);
        Sanctum::actingAs($student);
        $url = $this->assessmentUrl($subject, $course, $lesson)."/attempts/{$attempt->id}";

        $this->patchJson($url, [
            'revision' => 0,
            'lastQuestionId' => 'diagram-01',
            'responses' => [
                ['questionId' => 'question-01', 'response' => ['selectedOptionId' => 'o1']],
                ['questionId' => 'diagram-01', 'response' => ['selections' => ['name' => 'o1', 'p01' => 'o2']]],
            ],
        ])->assertOk();

        $pending = $this->postJson("{$url}/submissions", ['revision' => 1])
            ->assertOk()
            ->assertJsonPath('data.assessment.scoringPolicy.mode', 'hybrid')
            ->assertJsonPath('data.assessment.questions.0.gradingMode', 'automatic')
            ->assertJsonPath('data.assessment.questions.1.gradingMode', 'manual')
            ->assertJsonPath('data.assessment.retakeAvailable', false)
            ->assertJsonPath('data.attempt.status', LessonAssessmentAttempt::STATUS_PENDING_REVIEW)
            ->assertJsonPath('data.attempt.automaticGate.passed', true)
            ->assertJsonPath('data.attempt.automaticGate.overall.percentBps', 10000)
            ->assertJsonPath('data.attempt.pendingManualQuestionCount', 1)
            ->assertJsonPath('data.attempt.reviewedManualQuestionCount', 0)
            ->assertJsonPath('data.attempt.reviewedManualQuestionIds', [])
            ->assertJsonPath('data.attempt.manualProgressBps', 0)
            ->assertJsonMissingPath('data.attempt.score')
            ->assertJsonMissingPath('data.attempt.manualResults');
        $this->assertDatabaseCount('lesson_assessment_completions', 0);
        $pendingProgress = app(LessonAssessmentProgressService::class)->forLessons($student->id, [$lesson->id]);
        $this->assertSame(
            LessonAssessmentAttempt::STATUS_PENDING_REVIEW,
            $pendingProgress[$lesson->id]['testProgress']['status'],
        );
        $this->assertNull($pendingProgress[$lesson->id]['testProgress']['score']);
        $this->assertSame(10000, $pendingProgress[$lesson->id]['testProgress']['automaticGate']['overall']['percentBps']);
        $this->assertFalse($pendingProgress[$lesson->id]['testProgress']['retakeAvailable']);
        $this->assertDoesNotMatchRegularExpression(
            '/answer[_-]?key|rubric|reviewer|audit|responseSnapshot|rubricSnapshot/i',
            (string) $pending->getContent(),
        );

        $attempt->refresh();
        $review = $attempt->manualReviews()->firstOrFail();
        $reviewer = User::factory()->create(['role' => 'admin_full']);
        $reviews = app(LessonAssessmentManualReviewService::class);
        $reviews->update(
            $reviewer,
            $attempt->id,
            $review->id,
            0,
            8000,
            'Хорошее обоснование',
            'student-contract-review',
        );
        $attempt->refresh();
        $reviewed = $this->getJson($this->assessmentUrl($subject, $course, $lesson))
            ->assertOk()
            ->assertJsonPath('data.attempt.status', LessonAssessmentAttempt::STATUS_PENDING_REVIEW)
            ->assertJsonPath('data.attempt.pendingManualQuestionCount', 0)
            ->assertJsonPath('data.attempt.reviewedManualQuestionCount', 1)
            ->assertJsonPath('data.attempt.reviewedManualQuestionIds', ['diagram-01'])
            ->assertJsonPath('data.attempt.manualProgressBps', 8000)
            ->assertJsonMissingPath('data.attempt.score')
            ->assertJsonMissingPath('data.attempt.manualResults');
        $this->assertStringNotContainsString('Хорошее обоснование', (string) $reviewed->getContent());

        $reviews->finalize(
            $reviewer,
            $attempt->id,
            $attempt->revision,
            'student-contract-finalize',
        );

        $finalized = $this->getJson($this->assessmentUrl($subject, $course, $lesson))
            ->assertOk()
            ->assertJsonPath('data.attempt.status', LessonAssessmentAttempt::STATUS_PASSED)
            ->assertJsonPath('data.attempt.reviewedManualQuestionIds', ['diagram-01'])
            ->assertJsonMissingPath('data.attempt.manualProgressBps')
            ->assertJsonPath('data.attempt.score.overall.percentBps', 9400)
            ->assertJsonPath('data.attempt.score.passed', true)
            ->assertJsonPath('data.attempt.manualResults.0.questionId', 'diagram-01')
            ->assertJsonPath('data.attempt.manualResults.0.scoreBps', 8000)
            ->assertJsonPath('data.attempt.manualResults.0.comment', 'Хорошее обоснование');
        $this->assertDatabaseHas('lesson_assessment_completions', [
            'user_id' => $student->id,
            'passed_attempt_id' => $attempt->id,
        ]);
        $finalProgress = app(LessonAssessmentProgressService::class)->forLessons($student->id, [$lesson->id]);
        $this->assertSame(9400, $finalProgress[$lesson->id]['testProgress']['score']['overall']['percentBps']);
        $this->assertTrue($finalProgress[$lesson->id]['testPassed']);
        $this->assertDoesNotMatchRegularExpression(
            '/answer[_-]?key|rubric|reviewer|audit|responseSnapshot|rubricSnapshot/i',
            (string) $finalized->getContent(),
        );
        $events = $attempt->reviewEvents()->get()->toJson();
        $this->assertStringNotContainsString('student-contract-review', $events);
        $this->assertStringNotContainsString('expectedAnswer', $events);
    }

    public function test_progress_loads_only_the_latest_failed_attempt_per_assessment(): void
    {
        $user = User::factory()->create();
        [, , $lesson, $assessment, $version] = $this->createAssessmentChain();
        $oldAttempt = $this->createAttempt($user, $assessment, $version);
        $oldAttempt->forceFill([
            'status' => LessonAssessmentAttempt::STATUS_FAILED,
            'check_count' => 1,
            'checked_at' => now()->subMinute(),
        ])->save();
        $latestAttempt = $this->createAttempt($user, $assessment, $version);
        $latestAttempt->forceFill([
            'status' => LessonAssessmentAttempt::STATUS_FAILED,
            'correct_count' => 0,
            'check_count' => 1,
            'checked_at' => now(),
            'submitted_at' => now(),
            'automatic_score' => [
                'passed' => false,
                'overall' => ['percentBps' => 0],
                'sections' => [],
                'correctCount' => 0,
            ],
        ])->save();
        LessonAssessmentAttemptResponse::query()->forceCreate([
            'lesson_assessment_attempt_id' => $latestAttempt->id,
            'question_id' => 'question-01',
            'response' => ['selectedOptionId' => 'o1'],
            'is_complete' => true,
        ]);
        LessonAssessmentAttemptResponse::query()->forceCreate([
            'lesson_assessment_attempt_id' => $latestAttempt->id,
            'question_id' => 'diagram-01',
            'response' => ['selections' => ['name' => 'o1', 'p01' => 'o1']],
            'is_complete' => true,
        ]);

        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            $queries[] = $query;
        });

        $progress = app(LessonAssessmentProgressService::class)->forLessons($user->id, [$lesson->id]);

        $this->assertNull($progress[$lesson->id]['testProgress']['score']);
        $this->assertSame(0, $progress[$lesson->id]['testProgress']['automaticGate']['overall']['percentBps']);
        $responseQuery = collect($queries)->first(
            fn (QueryExecuted $query): bool => str_contains($query->sql, 'lesson_assessment_attempt_responses'),
        );
        $this->assertInstanceOf(QueryExecuted::class, $responseQuery);
        $attemptBindings = array_map('intval', $responseQuery->bindings);
        $this->assertContains($latestAttempt->id, $attemptBindings);
        $this->assertNotContains($oldAttempt->id, $attemptBindings);
    }

    public function test_development_reset_removes_only_the_current_synthetic_profiles_assessment_state(): void
    {
        $user = User::factory()->create(['email' => 'all@test.test']);
        $otherUser = User::factory()->create();
        [$subject, $course, $lesson, $assessment, $version] = $this->createAssessmentChain();
        $reviewer = User::factory()->create(['role' => 'admin_full']);
        $finalizedAttempt = $this->createSubmittedAttempt($user, $assessment, $version);
        $review = $finalizedAttempt->manualReviews()->sole();
        $updated = app(LessonAssessmentManualReviewService::class)->update(
            $reviewer,
            $finalizedAttempt->id,
            $review->id,
            0,
            10000,
            'Local reset fixture',
            'local-reset-review',
        );
        app(LessonAssessmentManualReviewService::class)->finalize(
            $reviewer,
            $finalizedAttempt->id,
            $updated['attempt']['reviewRevision'],
            'local-reset-finalize',
        );
        $decidedAttempt = $this->createSubmittedAttempt($user, $assessment, $version);
        $decidedReview = $decidedAttempt->manualReviews()->sole();
        app(LessonAssessmentManualReviewService::class)->update(
            $reviewer,
            $decidedAttempt->id,
            $decidedReview->id,
            0,
            9000,
            'Decided before local reset',
            'local-reset-decided-review',
        );
        $pendingAttempt = $this->createSubmittedAttempt($user, $assessment, $version);
        $otherAttempt = $this->createAttempt($otherUser, $assessment, $version);
        [, , , $otherAssessment, $otherVersion] = $this->createAssessmentChain();
        $usersOtherAttempt = $this->createAttempt($user, $otherAssessment, $otherVersion);
        $this->grant($user, $lesson);
        Sanctum::actingAs($user);

        $this->deleteJson($this->assessmentUrl($subject, $course, $lesson).'/development-state')
            ->assertOk()
            ->assertExactJson(['data' => ['status' => 'reset']])
            ->assertHeader('Cache-Control', 'no-store, private');

        $this->assertDatabaseMissing('lesson_assessment_attempts', ['id' => $finalizedAttempt->id]);
        $this->assertDatabaseMissing('lesson_assessment_attempts', ['id' => $decidedAttempt->id]);
        $this->assertDatabaseMissing('lesson_assessment_attempts', ['id' => $pendingAttempt->id]);
        $this->assertDatabaseMissing('lesson_assessment_attempt_responses', [
            'lesson_assessment_attempt_id' => $finalizedAttempt->id,
        ]);
        $this->assertDatabaseMissing('lesson_assessment_manual_reviews', [
            'lesson_assessment_attempt_id' => $finalizedAttempt->id,
        ]);
        $this->assertDatabaseMissing('lesson_assessment_review_events', [
            'lesson_assessment_attempt_id' => $finalizedAttempt->id,
        ]);
        $this->assertDatabaseMissing('lesson_assessment_completions', [
            'user_id' => $user->id,
            'lesson_assessment_id' => $assessment->id,
        ]);
        $this->assertDatabaseHas('lesson_assessment_attempts', ['id' => $otherAttempt->id]);
        $this->assertDatabaseHas('lesson_assessment_attempts', ['id' => $usersOtherAttempt->id]);
        $this->getJson($this->assessmentUrl($subject, $course, $lesson))
            ->assertOk()
            ->assertJsonPath('data.attempt', null);
    }

    public function test_development_reset_requires_the_exact_local_database_allowlist(): void
    {
        $service = app(LessonAssessmentAttemptService::class);
        $user = new User(['email' => 'all@test.test']);
        $originalEnvironment = app()->environment();
        $originalConnection = config('database.default');

        try {
            $this->app->instance('env', 'local');
            config()->set('database.default', 'pgsql');
            config()->set('database.connections.pgsql.url', null);
            config()->set('database.connections.pgsql.host', '127.0.0.1');
            config()->set('database.connections.pgsql.database', 'not_cms');
            $this->assertFalse($service->developmentToolsAllowed($user));

            config()->set('database.connections.pgsql.database', 'cms');
            $this->assertTrue($service->developmentToolsAllowed($user));

            $this->app->instance('env', 'production');
            $this->assertFalse($service->developmentToolsAllowed($user));
        } finally {
            $this->app->instance('env', $originalEnvironment);
            config()->set('database.default', $originalConnection);
        }
    }

    public function test_development_calibration_key_returns_only_the_first_canonical_term_for_the_requested_diagram(): void
    {
        $user = User::factory()->create(['email' => 'all@test.test']);
        [$subject, $course, $lesson, , $version] = $this->createAssessmentChain();
        $version->forceFill([
            'questions' => [
                [
                    'id' => 'diagram-01',
                    'sectionId' => 'diagrams',
                    'type' => 'diagram_text',
                    'gradingMode' => 'automatic',
                    'prompt' => 'Подпишите схему.',
                    'parts' => [
                        ['id' => 'name', 'prompt' => 'Название изображения'],
                        ['id' => 'p01', 'prompt' => 'Стрелка 1'],
                    ],
                    'assetKey' => 'diagram-01',
                    'callouts' => [['partId' => 'p01', 'x' => 0.5, 'y' => 0.4]],
                ],
                [
                    'id' => 'diagram-02',
                    'sectionId' => 'diagrams',
                    'type' => 'diagram_text',
                    'gradingMode' => 'automatic',
                    'prompt' => 'Другая схема.',
                    'parts' => [['id' => 'p01', 'prompt' => 'Стрелка 1']],
                    'assetKey' => 'diagram-01',
                    'callouts' => [['partId' => 'p01', 'x' => 0.5, 'y' => 0.4]],
                ],
            ],
            'answer_key' => [
                'diagram-01' => ['response' => ['acceptedTexts' => [
                    'name' => ['строение сустава'],
                    'p01' => ['кость', 'костная ткань'],
                ]]],
                'diagram-02' => ['response' => ['acceptedTexts' => [
                    'p01' => ['закрытый термин другой схемы'],
                ]]],
            ],
        ])->save();
        $this->grant($user, $lesson);
        Sanctum::actingAs($user);

        $response = $this->getJson(
            $this->assessmentUrl($subject, $course, $lesson).'/development-calibration-key?questionId=diagram-01',
        )->assertOk()
            ->assertExactJson(['data' => [
                'questionId' => 'diagram-01',
                'terms' => [
                    'name' => 'строение сустава',
                    'p01' => 'кость',
                ],
            ]])
            ->assertHeader('Cache-Control', 'no-store, private');

        $payload = (string) $response->getContent();
        $this->assertStringNotContainsString('костная ткань', $payload);
        $this->assertStringNotContainsString('закрытый термин другой схемы', $payload);
    }

    public function test_development_reset_is_forbidden_for_non_synthetic_profiles_and_preserves_state(): void
    {
        $user = User::factory()->create();
        [$subject, $course, $lesson, $assessment, $version] = $this->createAssessmentChain();
        $attempt = $this->createAttempt($user, $assessment, $version);
        $this->grant($user, $lesson);
        Sanctum::actingAs($user);

        $this->deleteJson($this->assessmentUrl($subject, $course, $lesson).'/development-state')
            ->assertForbidden()
            ->assertExactJson($this->accessDenied());

        $this->getJson(
            $this->assessmentUrl($subject, $course, $lesson).'/development-calibration-key?questionId=diagram-01',
        )->assertForbidden()
            ->assertExactJson($this->accessDenied());

        $this->assertDatabaseHas('lesson_assessment_attempts', ['id' => $attempt->id]);
    }

    /** @return array{Subject, Course, Lesson, LessonAssessment, LessonAssessmentVersion} */
    private function createAssessmentChain(): array
    {
        [$subject, $course, $lesson] = $this->createCatalogChain(
            'anatomiya-'.uniqid(),
            'artrologiya-'.uniqid(),
            'lesson-'.uniqid(),
        );
        $lesson->forceFill(['title' => 'Соединения костей туловища и черепа'])->save();
        $assessment = LessonAssessment::query()->create(['lesson_id' => $lesson->id]);
        $version = $this->createVersion(
            $assessment,
            1,
            LessonAssessmentVersion::STATUS_PUBLISHED,
            'Version one',
            [
                ['id' => 'fundamentals', 'title' => 'Version one fundamentals'],
                ['id' => 'diagrams', 'title' => 'Version one diagrams'],
            ],
        );

        return [$subject, $course, $lesson, $assessment, $version];
    }

    /** @return array{Subject, Course, Lesson} */
    private function createCatalogChain(string $subjectSlug, string $courseSlug, string $lessonSlug): array
    {
        $subject = Subject::query()->create([
            'slug' => $subjectSlug,
            'title' => 'Subject',
            'sort_order' => 1,
            'is_active' => true,
        ]);
        $course = Course::query()->create([
            'subject_id' => $subject->id,
            'slug' => $courseSlug,
            'title' => 'Course',
            'price_cents' => 1000,
            'sort_order' => 1,
            'is_active' => true,
        ]);
        $lesson = Lesson::query()->create([
            'course_id' => $course->id,
            'slug' => $lessonSlug,
            'title' => 'Lesson',
            'sort_order' => 1,
            'is_active' => true,
            'is_free_preview' => false,
        ]);

        return [$subject, $course, $lesson];
    }

    private function createVersion(
        LessonAssessment $assessment,
        int $contentVersion,
        string $status,
        string $prompt,
        array $sections,
    ): LessonAssessmentVersion {
        $version = LessonAssessmentVersion::query()->forceCreate([
            'lesson_assessment_id' => $assessment->id,
            'status' => $status,
            'schema_version' => 2,
            'content_version' => $contentVersion,
            'scoring_policy' => LessonAssessmentSeeder::validatedFixture()['scoringPolicy'],
            'source_assignment_sha256' => str_repeat((string) $contentVersion, 64),
            'source_key_sha256' => str_repeat((string) ($contentVersion + 1), 64),
            'sections' => $sections,
            'questions' => [
                [
                    'id' => 'question-01',
                    'sectionId' => 'fundamentals',
                    'type' => 'single_choice',
                    'gradingMode' => 'automatic',
                    'prompt' => $prompt,
                    'source' => ['page' => 1, 'item' => '1'],
                    'options' => [
                        ['id' => 'o1', 'label' => 'Option 1', 'isCorrect' => true],
                        ['id' => 'o2', 'label' => 'Option 2'],
                    ],
                    'distractorOptionIds' => ['o2'],
                ],
                [
                    'id' => 'diagram-01',
                    'sectionId' => 'diagrams',
                    'type' => 'diagram_labels',
                    'gradingMode' => 'manual',
                    'prompt' => 'Diagram',
                    'source' => ['page' => 2, 'item' => '1'],
                    'assetKey' => 'diagram-01',
                    'options' => [
                        ['id' => 'o1', 'label' => 'Option 1'],
                        ['id' => 'o2', 'label' => 'Option 2'],
                    ],
                    'parts' => [
                        ['id' => 'name', 'prompt' => 'Name', 'optionIds' => ['o1', 'o2']],
                        ['id' => 'p01', 'prompt' => 'Part', 'optionIds' => ['o1', 'o2']],
                    ],
                    'callouts' => [[
                        'targetId' => 'p01-1',
                        'partId' => 'p01',
                        'x' => 0.5,
                        'y' => 0.4,
                        'labelX' => 0.7,
                        'labelY' => 0.4,
                        'labelSide' => 'top',
                        'labelWidth' => 0.24,
                        'leaderStyle' => 'straight',
                        'targetMarker' => 'arrow',
                    ]],
                    'distractorOptionIds' => ['o2'],
                ],
            ],
            'answer_key' => [
                'question-01' => ['response' => ['selectedOptionId' => 'o1']],
                'diagram-01' => ['response' => ['selections' => ['name' => 'o1', 'p01' => 'o2']]],
            ],
            'published_at' => $status === LessonAssessmentVersion::STATUS_PUBLISHED ? now() : null,
        ]);
        $version->assets()->create([
            'asset_key' => 'diagram-01',
            'disk' => 'local',
            'object_key' => 'private/diagram-01.png',
            'sha256' => str_repeat('a', 64),
            'mime_type' => 'image/png',
            'width' => 640,
            'height' => 480,
        ]);

        return $version;
    }

    private function createAttempt(
        User $user,
        LessonAssessment $assessment,
        LessonAssessmentVersion $version,
    ): LessonAssessmentAttempt {
        return LessonAssessmentAttempt::query()->forceCreate([
            'user_id' => $user->id,
            'lesson_assessment_id' => $assessment->id,
            'lesson_assessment_version_id' => $version->id,
            'status' => LessonAssessmentAttempt::STATUS_IN_PROGRESS,
            'correct_count' => 0,
            'revision' => 0,
            'check_count' => 0,
        ]);
    }

    private function createSubmittedAttempt(
        User $user,
        LessonAssessment $assessment,
        LessonAssessmentVersion $version,
    ): LessonAssessmentAttempt {
        $attempt = $this->createAttempt($user, $assessment, $version);
        LessonAssessmentAttemptResponse::query()->forceCreate([
            'lesson_assessment_attempt_id' => $attempt->id,
            'question_id' => 'question-01',
            'response' => ['selectedOptionId' => 'o1'],
            'is_complete' => true,
        ]);
        LessonAssessmentAttemptResponse::query()->forceCreate([
            'lesson_assessment_attempt_id' => $attempt->id,
            'question_id' => 'diagram-01',
            'response' => ['selections' => ['name' => 'o1', 'p01' => 'o2']],
            'is_complete' => true,
        ]);

        return app(LessonAssessmentAttemptService::class)->submit($user, $attempt, 0)['attempt'];
    }

    private function grant(User $user, Lesson $lesson): void
    {
        UserEntitlementGrant::query()->create([
            'user_id' => $user->id,
            'atom_kind' => UserEntitlementGrant::ATOM_LESSON,
            'atom_id' => $lesson->id,
            'status' => UserEntitlementGrant::STATUS_ACTIVE,
            'source_type' => UserEntitlementGrant::SOURCE_TEST_FIXTURE,
            'source_id' => "assessment-{$lesson->id}",
            'idempotency_key' => "assessment-{$user->id}-{$lesson->id}",
            'granted_at' => now(),
        ]);
    }

    private function assessmentUrl(Subject $subject, Course $course, Lesson $lesson): string
    {
        return "/api/v1/learning/subjects/{$subject->slug}/courses/{$course->slug}/lessons/{$lesson->slug}/assessment";
    }

    private function lessonNotFound(): array
    {
        return ['error' => ['code' => 'LEARNING_LESSON_NOT_FOUND', 'message' => 'Урок не найден.']];
    }

    private function accessDenied(): array
    {
        return ['error' => ['code' => 'LEARNING_ACCESS_DENIED', 'message' => 'У пользователя нет доступа к этому уроку.']];
    }

    private function domainError(string $code): array
    {
        return ['error' => ['code' => $code, 'message' => match ($code) {
            'LEARNING_ASSESSMENT_ATTEMPT_NOT_FOUND' => 'Попытка сдачи экзамена не найдена.',
            'LEARNING_ASSESSMENT_ATTEMPT_CONFLICT' => 'Состояние попытки изменилось. Обновите данные и повторите действие.',
            'LEARNING_ASSESSMENT_LOCKED_RESPONSE' => 'Уже зачтённый ответ нельзя изменить.',
            'LEARNING_ASSESSMENT_INCOMPLETE' => 'Заполните все доступные задания перед проверкой.',
            'LEARNING_ASSESSMENT_RESPONSE_INVALID' => 'Ответ на задание имеет неверный формат.',
            'LEARNING_ASSESSMENT_SUGGESTION_FORBIDDEN' => 'Подсказки недоступны для заданий с ручной проверкой.',
            default => 'Экзамен не найден.',
        }]];
    }
}
