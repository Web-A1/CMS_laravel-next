<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\LearningController;
use App\Models\Course;
use App\Models\Lesson;
use App\Models\LessonAssessment;
use App\Models\LessonAssessmentAttempt;
use App\Models\LessonAssessmentAttemptResponse;
use App\Models\LessonAssessmentCompletion;
use App\Models\LessonAssessmentVersion;
use App\Models\LessonLearningContent;
use App\Models\Subject;
use App\Models\User;
use App\Models\UserEntitlementGrant;
use Database\Seeders\LessonAssessmentSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class LearningHierarchyApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_learning_payload_exposes_bulk_assessment_progress_and_completion_counts_without_changing_access_counts(): void
    {
        $user = $this->createUser();
        $subject = $this->createSubject('assessment-progress', 'Прогресс теста');
        $course = $this->createCourse($subject, 'assessment-progress', 'Курс с тестами');
        $noAssessment = $this->createLesson($course, 'no-assessment', 'Без теста', sortOrder: 10);
        $notStarted = $this->createLesson($course, 'not-started', 'Не начат', sortOrder: 20);
        $inProgress = $this->createLesson($course, 'in-progress', 'В процессе', sortOrder: 30);
        $failed = $this->createLesson($course, 'failed', 'Не пройден', sortOrder: 40);
        $passed = $this->createLesson($course, 'passed', 'Пройден', sortOrder: 50);

        $this->createAssessmentVersion($notStarted, 1, LessonAssessmentVersion::STATUS_PUBLISHED, 2);
        $activeVersion = $this->createAssessmentVersion($inProgress, 1, LessonAssessmentVersion::STATUS_RETIRED, 3);
        $this->createAssessmentVersion($inProgress, 2, LessonAssessmentVersion::STATUS_PUBLISHED, 2);
        $failedVersion = $this->createAssessmentVersion($failed, 1, LessonAssessmentVersion::STATUS_PUBLISHED, 2);
        $passedVersion = $this->createAssessmentVersion($passed, 1, LessonAssessmentVersion::STATUS_RETIRED, 5);
        $passedPublishedVersion = $this->createAssessmentVersion($passed, 2, LessonAssessmentVersion::STATUS_PUBLISHED, 2);

        $activeAttempt = $this->createAssessmentAttempt($user, $activeVersion, correctCount: 1);
        LessonAssessmentAttemptResponse::query()->forceCreate([
            'lesson_assessment_attempt_id' => $activeAttempt->id,
            'question_id' => 'q-1',
            'response' => ['saved' => true],
            'is_complete' => true,
        ]);
        LessonAssessmentAttemptResponse::query()->forceCreate([
            'lesson_assessment_attempt_id' => $activeAttempt->id,
            'question_id' => 'q-2',
            'response' => ['saved' => false],
            'is_complete' => false,
        ]);

        $failedAttempt = $this->createAssessmentAttempt(
            $user,
            $failedVersion,
            status: LessonAssessmentAttempt::STATUS_FAILED,
            correctCount: 0,
        );
        foreach ([1 => 'o2', 2 => 'o2'] as $index => $selectedOptionId) {
            LessonAssessmentAttemptResponse::query()->forceCreate([
                'lesson_assessment_attempt_id' => $failedAttempt->id,
                'question_id' => "q-{$index}",
                'response' => ['selectedOptionId' => $selectedOptionId],
                'is_complete' => true,
            ]);
        }

        $passedAttempt = $this->createAssessmentAttempt(
            $user,
            $passedVersion,
            status: LessonAssessmentAttempt::STATUS_PASSED,
            correctCount: 4,
        );
        for ($index = 1; $index <= 5; $index++) {
            LessonAssessmentAttemptResponse::query()->forceCreate([
                'lesson_assessment_attempt_id' => $passedAttempt->id,
                'question_id' => "q-{$index}",
                'response' => ['selectedOptionId' => $index < 5 ? 'o1' : 'o2'],
                'is_complete' => true,
            ]);
        }
        $completedAt = now()->subMinute()->startOfSecond();
        LessonAssessmentCompletion::query()->forceCreate([
            'user_id' => $user->id,
            'lesson_id' => $passed->id,
            'lesson_assessment_id' => $passedVersion->lesson_assessment_id,
            'passed_attempt_id' => $passedAttempt->id,
            'completed_at' => $completedAt,
        ]);
        $this->createAssessmentAttempt($user, $passedPublishedVersion);

        foreach ([$noAssessment, $notStarted, $inProgress, $failed, $passed] as $lesson) {
            $this->createGrant($user, $lesson);
        }
        Sanctum::actingAs($user);

        $courseResponse = $this->getJson($this->courseUrl($subject, $course))
            ->assertOk()
            ->assertJsonPath('data.access', 'full')
            ->assertJsonPath('data.requiredLessonsCount', 5)
            ->assertJsonPath('data.ownedLessonsCount', 5)
            ->assertJsonPath('data.completedLessonsCount', 1);

        $this->assertSame([
            [
                'testAvailable' => false,
                'testPassed' => false,
                'testProgress' => null,
            ],
            [
                'testAvailable' => true,
                'testPassed' => false,
                'testProgress' => [
                    'status' => 'not_started',
                    'answeredCount' => 0,
                    'correctCount' => 0,
                    'totalCount' => 2,
                    'score' => null,
                    'mastered' => false,
                    'completedAt' => null,
                    'retakeAvailable' => true,
                    'retakeInProgress' => false,
                ],
            ],
            [
                'testAvailable' => true,
                'testPassed' => false,
                'testProgress' => [
                    'status' => 'in_progress',
                    'answeredCount' => 1,
                    'correctCount' => 1,
                    'totalCount' => 3,
                    'score' => null,
                    'mastered' => false,
                    'completedAt' => null,
                    'retakeAvailable' => false,
                    'retakeInProgress' => false,
                ],
            ],
            [
                'testAvailable' => true,
                'testPassed' => false,
                'testProgress' => [
                    'status' => 'failed',
                    'answeredCount' => 2,
                    'correctCount' => 0,
                    'totalCount' => 2,
                    'score' => null,
                    'mastered' => false,
                    'automaticGate' => [
                        'passed' => false,
                        'overall' => [
                            'numerator' => 0,
                            'denominator' => 1,
                            'percentBps' => 0,
                            'requiredBps' => 8000,
                            'passed' => false,
                        ],
                        'sections' => [[
                            'sectionId' => 'main',
                            'numerator' => 0,
                            'denominator' => 1,
                            'percentBps' => 0,
                            'requiredBps' => 6000,
                            'passed' => false,
                        ]],
                        'correctCount' => 0,
                    ],
                    'completedAt' => null,
                    'retakeAvailable' => true,
                    'retakeInProgress' => false,
                ],
            ],
            [
                'testAvailable' => true,
                'testPassed' => true,
                'testProgress' => [
                    'status' => 'passed',
                    'answeredCount' => 5,
                    'correctCount' => 4,
                    'totalCount' => 5,
                    'score' => [
                        'checked' => true,
                        'passed' => true,
                        'mastered' => false,
                        'overall' => [
                            'numerator' => 9400,
                            'denominator' => 10000,
                            'percentBps' => 9400,
                            'requiredBps' => 8000,
                            'passed' => true,
                        ],
                        'components' => [
                            'automaticBps' => 10000,
                            'manualBps' => 8000,
                            'automaticWeightBps' => 7000,
                            'manualWeightBps' => 3000,
                        ],
                    ],
                    'mastered' => false,
                    'automaticGate' => [
                        'passed' => true,
                        'overall' => [
                            'numerator' => 1,
                            'denominator' => 1,
                            'percentBps' => 10000,
                            'requiredBps' => 8000,
                            'passed' => true,
                        ],
                        'sections' => [[
                            'sectionId' => 'main',
                            'numerator' => 1,
                            'denominator' => 1,
                            'percentBps' => 10000,
                            'requiredBps' => 6000,
                            'passed' => true,
                        ]],
                        'correctCount' => 4,
                    ],
                    'completedAt' => $completedAt->toJSON(),
                    'retakeAvailable' => true,
                    'retakeInProgress' => true,
                ],
            ],
        ], array_map(
            fn (array $lesson): array => array_intersect_key($lesson, array_flip([
                'testAvailable',
                'testPassed',
                'testProgress',
            ])),
            $courseResponse->json('data.lessons'),
        ));
        $this->assertDoesNotMatchRegularExpression(
            '/answer[_-]?key|"response"|"questions"/i',
            (string) $courseResponse->getContent(),
        );

        $this->getJson($this->subjectUrl($subject))
            ->assertOk()
            ->assertJsonPath('data.access', 'full')
            ->assertJsonPath('data.requiredLessonsCount', 5)
            ->assertJsonPath('data.ownedLessonsCount', 5)
            ->assertJsonPath('data.courses.0.completedLessonsCount', 1);

        $this->getJson($this->lessonUrl($subject, $course, $passed))
            ->assertOk()
            ->assertJsonPath('data.testAvailable', true)
            ->assertJsonPath('data.testPassed', true)
            ->assertJsonPath('data.testProgress.status', 'passed')
            ->assertJsonPath('data.testProgress.totalCount', 5)
            ->assertJsonPath('data.testProgress.correctCount', 4)
            ->assertJsonPath('data.testProgress.score.overall.percentBps', 9400)
            ->assertJsonPath('data.testProgress.mastered', false)
            ->assertJsonPath('data.testProgress.completedAt', $completedAt->toJSON())
            ->assertJsonPath('data.testProgress.retakeAvailable', true)
            ->assertJsonPath('data.testProgress.retakeInProgress', true);
    }

    public function test_course_assessment_progress_query_count_is_constant_for_larger_lesson_collections(): void
    {
        $user = $this->createUser();
        $smallSubject = $this->createSubject('progress-small', 'Малый');
        $smallCourse = $this->createCourse($smallSubject, 'progress-small', 'Малый курс');
        $smallLesson = $this->createLesson($smallCourse, 'small-1', 'Малый урок');
        $this->createAssessmentVersion($smallLesson, 1, LessonAssessmentVersion::STATUS_PUBLISHED, 2);
        $largeSubject = $this->createSubject('progress-large', 'Большой');
        $largeCourse = $this->createCourse($largeSubject, 'progress-large', 'Большой курс');
        for ($index = 1; $index <= 8; $index++) {
            $lesson = $this->createLesson($largeCourse, "large-{$index}", "Большой урок {$index}", sortOrder: $index);
            $this->createAssessmentVersion($lesson, 1, LessonAssessmentVersion::STATUS_PUBLISHED, 2);
        }
        Sanctum::actingAs($user);

        $recording = false;
        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$queries, &$recording): void {
            if ($recording) {
                $queries[] = $query->sql;
            }
        });

        $recording = true;
        $this->getJson($this->courseUrl($smallSubject, $smallCourse))->assertOk();
        $recording = false;
        $smallQueryCount = count($queries);
        // The migration hold middleware adds one fixed account-state read to either collection size.
        $this->assertSame(11, $smallQueryCount);
        $queries = [];

        $recording = true;
        $this->getJson($this->courseUrl($largeSubject, $largeCourse))->assertOk();
        $recording = false;

        $this->assertSame($smallQueryCount, count($queries));
    }

    public function test_canonical_routes_use_scoped_slug_bindings_without_replacing_existing_routes(): void
    {
        $subjectRoute = Route::getRoutes()->getByAction(LearningController::class.'@showSubject');
        $courseRoute = Route::getRoutes()->getByAction(LearningController::class.'@showCourse');
        $lessonRoute = Route::getRoutes()->getByAction(LearningController::class.'@showLesson');

        $this->assertNotNull($subjectRoute);
        $this->assertNotNull($courseRoute);
        $this->assertNotNull($lessonRoute);
        $this->assertSame('api/v1/learning/subjects/{subject}', $subjectRoute->uri());
        $this->assertSame(['subject' => 'slug'], $subjectRoute->bindingFields());
        $this->assertTrue($subjectRoute->enforcesScopedBindings());
        $this->assertSame(
            'api/v1/learning/subjects/{subject}/courses/{course}',
            $courseRoute->uri(),
        );
        $this->assertSame(['subject' => 'slug', 'course' => 'slug'], $courseRoute->bindingFields());
        $this->assertTrue($courseRoute->enforcesScopedBindings());
        $this->assertSame(
            'api/v1/learning/subjects/{subject}/courses/{course}/lessons/{lesson}',
            $lessonRoute->uri(),
        );
        $this->assertSame(
            ['subject' => 'slug', 'course' => 'slug', 'lesson' => 'slug'],
            $lessonRoute->bindingFields(),
        );
        $this->assertTrue($lessonRoute->enforcesScopedBindings());
        $this->assertSame(
            'api/v1/learning',
            Route::getRoutes()->getByAction(LearningController::class.'@index')?->uri(),
        );
        $this->assertSame(
            $lessonRoute->uri(),
            Route::getRoutes()->getByAction(LearningController::class.'@showLesson')?->uri(),
        );
    }

    public function test_learning_index_returns_all_active_non_reserved_subjects_in_bought_first_curriculum_order(): void
    {
        $user = $this->createUser();
        $boughtSubject = $this->createSubject('bought', 'Купленный', sortOrder: 30);
        $boughtCourse = $this->createCourse($boughtSubject, 'unsellable', 'Непродаваемый курс');
        $boughtLesson = $this->createLesson($boughtCourse, 'owned', 'Купленный урок');
        $firstSubject = $this->createSubject('first', 'Первый', sortOrder: 5);
        $firstCourse = $this->createCourse($firstSubject, 'closed', 'Закрытый курс');
        $this->createLesson($firstCourse, 'closed', 'Закрытый урок');
        $alphaSubject = $this->createSubject('alpha', 'Альфа', sortOrder: 10);
        $betaSubject = $this->createSubject('beta', 'Бета', sortOrder: 10);
        $reservedSubject = $this->createSubject('lessons', 'Служебный', sortOrder: 1);
        $inactiveSubject = $this->createSubject('inactive', 'Неактивный', sortOrder: 1, active: false);
        $this->createGrant($user, $boughtLesson);
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/learning')
            ->assertOk()
            ->assertExactJson([
                'data' => [
                    'subjects' => [
                        [
                            'id' => $boughtSubject->id,
                            'slug' => 'bought',
                            'title' => 'Купленный',
                            'sortOrder' => 30,
                            'access' => 'full',
                            'requiredLessonsCount' => 1,
                            'ownedLessonsCount' => 1,
                            'sellableCoursesCount' => 0,
                            'boughtCoursesCount' => 1,
                            'freeLessonAvailable' => false,
                            'canonicalPath' => '/lk/learning/bought',
                        ],
                        [
                            'id' => $firstSubject->id,
                            'slug' => 'first',
                            'title' => 'Первый',
                            'sortOrder' => 5,
                            'access' => 'none',
                            'requiredLessonsCount' => 1,
                            'ownedLessonsCount' => 0,
                            'sellableCoursesCount' => 0,
                            'boughtCoursesCount' => 0,
                            'freeLessonAvailable' => false,
                            'canonicalPath' => '/lk/learning/first',
                        ],
                        [
                            'id' => $alphaSubject->id,
                            'slug' => 'alpha',
                            'title' => 'Альфа',
                            'sortOrder' => 10,
                            'access' => 'none',
                            'requiredLessonsCount' => 0,
                            'ownedLessonsCount' => 0,
                            'sellableCoursesCount' => 0,
                            'boughtCoursesCount' => 0,
                            'freeLessonAvailable' => false,
                            'canonicalPath' => '/lk/learning/alpha',
                        ],
                        [
                            'id' => $betaSubject->id,
                            'slug' => 'beta',
                            'title' => 'Бета',
                            'sortOrder' => 10,
                            'access' => 'none',
                            'requiredLessonsCount' => 0,
                            'ownedLessonsCount' => 0,
                            'sellableCoursesCount' => 0,
                            'boughtCoursesCount' => 0,
                            'freeLessonAvailable' => false,
                            'canonicalPath' => '/lk/learning/beta',
                        ],
                    ],
                    'empty' => false,
                ],
            ]);

        $this->assertNoProtectedKeys($response);
        $this->assertTrue($reservedSubject->exists);
        $this->assertTrue($inactiveSubject->exists);
    }

    public function test_subject_summary_returns_all_active_courses_in_curriculum_order(): void
    {
        $user = $this->createUser();
        $subject = $this->createSubject('anatomy', 'Анатомия', sortOrder: 5);
        $laterCourse = $this->createCourse(
            $subject,
            'osteology',
            'Остеология',
            shortTitle: 'Кости',
            sortOrder: 20,
        );
        $firstCourse = $this->createCourse(
            $subject,
            'cardiology',
            'Кардиология',
            sortOrder: 10,
        );
        $closedCourse = $this->createCourse(
            $subject,
            'neurology',
            'Неврология',
            sortOrder: 30,
        );
        $laterOwnedLesson = $this->createLesson($laterCourse, 'bones', 'Кости', sortOrder: 20);
        $this->createLesson($laterCourse, 'joints', 'Суставы', sortOrder: 10);
        $firstOwnedLesson = $this->createLesson($firstCourse, 'heart', 'Сердце', sortOrder: 10);
        $closedLesson = $this->createLesson($closedCourse, 'brain', 'Головной мозг', sortOrder: 10);
        $this->createPublishedContent($laterOwnedLesson);
        $this->createPublishedContent($closedLesson);
        $this->createGrant($user, $laterOwnedLesson);
        $this->createGrant($user, $firstOwnedLesson);
        Sanctum::actingAs($user);

        $response = $this->getJson($this->subjectUrl($subject))
            ->assertOk()
            ->assertExactJson([
                'data' => [
                    'subject' => [
                        'id' => $subject->id,
                        'slug' => 'anatomy',
                        'title' => 'Анатомия',
                        'canonicalPath' => '/lk/learning/anatomy',
                    ],
                    'access' => 'partial',
                    'requiredLessonsCount' => 4,
                    'ownedLessonsCount' => 2,
                    'courses' => [
                        [
                            'id' => $firstCourse->id,
                            'itemId' => 'course:anatomy:cardiology',
                            'slug' => 'cardiology',
                            'title' => 'Кардиология',
                            'shortTitle' => null,
                            'sortOrder' => 10,
                            'canonicalPath' => '/lk/learning/anatomy/cardiology',
                            'publicPath' => '/subjects/anatomy/cardiology',
                            'access' => 'full',
                            'requiredLessonsCount' => 1,
                            'ownedLessonsCount' => 1,
                            'completedLessonsCount' => 0,
                            'freeLessonAvailable' => false,
                        ],
                        [
                            'id' => $laterCourse->id,
                            'itemId' => 'course:anatomy:osteology',
                            'slug' => 'osteology',
                            'title' => 'Остеология',
                            'shortTitle' => 'Кости',
                            'sortOrder' => 20,
                            'canonicalPath' => '/lk/learning/anatomy/osteology',
                            'publicPath' => '/subjects/anatomy/osteology',
                            'access' => 'partial',
                            'requiredLessonsCount' => 2,
                            'ownedLessonsCount' => 1,
                            'completedLessonsCount' => 0,
                            'freeLessonAvailable' => false,
                        ],
                        [
                            'id' => $closedCourse->id,
                            'itemId' => 'course:anatomy:neurology',
                            'slug' => 'neurology',
                            'title' => 'Неврология',
                            'shortTitle' => null,
                            'sortOrder' => 30,
                            'canonicalPath' => '/lk/learning/anatomy/neurology',
                            'publicPath' => '/subjects/anatomy/neurology',
                            'access' => 'none',
                            'requiredLessonsCount' => 1,
                            'ownedLessonsCount' => 0,
                            'completedLessonsCount' => 0,
                            'freeLessonAvailable' => false,
                        ],
                    ],
                    'empty' => false,
                ],
            ]);

        $this->assertNoProtectedKeys($response);
    }

    public function test_subject_summary_returns_bought_courses_first_then_other_courses_in_curriculum_order(): void
    {
        $user = $this->createUser();
        $subject = $this->createSubject('anatomy', 'Анатомия');
        $partialEarlyCourse = $this->createCourse($subject, 'partial-early', 'Частично открытый ранний курс', sortOrder: 5);
        $lockedEarlyCourse = $this->createCourse($subject, 'locked-early', 'Закрытый ранний курс', sortOrder: 10);
        $boughtFirstCourse = $this->createCourse($subject, 'bought-first', 'Купленный первый курс', sortOrder: 20);
        $lockedLaterCourse = $this->createCourse($subject, 'locked-later', 'Закрытый поздний курс', sortOrder: 30);
        $boughtLaterCourse = $this->createCourse($subject, 'bought-later', 'Купленный поздний курс', sortOrder: 40);

        $partialLesson = $this->createLesson($partialEarlyCourse, 'partial-owned', 'Открытый урок');
        $this->createLesson($partialEarlyCourse, 'partial-locked', 'Закрытый урок');
        $this->createLesson($lockedEarlyCourse, 'locked-early', 'Закрытый ранний урок');
        $boughtFirstLesson = $this->createLesson($boughtFirstCourse, 'bought-first', 'Купленный первый урок');
        $this->createLesson($lockedLaterCourse, 'locked-later', 'Закрытый поздний урок');
        $boughtLaterLesson = $this->createLesson($boughtLaterCourse, 'bought-later', 'Купленный поздний урок');
        $this->createGrant($user, $partialLesson);
        $this->createGrant($user, $boughtFirstLesson);
        $this->createGrant($user, $boughtLaterLesson);
        Sanctum::actingAs($user);

        $response = $this->getJson($this->subjectUrl($subject))
            ->assertOk();

        $courses = $response->json('data.courses');

        $this->assertSame([
            'bought-first',
            'bought-later',
            'partial-early',
            'locked-early',
            'locked-later',
        ], array_column($courses, 'slug'));
        $this->assertSame([
            'full',
            'full',
            'partial',
            'none',
            'none',
        ], array_column($courses, 'access'));
    }

    public function test_subject_without_grants_returns_none_and_visible_course_navigation(): void
    {
        $user = $this->createUser();
        $subject = $this->createSubject('anatomy', 'Анатомия');
        $course = $this->createCourse($subject, 'osteology', 'Остеология');
        $this->createLesson($course, 'bones', 'Кости');
        Sanctum::actingAs($user);

        $response = $this->getJson($this->subjectUrl($subject))
            ->assertOk()
            ->assertExactJson([
                'data' => [
                    'subject' => [
                        'id' => $subject->id,
                        'slug' => 'anatomy',
                        'title' => 'Анатомия',
                        'canonicalPath' => '/lk/learning/anatomy',
                    ],
                    'access' => 'none',
                    'requiredLessonsCount' => 1,
                    'ownedLessonsCount' => 0,
                    'courses' => [[
                        'id' => $course->id,
                        'itemId' => 'course:anatomy:osteology',
                        'slug' => 'osteology',
                        'title' => 'Остеология',
                        'shortTitle' => null,
                        'sortOrder' => 0,
                        'canonicalPath' => '/lk/learning/anatomy/osteology',
                        'publicPath' => '/subjects/anatomy/osteology',
                        'access' => 'none',
                        'requiredLessonsCount' => 1,
                        'ownedLessonsCount' => 0,
                        'completedLessonsCount' => 0,
                        'freeLessonAvailable' => false,
                    ]],
                    'empty' => false,
                ],
            ]);

        $this->assertNoProtectedKeys($response);
    }

    public function test_course_outline_returns_all_active_lessons_as_navigation_only_metadata(): void
    {
        $user = $this->createUser();
        $subject = $this->createSubject('anatomy', 'Анатомия');
        $course = $this->createCourse($subject, 'osteology', 'Остеология');
        $closedLesson = $this->createLesson($course, 'closed', 'Закрытый урок', sortOrder: 5);
        $secondOwnedLesson = $this->createLesson($course, 'second', 'Второй урок', sortOrder: 20);
        $firstOwnedLesson = $this->createLesson($course, 'first', 'Первый урок', sortOrder: 10);
        $this->createPublishedContent($closedLesson);
        $this->createGrant($user, $firstOwnedLesson);
        $this->createGrant($user, $secondOwnedLesson);
        Sanctum::actingAs($user);

        $response = $this->getJson($this->courseUrl($subject, $course))
            ->assertOk()
            ->assertExactJson([
                'data' => [
                    'subject' => [
                        'id' => $subject->id,
                        'slug' => 'anatomy',
                        'title' => 'Анатомия',
                        'canonicalPath' => '/lk/learning/anatomy',
                    ],
                    'course' => [
                        'id' => $course->id,
                        'itemId' => 'course:anatomy:osteology',
                        'slug' => 'osteology',
                        'title' => 'Остеология',
                        'shortTitle' => null,
                        'sortOrder' => 0,
                        'canonicalPath' => '/lk/learning/anatomy/osteology',
                        'publicPath' => '/subjects/anatomy/osteology',
                        'freeLessonAvailable' => false,
                    ],
                    'access' => 'partial',
                    'requiredLessonsCount' => 3,
                    'ownedLessonsCount' => 2,
                    'completedLessonsCount' => 0,
                    'lessons' => [
                        [
                            'id' => $closedLesson->id,
                            'slug' => 'closed',
                            'title' => 'Закрытый урок',
                            'sortOrder' => 5,
                            'access' => 'none',
                            'availability' => 'locked',
                            'canonicalPath' => '/lk/learning/anatomy/osteology/closed',
                            ...$this->unavailableTestPayload(),
                        ],
                        [
                            'id' => $firstOwnedLesson->id,
                            'slug' => 'first',
                            'title' => 'Первый урок',
                            'sortOrder' => 10,
                            'access' => 'full',
                            'availability' => 'entitled',
                            'canonicalPath' => '/lk/learning/anatomy/osteology/first',
                            ...$this->unavailableTestPayload(),
                        ],
                        [
                            'id' => $secondOwnedLesson->id,
                            'slug' => 'second',
                            'title' => 'Второй урок',
                            'sortOrder' => 20,
                            'access' => 'full',
                            'availability' => 'entitled',
                            'canonicalPath' => '/lk/learning/anatomy/osteology/second',
                            ...$this->unavailableTestPayload(),
                        ],
                    ],
                    'empty' => false,
                ],
            ]);

        $this->assertNoProtectedKeys($response);
    }

    public function test_course_without_grants_returns_locked_lesson_navigation(): void
    {
        $user = $this->createUser();
        $subject = $this->createSubject('anatomy', 'Анатомия');
        $course = $this->createCourse($subject, 'osteology', 'Остеология', shortTitle: 'Кости');
        $lesson = $this->createLesson($course, 'bones', 'Кости');
        Sanctum::actingAs($user);

        $response = $this->getJson($this->courseUrl($subject, $course))
            ->assertOk()
            ->assertExactJson([
                'data' => [
                    'subject' => [
                        'id' => $subject->id,
                        'slug' => 'anatomy',
                        'title' => 'Анатомия',
                        'canonicalPath' => '/lk/learning/anatomy',
                    ],
                    'course' => [
                        'id' => $course->id,
                        'itemId' => 'course:anatomy:osteology',
                        'slug' => 'osteology',
                        'title' => 'Остеология',
                        'shortTitle' => 'Кости',
                        'sortOrder' => 0,
                        'canonicalPath' => '/lk/learning/anatomy/osteology',
                        'publicPath' => '/subjects/anatomy/osteology',
                        'freeLessonAvailable' => false,
                    ],
                    'access' => 'none',
                    'requiredLessonsCount' => 1,
                    'ownedLessonsCount' => 0,
                    'completedLessonsCount' => 0,
                    'lessons' => [[
                        'id' => $lesson->id,
                        'slug' => 'bones',
                        'title' => 'Кости',
                        'sortOrder' => 0,
                        'access' => 'none',
                        'availability' => 'locked',
                        'canonicalPath' => '/lk/learning/anatomy/osteology/bones',
                        ...$this->unavailableTestPayload(),
                    ]],
                    'empty' => false,
                ],
            ]);

        $this->assertNoProtectedKeys($response);
    }

    public function test_course_lesson_availability_prefers_entitlement_over_free_preview(): void
    {
        $user = $this->createUser();
        $subject = $this->createSubject('anatomy', 'Анатомия');
        $course = $this->createCourse($subject, 'osteology', 'Остеология');
        $freeLesson = $this->createLesson($course, 'free', 'Бесплатный', sortOrder: 10, freePreview: true);
        $ownedLesson = $this->createLesson($course, 'owned', 'Купленный', sortOrder: 20);
        $ownedFreeLesson = $this->createLesson($course, 'owned-free', 'Купленный бесплатный', sortOrder: 30, freePreview: true);
        $lockedLesson = $this->createLesson($course, 'locked', 'Закрытый', sortOrder: 40);
        $inactiveLesson = $this->createLesson($course, 'inactive', 'Неактивный', sortOrder: 5, active: false, freePreview: true);
        $this->createPublishedContent($lockedLesson);
        $this->createGrant($user, $ownedLesson);
        $this->createGrant($user, $ownedFreeLesson);
        Sanctum::actingAs($user);

        $response = $this->getJson($this->courseUrl($subject, $course))
            ->assertOk()
            ->assertJsonPath('data.course.freeLessonAvailable', true)
            ->assertJsonPath('data.lessons.0.id', $freeLesson->id)
            ->assertJsonPath('data.lessons.0.access', 'full')
            ->assertJsonPath('data.lessons.0.availability', 'free_preview')
            ->assertJsonPath('data.lessons.1.id', $ownedLesson->id)
            ->assertJsonPath('data.lessons.1.access', 'full')
            ->assertJsonPath('data.lessons.1.availability', 'entitled')
            ->assertJsonPath('data.lessons.2.id', $ownedFreeLesson->id)
            ->assertJsonPath('data.lessons.2.access', 'full')
            ->assertJsonPath('data.lessons.2.availability', 'entitled')
            ->assertJsonPath('data.lessons.3.id', $lockedLesson->id)
            ->assertJsonPath('data.lessons.3.access', 'none')
            ->assertJsonPath('data.lessons.3.availability', 'locked')
            ->assertJsonCount(4, 'data.lessons');

        $this->assertNoProtectedKeys($response);
        $this->assertNotContains(
            $inactiveLesson->id,
            collect($response->json('data.lessons'))->pluck('id')->all(),
        );
    }

    public function test_owned_lesson_returns_exact_metadata_only_shape(): void
    {
        $user = $this->createUser();
        $subject = $this->createSubject('anatomy', 'Анатомия');
        $course = $this->createCourse($subject, 'osteology', 'Остеология');
        $lesson = $this->createLesson($course, 'bones', 'Кости верхней конечности', sortOrder: 10);
        $this->createGrant($user, $lesson);
        Sanctum::actingAs($user);

        $this->getJson($this->lessonUrl($subject, $course, $lesson))
            ->assertOk()
            ->assertExactJson([
                'data' => [
                    'id' => $lesson->id,
                    'slug' => 'bones',
                    'title' => 'Кости верхней конечности',
                    'sortOrder' => 10,
                    'access' => 'full',
                    'canonicalPath' => '/lk/learning/anatomy/osteology/bones',
                    'subject' => [
                        'id' => $subject->id,
                        'slug' => 'anatomy',
                        'title' => 'Анатомия',
                        'canonicalPath' => '/lk/learning/anatomy',
                    ],
                    'course' => [
                        'id' => $course->id,
                        'itemId' => 'course:anatomy:osteology',
                        'slug' => 'osteology',
                        'title' => 'Остеология',
                        'shortTitle' => null,
                        'sortOrder' => 0,
                        'canonicalPath' => '/lk/learning/anatomy/osteology',
                        'publicPath' => '/subjects/anatomy/osteology',
                        'freeLessonAvailable' => false,
                    ],
                    ...$this->unavailableTestPayload(),
                    'content' => [
                        'status' => 'metadata_only',
                    ],
                ],
            ]);
    }

    public function test_owned_lesson_returns_exact_published_content_shape(): void
    {
        $user = $this->createUser();
        $subject = $this->createSubject('anatomy', 'Анатомия');
        $course = $this->createCourse($subject, 'osteology', 'Остеология');
        $lesson = $this->createLesson($course, 'bones', 'Кости', sortOrder: 10);
        $content = $this->createPublishedContent($lesson, [
            'blocks' => [
                [
                    'type' => 'heading',
                    'text' => 'Введение',
                    'level' => 2,
                    'id' => 'intro',
                ],
                [
                    'type' => 'paragraph',
                    'text' => 'Текст учебного материала.',
                ],
            ],
            'toc' => [
                [
                    'id' => 'intro',
                    'title' => 'Введение',
                ],
            ],
        ]);
        $this->createGrant($user, $lesson);
        Sanctum::actingAs($user);

        $this->getJson($this->lessonUrl($subject, $course, $lesson))
            ->assertOk()
            ->assertExactJson([
                'data' => [
                    'id' => $lesson->id,
                    'slug' => 'bones',
                    'title' => 'Кости',
                    'sortOrder' => 10,
                    'access' => 'full',
                    'canonicalPath' => '/lk/learning/anatomy/osteology/bones',
                    'subject' => [
                        'id' => $subject->id,
                        'slug' => 'anatomy',
                        'title' => 'Анатомия',
                        'canonicalPath' => '/lk/learning/anatomy',
                    ],
                    'course' => [
                        'id' => $course->id,
                        'itemId' => 'course:anatomy:osteology',
                        'slug' => 'osteology',
                        'title' => 'Остеология',
                        'shortTitle' => null,
                        'sortOrder' => 0,
                        'canonicalPath' => '/lk/learning/anatomy/osteology',
                        'publicPath' => '/subjects/anatomy/osteology',
                        'freeLessonAvailable' => false,
                    ],
                    ...$this->unavailableTestPayload(),
                    'content' => [
                        'status' => 'published',
                        'format' => 'lesson_blocks_v1',
                        'version' => 1,
                        'publishedAt' => $content->published_at?->toJSON(),
                        'body' => [
                            'blocks' => [
                                [
                                    'type' => 'heading',
                                    'text' => 'Введение',
                                    'level' => 2,
                                    'id' => 'intro',
                                ],
                                [
                                    'type' => 'paragraph',
                                    'text' => 'Текст учебного материала.',
                                ],
                            ],
                            'toc' => [
                                [
                                    'id' => 'intro',
                                    'title' => 'Введение',
                                ],
                            ],
                        ],
                    ],
                ],
            ]);
    }

    public function test_unknown_nodes_return_endpoint_specific_neutral_not_found_errors(): void
    {
        $user = $this->createUser();
        $subject = $this->createSubject('anatomy', 'Анатомия');
        $course = $this->createCourse($subject, 'osteology', 'Остеология');
        $this->createLesson($course, 'bones', 'Кости');
        Sanctum::actingAs($user);

        $cases = [
            ['/api/v1/learning/subjects/missing', 'LEARNING_SUBJECT_NOT_FOUND', 'Учебный раздел не найден.'],
            ['/api/v1/learning/subjects/missing/courses/course', 'LEARNING_COURSE_NOT_FOUND', 'Учебный курс не найден.'],
            [$this->subjectUrl($subject).'/courses/missing', 'LEARNING_COURSE_NOT_FOUND', 'Учебный курс не найден.'],
            ['/api/v1/learning/subjects/missing/courses/course/lessons/lesson', 'LEARNING_LESSON_NOT_FOUND', 'Урок не найден.'],
            [$this->courseUrl($subject, $course).'/lessons/missing', 'LEARNING_LESSON_NOT_FOUND', 'Урок не найден.'],
        ];

        foreach ($cases as [$url, $code, $message]) {
            $this->assertNeutralError($this->getJson($url), 404, $code, $message);
        }
    }

    public function test_scoped_bindings_reject_wrong_parents_and_resolve_slug_collisions(): void
    {
        $user = $this->createUser();
        $subjectA = $this->createSubject('subject-a', 'Предмет A');
        $subjectB = $this->createSubject('subject-b', 'Предмет B');
        $sharedCourseA = $this->createCourse($subjectA, 'shared-course', 'Курс A');
        $sharedCourseB = $this->createCourse($subjectB, 'shared-course', 'Курс B');
        $sharedLessonA = $this->createLesson($sharedCourseA, 'shared-lesson', 'Урок A');
        $sharedLessonB = $this->createLesson($sharedCourseB, 'shared-lesson', 'Урок B');
        $courseOnlyB = $this->createCourse($subjectB, 'course-only-b', 'Курс только B');
        $lessonOnlyB = $this->createLesson($courseOnlyB, 'lesson-only-b', 'Урок только B');
        $this->createGrant($user, $sharedLessonB);
        $this->createGrant($user, $sharedLessonA);
        $this->createGrant($user, $lessonOnlyB);
        Sanctum::actingAs($user);

        $this->getJson($this->lessonUrl($subjectB, $sharedCourseB, $sharedLessonB))
            ->assertOk()
            ->assertJsonPath('data.id', $sharedLessonB->id)
            ->assertJsonPath('data.course.id', $sharedCourseB->id)
            ->assertJsonPath('data.subject.id', $subjectB->id);

        $this->assertNeutralError(
            $this->getJson($this->subjectUrl($subjectA).'/courses/course-only-b'),
            404,
            'LEARNING_COURSE_NOT_FOUND',
            'Учебный курс не найден.',
        );
        $this->assertNeutralError(
            $this->getJson($this->courseUrl($subjectB, $sharedCourseB).'/lessons/lesson-only-b'),
            404,
            'LEARNING_LESSON_NOT_FOUND',
            'Урок не найден.',
        );
    }

    public function test_reserved_subject_slugs_return_endpoint_specific_not_found_errors(): void
    {
        $user = $this->createUser();
        Sanctum::actingAs($user);

        foreach (['lessons', 'favorites'] as $reservedSlug) {
            $subject = $this->createSubject($reservedSlug, "Reserved {$reservedSlug}");
            $course = $this->createCourse($subject, "course-{$reservedSlug}", 'Скрытый курс');
            $lesson = $this->createLesson($course, "lesson-{$reservedSlug}", 'Скрытый урок');
            $this->createGrant($user, $lesson);

            $this->assertNeutralError(
                $this->getJson($this->subjectUrl($subject)),
                404,
                'LEARNING_SUBJECT_NOT_FOUND',
                'Учебный раздел не найден.',
            );
            $this->assertNeutralError(
                $this->getJson($this->courseUrl($subject, $course)),
                404,
                'LEARNING_COURSE_NOT_FOUND',
                'Учебный курс не найден.',
            );
            $this->assertNeutralError(
                $this->getJson($this->lessonUrl($subject, $course, $lesson)),
                404,
                'LEARNING_LESSON_NOT_FOUND',
                'Урок не найден.',
            );
        }
    }

    public function test_each_inactive_node_returns_not_found_before_access_resolution(): void
    {
        $user = $this->createUser();
        $inactiveSubject = $this->createSubject('inactive-subject', 'Неактивный предмет', active: false);
        $courseUnderInactiveSubject = $this->createCourse($inactiveSubject, 'course-a', 'Курс A');
        $lessonUnderInactiveSubject = $this->createLesson($courseUnderInactiveSubject, 'lesson-a', 'Урок A');
        $activeSubject = $this->createSubject('active-subject', 'Активный предмет');
        $inactiveCourse = $this->createCourse($activeSubject, 'inactive-course', 'Неактивный курс', active: false);
        $lessonUnderInactiveCourse = $this->createLesson($inactiveCourse, 'lesson-b', 'Урок B');
        $activeCourse = $this->createCourse($activeSubject, 'active-course', 'Активный курс');
        $inactiveLesson = $this->createLesson($activeCourse, 'inactive-lesson', 'Неактивный урок', active: false);
        $this->createGrant($user, $lessonUnderInactiveSubject);
        $this->createGrant($user, $lessonUnderInactiveCourse);
        $this->createGrant($user, $inactiveLesson);
        Sanctum::actingAs($user);

        $this->assertNeutralError(
            $this->getJson($this->subjectUrl($inactiveSubject)),
            404,
            'LEARNING_SUBJECT_NOT_FOUND',
            'Учебный раздел не найден.',
        );
        $this->assertNeutralError(
            $this->getJson($this->courseUrl($inactiveSubject, $courseUnderInactiveSubject)),
            404,
            'LEARNING_COURSE_NOT_FOUND',
            'Учебный курс не найден.',
        );
        $this->assertNeutralError(
            $this->getJson($this->lessonUrl($inactiveSubject, $courseUnderInactiveSubject, $lessonUnderInactiveSubject)),
            404,
            'LEARNING_LESSON_NOT_FOUND',
            'Урок не найден.',
        );
        $this->assertNeutralError(
            $this->getJson($this->courseUrl($activeSubject, $inactiveCourse)),
            404,
            'LEARNING_COURSE_NOT_FOUND',
            'Учебный курс не найден.',
        );
        $this->assertNeutralError(
            $this->getJson($this->lessonUrl($activeSubject, $inactiveCourse, $lessonUnderInactiveCourse)),
            404,
            'LEARNING_LESSON_NOT_FOUND',
            'Урок не найден.',
        );
        $this->assertNeutralError(
            $this->getJson($this->lessonUrl($activeSubject, $activeCourse, $inactiveLesson)),
            404,
            'LEARNING_LESSON_NOT_FOUND',
            'Урок не найден.',
        );
    }

    public function test_ineffective_access_states_deny_published_lessons_without_leaking_content(): void
    {
        $viewer = $this->createUser();
        $otherUser = $this->createUser();
        Sanctum::actingAs($viewer);

        $cases = [];
        foreach (['none', 'other-user', 'revoked', 'expired-status', 'expired-time'] as $case) {
            $subject = $this->createSubject("subject-{$case}", "Subject {$case}");
            $course = $this->createCourse($subject, "course-{$case}", "Course {$case}");
            $lesson = $this->createLesson($course, "lesson-{$case}", "Protected {$case}");
            $this->createPublishedContent($lesson, [
                'blocks' => [[
                    'type' => 'paragraph',
                    'text' => "Protected body {$case}",
                ]],
                'toc' => [],
            ]);

            match ($case) {
                'other-user' => $this->createGrant($otherUser, $lesson),
                'revoked' => $this->createGrant($viewer, $lesson, UserEntitlementGrant::STATUS_REVOKED),
                'expired-status' => $this->createGrant($viewer, $lesson, UserEntitlementGrant::STATUS_EXPIRED),
                'expired-time' => $this->createGrant(
                    $viewer,
                    $lesson,
                    UserEntitlementGrant::STATUS_ACTIVE,
                    expired: true,
                ),
                default => null,
            };

            $cases[] = [$subject, $course, $lesson, $case];
        }

        foreach ($cases as [$subject, $course, $lesson, $case]) {
            $response = $this->getJson($this->lessonUrl($subject, $course, $lesson));
            $this->assertNeutralError(
                $response,
                403,
                'LEARNING_ACCESS_DENIED',
                'У пользователя нет доступа к этому уроку.',
            );
            $this->assertStringNotContainsString("Protected body {$case}", $response->getContent() ?: '');
        }
    }

    public function test_client_access_and_payment_hints_do_not_unlock_canonical_endpoints(): void
    {
        $user = $this->createUser();
        $subject = $this->createSubject('anatomy', 'Анатомия');
        $course = $this->createCourse($subject, 'osteology', 'Остеология');
        $lesson = $this->createLesson($course, 'bones', 'Кости');
        $this->createPublishedContent($lesson);
        Sanctum::actingAs($user);
        $headers = [
            'X-Access' => 'full',
            'X-Paid' => 'true',
        ];

        foreach (['?access=full', '?paid=true', '?unknown=value'] as $query) {
            $this->getJson($this->subjectUrl($subject).$query, $headers)
                ->assertOk()
                ->assertJsonPath('data.access', 'none')
                ->assertJsonPath('data.courses.0.access', 'none')
                ->assertJsonCount(1, 'data.courses');
            $this->getJson($this->courseUrl($subject, $course).$query, $headers)
                ->assertOk()
                ->assertJsonPath('data.access', 'none')
                ->assertJsonPath('data.lessons.0.access', 'none')
                ->assertJsonPath('data.lessons.0.availability', 'locked')
                ->assertJsonCount(1, 'data.lessons');
            $this->assertNeutralError(
                $this->getJson($this->lessonUrl($subject, $course, $lesson).$query, $headers),
                403,
                'LEARNING_ACCESS_DENIED',
                'У пользователя нет доступа к этому уроку.',
            );
        }
    }

    public function test_auth_and_permission_middleware_protect_canonical_endpoints(): void
    {
        $subject = $this->createSubject('anatomy', 'Анатомия');
        $course = $this->createCourse($subject, 'osteology', 'Остеология');
        $lesson = $this->createLesson($course, 'bones', 'Кости');
        $urls = [
            $this->subjectUrl($subject),
            $this->courseUrl($subject, $course),
            $this->lessonUrl($subject, $course, $lesson),
        ];

        foreach ($urls as $url) {
            $this->getJson($url)->assertUnauthorized();
        }

        Sanctum::actingAs($this->createUser(['phone_verified_at' => null]));
        $this->getJson($urls[0])->assertOk();
        $this->getJson($urls[1])->assertOk();
        $this->getJson($urls[2])
            ->assertForbidden()
            ->assertJsonPath('error.code', 'LEARNING_ACCESS_DENIED');

        Sanctum::actingAs($this->createUser(['role' => 'guest']));
        foreach ($urls as $url) {
            $this->getJson($url)->assertForbidden();
        }
    }

    private function createUser(array $attributes = []): User
    {
        return User::factory()->create([
            'role' => 'user_no_purchase',
            ...$attributes,
        ]);
    }

    private function createAssessmentVersion(
        Lesson $lesson,
        int $contentVersion,
        string $status,
        int $questionCount,
    ): LessonAssessmentVersion {
        $assessment = LessonAssessment::query()->firstOrCreate([
            'lesson_id' => $lesson->id,
        ]);
        $questions = [];
        $answerKey = [];
        for ($index = 1; $index <= $questionCount; $index++) {
            $questionId = "q-{$index}";
            $questions[] = [
                'id' => $questionId,
                'sectionId' => 'main',
                'type' => 'single_choice',
                'gradingMode' => $index === $questionCount ? 'manual' : 'automatic',
                'options' => [
                    ['id' => 'o1', 'label' => 'One'],
                    ['id' => 'o2', 'label' => 'Two'],
                ],
            ];
            $answerKey[$questionId] = ['response' => ['selectedOptionId' => 'o1']];
        }

        return LessonAssessmentVersion::query()->forceCreate([
            'lesson_assessment_id' => $assessment->id,
            'status' => $status,
            'schema_version' => 2,
            'content_version' => $contentVersion,
            'scoring_policy' => LessonAssessmentSeeder::validatedFixture()['scoringPolicy'],
            'source_assignment_sha256' => str_repeat('a', 64),
            'source_key_sha256' => str_repeat('b', 64),
            'sections' => [['id' => 'main', 'title' => 'Main']],
            'questions' => $questions,
            'answer_key' => $answerKey,
            'published_at' => $status === LessonAssessmentVersion::STATUS_PUBLISHED ? now() : null,
        ]);
    }

    private function createAssessmentAttempt(
        User $user,
        LessonAssessmentVersion $version,
        string $status = LessonAssessmentAttempt::STATUS_IN_PROGRESS,
        int $correctCount = 0,
    ): LessonAssessmentAttempt {
        $submitted = $status !== LessonAssessmentAttempt::STATUS_IN_PROGRESS;
        $passed = $status === LessonAssessmentAttempt::STATUS_PASSED;
        $automaticScore = $submitted ? [
            'checked' => true,
            'passed' => $passed,
            'mastered' => $passed,
            'overall' => [
                'numerator' => $passed ? 1 : 0,
                'denominator' => 1,
                'percentBps' => $passed ? 10000 : 0,
                'requiredBps' => 8000,
                'passed' => $passed,
            ],
            'sections' => [[
                'sectionId' => 'main',
                'numerator' => $passed ? 1 : 0,
                'denominator' => 1,
                'percentBps' => $passed ? 10000 : 0,
                'requiredBps' => 6000,
                'passed' => $passed,
            ]],
            'correctCount' => $correctCount,
            'questionResults' => [],
        ] : null;

        return LessonAssessmentAttempt::query()->forceCreate([
            'user_id' => $user->id,
            'lesson_assessment_id' => $version->lesson_assessment_id,
            'lesson_assessment_version_id' => $version->id,
            'status' => $status,
            'correct_count' => $correctCount,
            'automatic_score' => $automaticScore,
            'manual_score' => $passed ? [
                'percentBps' => 8000,
                'requiredBps' => 6000,
                'passed' => true,
            ] : null,
            'final_score' => $passed ? [
                'checked' => true,
                'passed' => true,
                'mastered' => false,
                'overall' => [
                    'numerator' => 9400,
                    'denominator' => 10000,
                    'percentBps' => 9400,
                    'requiredBps' => 8000,
                    'passed' => true,
                ],
                'components' => [
                    'automaticBps' => 10000,
                    'manualBps' => 8000,
                    'automaticWeightBps' => 7000,
                    'manualWeightBps' => 3000,
                ],
            ] : null,
            'revision' => 0,
            'checked_at' => $status === LessonAssessmentAttempt::STATUS_IN_PROGRESS ? null : now(),
            'submitted_at' => $submitted ? now() : null,
            'finalized_at' => $passed ? now() : null,
            'failure_reason' => $status === LessonAssessmentAttempt::STATUS_FAILED
                ? LessonAssessmentAttempt::FAILURE_AUTOMATIC_THRESHOLD
                : null,
            'passed_at' => $status === LessonAssessmentAttempt::STATUS_PASSED ? now() : null,
            'check_count' => $status === LessonAssessmentAttempt::STATUS_IN_PROGRESS ? 0 : 1,
        ]);
    }

    private function createSubject(
        string $slug,
        string $title,
        int $sortOrder = 0,
        bool $active = true,
    ): Subject {
        return Subject::query()->create([
            'slug' => $slug,
            'title' => $title,
            'sort_order' => $sortOrder,
            'is_active' => $active,
        ]);
    }

    private function createCourse(
        Subject $subject,
        string $slug,
        string $title,
        ?string $shortTitle = null,
        int $sortOrder = 0,
        bool $active = true,
    ): Course {
        return Course::query()->create([
            'subject_id' => $subject->id,
            'slug' => $slug,
            'title' => $title,
            'short_title' => $shortTitle,
            'price_cents' => 100000,
            'sort_order' => $sortOrder,
            'is_active' => $active,
        ]);
    }

    private function createLesson(
        Course $course,
        string $slug,
        string $title,
        int $sortOrder = 0,
        bool $active = true,
        bool $freePreview = false,
    ): Lesson {
        return Lesson::query()->create([
            'course_id' => $course->id,
            'slug' => $slug,
            'title' => $title,
            'sort_order' => $sortOrder,
            'is_active' => $active,
            'is_free_preview' => $freePreview,
        ]);
    }

    private function createGrant(
        User $user,
        Lesson $lesson,
        string $status = UserEntitlementGrant::STATUS_ACTIVE,
        bool $expired = false,
    ): UserEntitlementGrant {
        return UserEntitlementGrant::query()->create([
            'user_id' => $user->id,
            'atom_kind' => UserEntitlementGrant::ATOM_LESSON,
            'atom_id' => $lesson->id,
            'status' => $status,
            'source_type' => UserEntitlementGrant::SOURCE_TEST_FIXTURE,
            'source_id' => "lesson-{$lesson->id}",
            'idempotency_key' => "hierarchy-{$lesson->id}-{$user->id}-{$status}-".($expired ? 'expired' : 'current'),
            'granted_at' => $status === UserEntitlementGrant::STATUS_ACTIVE ? now() : null,
            'revoked_at' => $status === UserEntitlementGrant::STATUS_REVOKED ? now() : null,
            'expires_at' => $expired ? now()->subMinute() : null,
        ]);
    }

    /**
     * @param  array<string, mixed>|null  $body
     */
    private function createPublishedContent(
        Lesson $lesson,
        ?array $body = null,
    ): LessonLearningContent {
        return LessonLearningContent::query()->create([
            'lesson_id' => $lesson->id,
            'status' => LessonLearningContent::STATUS_PUBLISHED,
            'format' => LessonLearningContent::FORMAT_LESSON_BLOCKS_V1,
            'version' => 1,
            'body' => $body ?? [
                'blocks' => [[
                    'type' => 'paragraph',
                    'text' => 'Опубликованный учебный текст.',
                ]],
                'toc' => [],
            ],
            'published_at' => now(),
            'import_status' => LessonLearningContent::IMPORT_STATUS_MANUAL,
        ]);
    }

    private function subjectUrl(Subject $subject): string
    {
        return "/api/v1/learning/subjects/{$subject->slug}";
    }

    private function courseUrl(Subject $subject, Course $course): string
    {
        return $this->subjectUrl($subject)."/courses/{$course->slug}";
    }

    private function lessonUrl(Subject $subject, Course $course, Lesson $lesson): string
    {
        return $this->courseUrl($subject, $course)."/lessons/{$lesson->slug}";
    }

    /** @return array{testAvailable:bool,testPassed:bool,testProgress:null} */
    private function unavailableTestPayload(): array
    {
        return [
            'testAvailable' => false,
            'testPassed' => false,
            'testProgress' => null,
        ];
    }

    private function assertNeutralError(
        TestResponse $response,
        int $status,
        string $code,
        string $message,
    ): void {
        $response
            ->assertStatus($status)
            ->assertExactJson([
                'error' => [
                    'code' => $code,
                    'message' => $message,
                ],
            ]);
    }

    private function assertNoProtectedKeys(TestResponse $response): void
    {
        $this->assertArrayDoesNotContainProtectedKeys($response->json());
    }

    /**
     * @param  array<mixed>  $payload
     */
    private function assertArrayDoesNotContainProtectedKeys(array $payload): void
    {
        $forbiddenKeys = [
            'body',
            'content',
            'file',
            'files',
            'html',
            'media',
            'video',
        ];

        foreach ($payload as $key => $value) {
            if (is_string($key)) {
                $this->assertNotContains($key, $forbiddenKeys);
                $this->assertFalse(str_starts_with($key, 'legacy_'));
            }

            if (is_array($value)) {
                $this->assertArrayDoesNotContainProtectedKeys($value);
            }
        }
    }
}
