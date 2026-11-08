<?php

namespace Tests\Feature;

use App\Exceptions\LessonAssessmentException;
use App\Models\Course;
use App\Models\Lesson;
use App\Models\LessonAssessment;
use App\Models\LessonAssessmentAttempt;
use App\Models\LessonAssessmentAttemptResponse;
use App\Models\LessonAssessmentCompletion;
use App\Models\LessonAssessmentVersion;
use App\Models\Subject;
use App\Models\User;
use App\Services\Learning\LessonAssessmentAttemptService;
use App\Services\Learning\LessonAssessmentManualReviewService;
use Database\Seeders\LessonAssessmentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LessonAssessmentAttemptServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_start_is_repeatable_and_prefers_an_in_progress_immutable_version(): void
    {
        [$assessment, $published] = $this->createAssessment();
        $user = User::factory()->create();
        $retired = $this->createVersion($assessment, 2, LessonAssessmentVersion::STATUS_RETIRED);
        $existing = $this->createAttempt($user, $assessment, $retired);
        $service = app(LessonAssessmentAttemptService::class);

        $first = $service->start($user, $assessment);
        $second = $service->start($user, $assessment);

        $this->assertFalse($first['created']);
        $this->assertFalse($second['created']);
        $this->assertSame($existing->id, $first['attempt']->id);
        $this->assertSame($existing->id, $second['attempt']->id);
        $this->assertNotSame($published->id, $first['attempt']->lesson_assessment_version_id);
        $this->assertDatabaseCount('lesson_assessment_attempts', 1);
    }

    public function test_start_creates_only_one_current_attempt_and_prefers_active_attempt(): void
    {
        [$assessment, $published, $lesson] = $this->createAssessment();
        $user = User::factory()->create();
        $service = app(LessonAssessmentAttemptService::class);

        $created = $service->start($user, $assessment);
        $repeated = $service->start($user, $assessment);

        $this->assertTrue($created['created']);
        $this->assertFalse($repeated['created']);
        $this->assertSame($published->id, $created['attempt']->lesson_assessment_version_id);
        $this->assertSame($created['attempt']->id, $repeated['attempt']->id);

        $created['attempt']->forceFill([
            'status' => LessonAssessmentAttempt::STATUS_PASSED,
            'correct_count' => 48,
            'passed_at' => now(),
        ])->save();
        LessonAssessmentCompletion::query()->forceCreate([
            'user_id' => $user->id,
            'lesson_id' => $lesson->id,
            'lesson_assessment_id' => $assessment->id,
            'passed_attempt_id' => $created['attempt']->id,
            'completed_at' => now(),
        ]);
        $active = $this->createAttempt($user, $assessment, $this->createVersion(
            $assessment,
            2,
            LessonAssessmentVersion::STATUS_RETIRED,
        ));

        $completed = $service->start($user, $assessment);

        $this->assertFalse($completed['created']);
        $this->assertSame($active->id, $completed['attempt']->id);
    }

    public function test_retake_preserves_completion_and_reuses_one_blank_active_attempt(): void
    {
        [$assessment, $versionOne, $lesson] = $this->createAssessment();
        $user = User::factory()->create();
        $original = $this->createAttempt($user, $assessment, $versionOne);
        $original->forceFill([
            'status' => LessonAssessmentAttempt::STATUS_PASSED,
            'correct_count' => 48,
            'passed_at' => now()->subDay(),
        ])->save();
        $completion = LessonAssessmentCompletion::query()->forceCreate([
            'user_id' => $user->id,
            'lesson_id' => $lesson->id,
            'lesson_assessment_id' => $assessment->id,
            'passed_attempt_id' => $original->id,
            'completed_at' => now()->subDay(),
        ]);
        $versionOne->forceFill(['status' => LessonAssessmentVersion::STATUS_RETIRED])->save();
        $versionTwo = $this->createVersion($assessment, 2, LessonAssessmentVersion::STATUS_PUBLISHED);
        $service = app(LessonAssessmentAttemptService::class);

        $first = $service->start($user, $assessment, true);
        $second = $service->start($user, $assessment, true);
        $ordinaryStart = $service->start($user, $assessment);

        $this->assertTrue($first['created']);
        $this->assertFalse($second['created']);
        $this->assertFalse($ordinaryStart['created']);
        $this->assertNotSame($original->id, $first['attempt']->id);
        $this->assertSame($first['attempt']->id, $second['attempt']->id);
        $this->assertSame($first['attempt']->id, $ordinaryStart['attempt']->id);
        $this->assertSame($versionTwo->id, $first['attempt']->lesson_assessment_version_id);
        $this->assertSame(LessonAssessmentAttempt::STATUS_IN_PROGRESS, $first['attempt']->status);
        $this->assertDatabaseCount('lesson_assessment_attempt_responses', 0);
        $this->assertSame($original->id, $completion->refresh()->passed_attempt_id);
        $this->assertDatabaseCount('lesson_assessment_attempts', 2);
        $this->assertDatabaseCount('lesson_assessment_completions', 1);
    }

    public function test_successful_retake_updates_completion_to_the_latest_pass(): void
    {
        [$assessment, $version, $lesson] = $this->createAssessment(48);
        $user = User::factory()->create();
        $original = $this->createAttempt($user, $assessment, $version);
        $original->forceFill([
            'status' => LessonAssessmentAttempt::STATUS_PASSED,
            'correct_count' => 48,
            'passed_at' => now()->subDay(),
        ])->save();
        $completion = LessonAssessmentCompletion::query()->forceCreate([
            'user_id' => $user->id,
            'lesson_id' => $lesson->id,
            'lesson_assessment_id' => $assessment->id,
            'passed_attempt_id' => $original->id,
            'completed_at' => now()->subDay(),
        ]);
        $service = app(LessonAssessmentAttemptService::class);
        $retake = $service->start($user, $assessment, true)['attempt'];
        $responses = array_map(
            fn (int $number): array => [
                'questionId' => sprintf('question-%02d', $number),
                'response' => ['selectedOptionId' => 'o1'],
            ],
            range(1, 48),
        );

        $saved = $service->save($user, $retake, 0, $responses, 'question-48');
        $submitted = $service->submit($user, $saved, 1);
        $result = $this->finalizePending($submitted['attempt']);

        $this->assertSame(LessonAssessmentAttempt::STATUS_PASSED, $result['attempt']['status']);
        $this->assertSame($retake->id, $completion->refresh()->passed_attempt_id);
        $this->assertTrue($completion->completed_at->equalTo($original->passed_at));
        $this->assertDatabaseCount('lesson_assessment_completions', 1);
        $this->assertDatabaseCount('lesson_assessment_attempts', 2);
    }

    public function test_save_uses_optimistic_revision_and_derives_completeness(): void
    {
        [$assessment] = $this->createAssessment();
        $user = User::factory()->create();
        $service = app(LessonAssessmentAttemptService::class);
        $attempt = $service->start($user, $assessment)['attempt'];

        $saved = $service->save($user, $attempt, 0, [
            ['questionId' => 'question-01', 'response' => ['selectedOptionId' => 'o1']],
            ['questionId' => 'question-02', 'response' => ['selections' => ['p01' => 'o1']]],
        ], 'question-02');

        $this->assertSame(1, $saved->revision);
        $this->assertSame('question-02', $saved->last_question_id);
        $this->assertTrue((bool) $attempt->responses()->where('question_id', 'question-01')->value('is_complete'));
        $this->assertFalse((bool) $attempt->responses()->where('question_id', 'question-02')->value('is_complete'));

        $this->expectAssessmentException(
            LessonAssessmentException::ATTEMPT_CONFLICT,
            409,
            fn () => $service->save($user, $attempt, 0, [], null),
        );
    }

    public function test_save_can_lock_complete_answers_but_rejects_incomplete_locks(): void
    {
        [$assessment] = $this->createAssessment();
        $user = User::factory()->create();
        $service = app(LessonAssessmentAttemptService::class);
        $attempt = $service->start($user, $assessment)['attempt'];

        $locked = $service->save($user, $attempt, 0, [
            ['questionId' => 'question-01', 'response' => ['selectedOptionId' => 'o1']],
        ], 'question-01', ['question-01']);

        $this->assertSame(1, $locked->revision);
        $this->assertTrue((bool) $locked->responses()->where('question_id', 'question-01')->value('is_complete'));
        $this->assertTrue((bool) $locked->responses()->where('question_id', 'question-01')->value('is_locked'));

        $this->expectAssessmentException(
            LessonAssessmentException::LOCKED_RESPONSE,
            409,
            fn () => $service->save($user, $locked, 1, [
                ['questionId' => 'question-01', 'response' => ['selectedOptionId' => 'o2']],
            ], 'question-01'),
        );

        $incompleteUser = User::factory()->create();
        $incompleteAttempt = $service->start($incompleteUser, $assessment)['attempt'];
        $this->expectAssessmentException(
            LessonAssessmentException::INCOMPLETE,
            422,
            fn () => $service->save($incompleteUser, $incompleteAttempt, 0, [
                ['questionId' => 'question-02', 'response' => ['selections' => ['p01' => 'o1']]],
            ], 'question-02', ['question-02']),
        );
        $this->assertFalse(
            (bool) $incompleteAttempt->responses()->where('question_id', 'question-02')->exists(),
        );
    }

    public function test_typed_and_long_answers_save_completeness_and_submit_by_explicit_grading_mode(): void
    {
        [$assessment, $version] = $this->createAssessment();
        $version->forceFill([
            'questions' => [
                [
                    'id' => 'fill-01',
                    'sectionId' => 'attempts',
                    'type' => 'text_fields',
                    'gradingMode' => 'automatic',
                    'parts' => [
                        ['id' => 'p01', 'prompt' => 'Строка 1'],
                        ['id' => 'p02', 'prompt' => 'Строка 2'],
                    ],
                ],
                ['id' => 'expanded-01', 'sectionId' => 'attempts', 'type' => 'long_text', 'gradingMode' => 'manual', 'rows' => 3],
            ],
            'answer_key' => [
                'fill-01' => ['response' => ['acceptedTexts' => [
                    'p01' => ['Жёлтая связка'],
                    'p02' => ['discus intervertebralis'],
                ]]],
                'expanded-01' => ['response' => [
                    'requiredFragments' => [
                        ['шейный отдел'],
                        ['грудной отдел'],
                    ],
                    'referenceAnswers' => ['Шейный и грудной отделы.'],
                ]],
            ],
        ])->save();
        $user = User::factory()->create();
        $service = app(LessonAssessmentAttemptService::class);
        $attempt = $service->start($user, $assessment)['attempt'];

        $partial = $service->save($user, $attempt, 0, [[
            'questionId' => 'fill-01',
            'response' => ['texts' => ['p01' => 'Жёлтая связка', 'p02' => '']],
        ]], 'fill-01');
        $this->assertFalse((bool) $partial->responses()->where('question_id', 'fill-01')->value('is_complete'));

        $saved = $service->save($user, $partial, 1, [
            [
                'questionId' => 'fill-01',
                'response' => ['texts' => [
                    'p01' => 'желтая, связка',
                    'p02' => 'DISCUS INTERVERTEBRALIS',
                ]],
            ],
            [
                'questionId' => 'expanded-01',
                'response' => ['text' => 'Сначала грудной отдел, затем шейный отдел.'],
            ],
        ], 'expanded-01');
        $result = $service->submit($user, $saved, 2);

        $this->assertSame(1, $result['attempt']->correct_count);
        $this->assertSame([true], array_column($result['automaticGate']['questionResults'], 'correct'));
        $this->assertSame(LessonAssessmentAttempt::STATUS_PENDING_REVIEW, $result['attempt']->status);
    }

    public function test_accepted_term_forms_are_scored_through_save_and_submit(): void
    {
        [$assessment, $version] = $this->createAssessment();
        $version->forceFill([
            'questions' => [
                [
                    'id' => 'fundamentals-01',
                    'sectionId' => 'attempts',
                    'type' => 'text_fields',
                    'gradingMode' => 'automatic',
                    'parts' => [
                        ['id' => 'p01', 'prompt' => 'Строка 1'],
                        ['id' => 'p02', 'prompt' => 'Строка 2'],
                        ['id' => 'p03', 'prompt' => 'Строка 3'],
                        ['id' => 'p04', 'prompt' => 'Строка 4'],
                        ['id' => 'p05', 'prompt' => 'Строка 5'],
                    ],
                ],
                ['id' => 'expanded-01', 'sectionId' => 'attempts', 'type' => 'long_text', 'gradingMode' => 'manual', 'rows' => 3],
            ],
            'answer_key' => [
                'fundamentals-01' => ['response' => [
                    'acceptedTexts' => [
                        'p01' => ['связки', 'связка'],
                        'p02' => ['межкостная мембрана'],
                        'p03' => ['роднички', 'родничок'],
                        'p04' => ['швы', 'шов'],
                        'p05' => ['вколачивания', 'вколачивание'],
                    ],
                    'unorderedPartGroups' => [['p01', 'p02', 'p03', 'p04', 'p05']],
                ]],
                'expanded-01' => ['response' => [
                    'requiredFragments' => [['эталон']],
                    'referenceAnswers' => ['Эталон.'],
                ]],
            ],
        ])->save();
        $user = User::factory()->create();
        $service = app(LessonAssessmentAttemptService::class);
        $attempt = $service->start($user, $assessment)['attempt'];

        $saved = $service->save($user, $attempt, 0, [
            [
                'questionId' => 'fundamentals-01',
                'response' => ['texts' => [
                    'p01' => 'шов',
                    'p02' => 'связка',
                    'p03' => 'родничок',
                    'p04' => 'вколачивание',
                    'p05' => 'межкостная мембрана',
                ]],
            ],
            [
                'questionId' => 'expanded-01',
                'response' => ['text' => 'Ответ для ручной проверки.'],
            ],
        ], 'expanded-01');
        $result = $service->submit($user, $saved, 1);

        $this->assertSame(1, $result['attempt']->correct_count);
        $this->assertSame([true], array_column($result['automaticGate']['questionResults'], 'correct'));
        $this->assertSame(LessonAssessmentAttempt::STATUS_PENDING_REVIEW, $result['attempt']->status);
    }

    public function test_save_rejects_an_associative_outer_response_container_without_mutation(): void
    {
        [$assessment] = $this->createAssessment();
        $user = User::factory()->create();
        $service = app(LessonAssessmentAttemptService::class);
        $attempt = $service->start($user, $assessment)['attempt'];

        $this->expectAssessmentException(
            LessonAssessmentException::INVALID_RESPONSE,
            422,
            fn () => $service->save($user, $attempt, 0, [
                'dirty' => [
                    'questionId' => 'question-01',
                    'response' => ['selectedOptionId' => 'o1'],
                ],
            ], null),
        );

        $attempt->refresh();
        $this->assertSame(0, $attempt->revision);
        $this->assertDatabaseCount('lesson_assessment_attempt_responses', 0);
    }

    public function test_save_rejects_unknown_references_and_locked_mutations_without_leaking_the_key(): void
    {
        [$assessment] = $this->createAssessment();
        $user = User::factory()->create();
        $service = app(LessonAssessmentAttemptService::class);
        $attempt = $service->start($user, $assessment)['attempt'];

        $this->expectAssessmentException(
            LessonAssessmentException::INVALID_RESPONSE,
            422,
            fn () => $service->save($user, $attempt, 0, [
                ['questionId' => 'question-01', 'response' => ['selectedOptionId' => 'unknown']],
            ], null),
        );
        $this->expectAssessmentException(
            LessonAssessmentException::INVALID_RESPONSE,
            422,
            fn () => $service->save($user, $attempt, 0, [], 'question-unknown'),
        );

        LessonAssessmentAttemptResponse::query()->forceCreate([
            'lesson_assessment_attempt_id' => $attempt->id,
            'question_id' => 'question-01',
            'response' => ['selectedOptionId' => 'o1'],
            'is_complete' => true,
            'is_locked' => true,
        ]);

        $this->expectAssessmentException(
            LessonAssessmentException::LOCKED_RESPONSE,
            409,
            fn () => $service->save($user, $attempt, 0, [
                ['questionId' => 'question-01', 'response' => ['selectedOptionId' => 'o2']],
            ], null),
        );
    }

    public function test_save_rejects_wrong_owner_and_a_version_from_another_assessment(): void
    {
        [$assessment] = $this->createAssessment();
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $service = app(LessonAssessmentAttemptService::class);
        $attempt = $service->start($user, $assessment)['attempt'];

        $this->expectAssessmentException(
            LessonAssessmentException::ATTEMPT_NOT_FOUND,
            404,
            fn () => $service->save($otherUser, $attempt, 0, [], null),
        );

        [, $foreignVersion] = $this->createAssessment();
        $attempt->forceFill(['lesson_assessment_version_id' => $foreignVersion->id])->save();

        $this->expectAssessmentException(
            LessonAssessmentException::ATTEMPT_CONFLICT,
            409,
            fn () => $service->save($user, $attempt, 0, [], null),
        );
    }

    public function test_submit_requires_every_question_to_be_complete_without_incrementing_submission_count(): void
    {
        [$assessment] = $this->createAssessment();
        $user = User::factory()->create();
        $service = app(LessonAssessmentAttemptService::class);
        $attempt = $service->start($user, $assessment)['attempt'];
        $attempt = $service->save($user, $attempt, 0, [
            ['questionId' => 'question-01', 'response' => ['selectedOptionId' => 'o1']],
        ], 'question-01');

        $this->expectAssessmentException(
            LessonAssessmentException::INCOMPLETE,
            422,
            fn () => $service->submit($user, $attempt, 1),
        );

        $attempt->refresh();
        $this->assertSame(0, $attempt->check_count);
        $this->assertSame(1, $attempt->revision);
    }

    public function test_failed_submission_is_terminal_locks_every_response_and_allows_only_a_clean_retake(): void
    {
        [$assessment] = $this->createAssessment();
        $user = User::factory()->create();
        $service = app(LessonAssessmentAttemptService::class);
        $attempt = $service->start($user, $assessment)['attempt'];
        $attempt = $service->save($user, $attempt, 0, [
            ['questionId' => 'question-01', 'response' => ['selectedOptionId' => 'o2']],
            ['questionId' => 'question-02', 'response' => ['selections' => ['p01' => 'o1', 'p02' => 'o1']]],
        ], null);

        $result = $service->submit($user, $attempt, 1);

        $this->assertFalse($result['automaticGate']['passed']);
        $this->assertSame([
            [
                'questionId' => 'question-01',
                'correct' => false,
                'earnedParts' => 0,
                'maxParts' => 1,
                'incorrectPartIds' => [],
            ],
        ], $result['automaticGate']['questionResults']);
        $this->assertSame(0, $result['attempt']->correct_count);
        $this->assertSame(1, $result['attempt']->check_count);
        $this->assertSame(2, $result['attempt']->revision);
        $this->assertSame(LessonAssessmentAttempt::STATUS_FAILED, $result['attempt']->status);
        $this->assertSame(LessonAssessmentAttempt::FAILURE_AUTOMATIC_THRESHOLD, $result['attempt']->failure_reason);
        $this->assertTrue((bool) $attempt->responses()->where('question_id', 'question-01')->value('is_locked'));
        $this->assertTrue((bool) $attempt->responses()->where('question_id', 'question-02')->value('is_locked'));
        $this->assertDatabaseCount('lesson_assessment_manual_reviews', 0);

        $this->expectAssessmentException(
            LessonAssessmentException::ATTEMPT_CONFLICT,
            409,
            fn () => $service->save($user, $result['attempt'], 2, [
                ['questionId' => 'question-02', 'response' => ['selections' => ['p01' => 'o1', 'p02' => 'o2']]],
            ], 'question-02'),
        );

        $sameTerminal = $service->start($user, $assessment);
        $retake = $service->start($user, $assessment, true);

        $this->assertFalse($sameTerminal['created']);
        $this->assertSame($result['attempt']->id, $sameTerminal['attempt']->id);
        $this->assertTrue($retake['created']);
        $this->assertNotSame($result['attempt']->id, $retake['attempt']->id);
        $this->assertSame(LessonAssessmentAttempt::STATUS_IN_PROGRESS, $retake['attempt']->status);
        $this->assertSame(0, $retake['attempt']->responses()->count());
    }

    public function test_exact_48_question_submission_and_finalization_are_atomic_and_terminal(): void
    {
        [$assessment] = $this->createAssessment(48);
        $user = User::factory()->create();
        $service = app(LessonAssessmentAttemptService::class);
        $attempt = $service->start($user, $assessment)['attempt'];
        $responses = array_map(
            fn (int $number): array => [
                'questionId' => sprintf('question-%02d', $number),
                'response' => ['selectedOptionId' => 'o1'],
            ],
            range(1, 48),
        );
        $attempt = $service->save($user, $attempt, 0, $responses, 'question-48');

        $submitted = $service->submit($user, $attempt, 1);
        $this->assertSame(LessonAssessmentAttempt::STATUS_PENDING_REVIEW, $submitted['attempt']->status);
        $this->assertSame(47, $submitted['attempt']->correct_count);
        $this->assertSame(1, $submitted['attempt']->check_count);
        $this->assertSame(2, $submitted['attempt']->revision);

        $first = $this->finalizePending($submitted['attempt']);
        $this->assertSame(LessonAssessmentAttempt::STATUS_PASSED, $first['attempt']['status']);
        $this->expectAssessmentException(
            LessonAssessmentException::ATTEMPT_CONFLICT,
            409,
            fn () => $service->submit($user, $attempt->refresh(), 4),
        );
        $this->assertDatabaseCount('lesson_assessment_completions', 1);
    }

    public function test_minimum_manual_pass_creates_completion_without_mastery(): void
    {
        [$assessment, , $lesson] = $this->createAssessment(5);
        $user = User::factory()->create();
        $service = app(LessonAssessmentAttemptService::class);
        $attempt = $service->start($user, $assessment)['attempt'];
        $responses = array_map(
            fn (int $number): array => [
                'questionId' => sprintf('question-%02d', $number),
                'response' => ['selectedOptionId' => $number < 5 ? 'o1' : 'o2'],
            ],
            range(1, 5),
        );
        $attempt = $service->save($user, $attempt, 0, $responses, 'question-05');

        $submitted = $service->submit($user, $attempt, 1);
        $result = $this->finalizePending($submitted['attempt'], 6000);
        $this->assertSame(LessonAssessmentAttempt::STATUS_PASSED, $result['attempt']['status']);
        $this->assertFalse($result['finalScore']['mastered']);
        $this->assertSame(8800, $result['finalScore']['overall']['numerator']);
        $this->assertSame(10000, $result['finalScore']['overall']['denominator']);
        $this->assertSame(4, $submitted['attempt']->correct_count);
        $this->assertCount(4, $submitted['automaticGate']['questionResults']);
        $this->expectAssessmentException(
            LessonAssessmentException::ATTEMPT_CONFLICT,
            409,
            fn () => $service->submit($user, $attempt->refresh(), 4),
        );
        $this->assertDatabaseHas('lesson_assessment_completions', [
            'user_id' => $user->id,
            'lesson_id' => $lesson->id,
            'passed_attempt_id' => $attempt->id,
        ]);
    }

    public function test_passing_overall_score_does_not_override_a_failing_section(): void
    {
        [$assessment, $version] = $this->createAssessment(5);
        $questions = $version->questions;
        $questions[3] = [
            'id' => 'question-04',
            'sectionId' => 'weak',
            'type' => 'slot_choice',
            'gradingMode' => 'automatic',
            'options' => $this->optionFixture(),
            'parts' => [
                ['id' => 'p01', 'prompt' => 'Part 1', 'optionIds' => ['o1', 'o2']],
                ['id' => 'p02', 'prompt' => 'Part 2', 'optionIds' => ['o1', 'o2']],
            ],
        ];
        $answerKey = $version->answer_key;
        $answerKey['question-04'] = [
            'response' => ['selections' => ['p01' => 'o1', 'p02' => 'o1']],
        ];
        $version->forceFill([
            'sections' => [
                ['id' => 'attempts', 'title' => 'Strong'],
                ['id' => 'weak', 'title' => 'Weak'],
            ],
            'questions' => $questions,
            'answer_key' => $answerKey,
        ])->save();
        $user = User::factory()->create();
        $service = app(LessonAssessmentAttemptService::class);
        $attempt = $service->start($user, $assessment)['attempt'];
        $responses = array_map(
            fn (int $number): array => [
                'questionId' => sprintf('question-%02d', $number),
                'response' => $number === 4
                    ? ['selections' => ['p01' => 'o1', 'p02' => 'o2']]
                    : ['selectedOptionId' => 'o1'],
            ],
            range(1, 5),
        );
        $attempt = $service->save($user, $attempt, 0, $responses, 'question-05');

        $result = $service->submit($user, $attempt, 1);

        $this->assertTrue($result['automaticGate']['overall']['passed']);
        $this->assertSame(8750, $result['automaticGate']['overall']['percentBps']);
        $this->assertFalse($result['automaticGate']['sections'][1]['passed']);
        $this->assertFalse($result['automaticGate']['passed']);
        $this->assertSame(LessonAssessmentAttempt::STATUS_FAILED, $result['attempt']->status);
        $this->assertDatabaseMissing('lesson_assessment_completions', ['user_id' => $user->id]);
    }

    public function test_weaker_successful_retake_does_not_downgrade_mastery_completion(): void
    {
        [$assessment, , $lesson] = $this->createAssessment(6);
        $user = User::factory()->create();
        $service = app(LessonAssessmentAttemptService::class);
        $original = $service->start($user, $assessment)['attempt'];
        $perfectResponses = array_map(
            fn (int $number): array => [
                'questionId' => sprintf('question-%02d', $number),
                'response' => ['selectedOptionId' => 'o1'],
            ],
            range(1, 6),
        );
        $original = $service->save($user, $original, 0, $perfectResponses, 'question-06');
        $perfectSubmission = $service->submit($user, $original, 1);
        $perfect = $this->finalizePending($perfectSubmission['attempt']);
        $completion = LessonAssessmentCompletion::query()
            ->where('user_id', $user->id)
            ->where('lesson_id', $lesson->id)
            ->firstOrFail();
        $firstCompletedAt = $completion->completed_at;

        $retake = $service->start($user, $assessment, true)['attempt'];
        $weakerResponses = $perfectResponses;
        $weakerResponses[4]['response'] = ['selectedOptionId' => 'o2'];
        $retake = $service->save($user, $retake, 0, $weakerResponses, 'question-06');
        $weakerSubmission = $service->submit($user, $retake, 1);
        $weaker = $this->finalizePending($weakerSubmission['attempt']);

        $this->assertTrue($perfect['finalScore']['mastered']);
        $this->assertSame(LessonAssessmentAttempt::STATUS_PASSED, $weaker['attempt']['status']);
        $this->assertFalse($weaker['finalScore']['mastered']);
        $this->assertSame($original->id, $completion->refresh()->passed_attempt_id);
        $this->assertTrue($completion->completed_at->equalTo($firstCompletedAt));
    }

    #[DataProvider('dynamicQuestionCountProvider')]
    public function test_completion_threshold_tracks_the_pinned_version_question_count(int $questionCount): void
    {
        [$assessment] = $this->createAssessment($questionCount);
        $user = User::factory()->create();
        $service = app(LessonAssessmentAttemptService::class);
        $attempt = $service->start($user, $assessment)['attempt'];
        $responses = array_map(
            fn (int $number): array => [
                'questionId' => sprintf('question-%02d', $number),
                'response' => ['selectedOptionId' => 'o1'],
            ],
            range(1, $questionCount),
        );
        $attempt = $service->save(
            $user,
            $attempt,
            0,
            $responses,
            sprintf('question-%02d', $questionCount),
        );

        $submitted = $service->submit($user, $attempt, 1);
        $result = $this->finalizePending($submitted['attempt']);

        $this->assertSame($questionCount - 1, $submitted['attempt']->correct_count);
        $this->assertSame(LessonAssessmentAttempt::STATUS_PASSED, $result['attempt']['status']);
        $this->assertDatabaseCount('lesson_assessment_completions', 1);
    }

    public static function dynamicQuestionCountProvider(): array
    {
        return [
            'below the legacy 48-question fixture' => [47],
            'above the legacy 48-question fixture' => [49],
        ];
    }

    private function createAssessment(int $questionCount = 2): array
    {
        $subject = Subject::query()->create([
            'slug' => 'assessment-subject-'.uniqid(),
            'title' => 'Assessment subject',
            'sort_order' => 1,
            'is_active' => true,
        ]);
        $course = Course::query()->create([
            'subject_id' => $subject->id,
            'slug' => 'assessment-course-'.uniqid(),
            'title' => 'Assessment course',
            'price_cents' => 1000,
            'sort_order' => 1,
            'is_active' => true,
        ]);
        $lesson = Lesson::query()->create([
            'course_id' => $course->id,
            'slug' => 'assessment-lesson-'.uniqid(),
            'title' => 'Assessment lesson',
            'sort_order' => 1,
            'is_active' => true,
        ]);
        $assessment = LessonAssessment::query()->create(['lesson_id' => $lesson->id]);
        $version = $this->createVersion(
            $assessment,
            1,
            LessonAssessmentVersion::STATUS_PUBLISHED,
            $questionCount,
        );

        return [$assessment, $version, $lesson];
    }

    private function createVersion(
        LessonAssessment $assessment,
        int $contentVersion,
        string $status,
        int $questionCount = 2,
    ): LessonAssessmentVersion {
        $questions = [];
        $answerKey = [];
        foreach (range(1, $questionCount) as $number) {
            $questionId = sprintf('question-%02d', $number);
            if ($questionCount === 2 && $number === 2) {
                $questions[] = [
                    'id' => $questionId,
                    'sectionId' => 'attempts',
                    'type' => 'slot_choice',
                    'gradingMode' => 'manual',
                    'options' => $this->optionFixture(),
                    'parts' => [
                        ['id' => 'p01', 'prompt' => 'Part 1', 'optionIds' => ['o1', 'o2']],
                        ['id' => 'p02', 'prompt' => 'Part 2', 'optionIds' => ['o1', 'o2']],
                    ],
                ];
                $answerKey[$questionId] = [
                    'response' => ['selections' => ['p01' => 'o1', 'p02' => 'o2']],
                ];

                continue;
            }

            $questions[] = [
                'id' => $questionId,
                'sectionId' => 'attempts',
                'type' => 'single_choice',
                'gradingMode' => $number === $questionCount ? 'manual' : 'automatic',
                'options' => $this->optionFixture(),
            ];
            $answerKey[$questionId] = ['response' => ['selectedOptionId' => 'o1']];
        }

        return LessonAssessmentVersion::query()->forceCreate([
            'lesson_assessment_id' => $assessment->id,
            'status' => $status,
            'schema_version' => 2,
            'content_version' => $contentVersion,
            'scoring_policy' => LessonAssessmentSeeder::validatedFixture()['scoringPolicy'],
            'source_assignment_sha256' => str_repeat((string) $contentVersion, 64),
            'source_key_sha256' => str_repeat((string) ($contentVersion + 1), 64),
            'sections' => [[
                'id' => 'attempts',
                'title' => "Attempt questions v{$contentVersion}",
            ]],
            'questions' => $questions,
            'answer_key' => $answerKey,
            'published_at' => $status === LessonAssessmentVersion::STATUS_PUBLISHED ? now() : null,
        ]);
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

    /** @return array<string, mixed> */
    private function finalizePending(LessonAssessmentAttempt $attempt, int $scoreBps = 10000): array
    {
        $reviewer = User::factory()->create(['role' => 'admin_full']);
        $service = app(LessonAssessmentManualReviewService::class);

        foreach ($attempt->manualReviews()->orderBy('id')->get() as $review) {
            $service->update(
                $reviewer,
                $attempt->id,
                $review->id,
                $review->revision,
                $scoreBps,
                null,
                "legacy-review-{$attempt->id}-{$review->id}",
            );
        }

        $attempt->refresh();

        return $service->finalize(
            $reviewer,
            $attempt->id,
            $attempt->revision,
            "legacy-finalize-{$attempt->id}",
        );
    }

    private function optionFixture(): array
    {
        return [
            ['id' => 'o1', 'label' => 'Option 1'],
            ['id' => 'o2', 'label' => 'Option 2'],
        ];
    }

    private function expectAssessmentException(string $code, int $status, callable $callback): void
    {
        try {
            $callback();
            $this->fail("Expected assessment exception {$code}.");
        } catch (LessonAssessmentException $exception) {
            $this->assertSame($code, $exception->errorCode);
            $this->assertSame($status, $exception->httpStatus);
            $this->assertStringNotContainsString('o1', $exception->getMessage());
            $this->assertStringNotContainsString('o2', $exception->getMessage());
        }
    }
}
