<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Lesson;
use App\Models\LessonAssessment;
use App\Models\LessonAssessmentAttempt;
use App\Models\LessonAssessmentAttemptResponse;
use App\Models\LessonAssessmentManualReview;
use App\Models\LessonAssessmentReviewEvent;
use App\Models\LessonAssessmentVersion;
use App\Models\LessonMaterial;
use App\Models\LessonMaterialFile;
use App\Models\ProviderEvent;
use App\Models\Subject;
use App\Models\User;
use App\Services\EntitlementGrantService;
use App\Services\Learning\LessonAssessmentAttemptService;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('pgsql')]
class PostgresDatabaseSemanticsTest extends TestCase
{
    private const HYBRID_POLICY = [
        'schemaVersion' => 2,
        'mode' => 'hybrid',
        'automaticWeightBps' => 7000,
        'manualWeightBps' => 3000,
        'automaticGateOverallRequiredBps' => 8000,
        'automaticGateSectionRequiredBps' => 6000,
        'manualRequiredBps' => 6000,
        'finalRequiredBps' => 8000,
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $connection = config('database.connections.pgsql');
        $this->assertSame('pgsql', config('database.default'));
        $this->assertSame('pgsql', $connection['driver'] ?? null);
        $this->assertSame('127.0.0.1', $connection['host'] ?? null);
        $this->assertSame('5432', (string) ($connection['port'] ?? ''));
        $this->assertSame('cms_test', $connection['database'] ?? null);
        $this->assertTrue(in_array($connection['url'] ?? null, [null, ''], true));

        $this->assertSame('pgsql', DB::connection()->getDriverName());
        $this->assertSame('cms_test', DB::connection()->getDatabaseName());
    }

    public function test_postgresql_server_and_test_database_are_explicit(): void
    {
        $version = DB::selectOne('SHOW server_version');

        $this->assertNotSame('', trim((string) $version->server_version));
        $this->assertSame('cms_test', DB::connection()->getDatabaseName());
    }

    public function test_protected_file_check_constraints_exist_and_reject_invalid_rows(): void
    {
        $names = collect(DB::select(<<<'SQL'
            SELECT conname
            FROM pg_constraint
            WHERE conname IN (
                'lesson_material_files_source_size_check',
                'protected_file_deliveries_status_check',
                'protected_file_deliveries_attempt_count_check',
                'protected_file_deliveries_output_size_check'
            )
            SQL))
            ->pluck('conname')
            ->sort()
            ->values()
            ->all();

        $this->assertSame([
            'lesson_material_files_source_size_check',
            'protected_file_deliveries_attempt_count_check',
            'protected_file_deliveries_output_size_check',
            'protected_file_deliveries_status_check',
        ], $names);

        $subject = Subject::query()->create([
            'slug' => 'pgsql-check-subject',
            'title' => 'PostgreSQL check subject',
            'sort_order' => 1,
            'is_active' => true,
        ]);
        $course = Course::query()->create([
            'subject_id' => $subject->id,
            'slug' => 'pgsql-check-course',
            'title' => 'PostgreSQL check course',
            'price_cents' => 1000,
            'sort_order' => 1,
            'is_active' => true,
        ]);
        $lesson = Lesson::query()->create([
            'course_id' => $course->id,
            'slug' => 'pgsql-check-lesson',
            'title' => 'PostgreSQL check lesson',
            'sort_order' => 1,
            'is_active' => true,
        ]);
        $material = LessonMaterial::query()->create([
            'lesson_id' => $lesson->id,
            'kind' => LessonMaterial::KIND_PDF,
            'title' => 'PostgreSQL protected file',
            'is_published' => true,
        ]);

        $this->assertCheckViolation(
            fn (): bool => DB::table('lesson_material_files')->insert([
                'lesson_material_id' => $material->id,
                'source_object_key' => 'protected/invalid.pdf',
                'source_sha256' => str_repeat('a', 64),
                'source_size_bytes' => 0,
                'source_mime_type' => 'application/pdf',
                'download_filename' => 'invalid.pdf',
                'created_at' => now(),
                'updated_at' => now(),
            ]),
            'lesson_material_files_source_size_check',
        );

        $source = LessonMaterialFile::query()->create([
            'lesson_material_id' => $material->id,
            'source_object_key' => 'protected/valid.pdf',
            'source_sha256' => str_repeat('b', 64),
            'source_size_bytes' => 1024,
            'source_mime_type' => 'application/pdf',
            'download_filename' => 'valid.pdf',
        ]);
        $user = User::factory()->create(['role' => 'user_no_purchase']);

        foreach ([
            ['constraint' => 'protected_file_deliveries_status_check', 'status' => 'unknown'],
            ['constraint' => 'protected_file_deliveries_attempt_count_check', 'attempt_count' => -1],
            ['constraint' => 'protected_file_deliveries_output_size_check', 'output_size_bytes' => 0],
        ] as $case) {
            $constraint = $case['constraint'];
            unset($case['constraint']);
            $this->assertCheckViolation(
                fn (): bool => DB::table('protected_file_deliveries')->insert([
                    'public_id' => (string) Str::uuid(),
                    'user_id' => $user->id,
                    'lesson_material_file_id' => $source->id,
                    'source_sha256' => $source->source_sha256,
                    'copy_code' => 'COPY-'.strtoupper(Str::random(16)),
                    'status' => 'pending',
                    'attempt_count' => 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                    ...$case,
                ]),
                $constraint,
            );
        }
    }

    public function test_provider_event_idempotency_is_enforced_by_postgresql(): void
    {
        ProviderEvent::query()->create([
            'provider' => 'pgsql-test',
            'provider_event_id' => 'duplicate-event',
        ]);

        $this->assertSqlStateViolation(static function (): void {
            ProviderEvent::query()->create([
                'provider' => 'pgsql-test',
                'provider_event_id' => 'duplicate-event',
            ]);
        }, '23505');
    }

    public function test_lesson_assessment_constraints_and_partial_unique_indexes_are_enforced(): void
    {
        $user = User::factory()->create(['role' => 'user_no_purchase']);
        $subject = Subject::query()->create([
            'slug' => 'pgsql-assessment-subject',
            'title' => 'PostgreSQL assessment subject',
            'sort_order' => 1,
            'is_active' => true,
        ]);
        $course = Course::query()->create([
            'subject_id' => $subject->id,
            'slug' => 'pgsql-assessment-course',
            'title' => 'PostgreSQL assessment course',
            'price_cents' => 1000,
            'sort_order' => 1,
            'is_active' => true,
        ]);
        $lesson = Lesson::query()->create([
            'course_id' => $course->id,
            'slug' => 'pgsql-assessment-lesson',
            'title' => 'PostgreSQL assessment lesson',
            'sort_order' => 1,
            'is_active' => true,
        ]);
        $assessment = LessonAssessment::query()->create(['lesson_id' => $lesson->id]);
        $now = now();
        $versionPayload = [
            'lesson_assessment_id' => $assessment->id,
            'status' => LessonAssessmentVersion::STATUS_DRAFT,
            'schema_version' => 2,
            'content_version' => 37,
            'scoring_policy' => json_encode(self::HYBRID_POLICY),
            'source_assignment_sha256' => str_repeat('a', 64),
            'source_key_sha256' => str_repeat('b', 64),
            'sections' => json_encode([]),
            'questions' => json_encode([]),
            'answer_key' => json_encode((object) []),
            'created_at' => $now,
            'updated_at' => $now,
        ];

        foreach ([
            ['constraint' => 'lesson_assessment_versions_status_check', 'status' => 'invalid'],
            ['constraint' => 'lesson_assessment_versions_sections_check', 'sections' => json_encode((object) [])],
            ['constraint' => 'lesson_assessment_versions_questions_check', 'questions' => json_encode((object) [])],
            ['constraint' => 'lesson_assessment_versions_answer_key_check', 'answer_key' => json_encode([])],
        ] as $case) {
            $constraint = $case['constraint'];
            unset($case['constraint']);

            $this->assertCheckViolation(
                fn (): bool => DB::table('lesson_assessment_versions')->insert([...$versionPayload, ...$case]),
                $constraint,
            );
        }

        $published = LessonAssessmentVersion::query()->forceCreate([
            'lesson_assessment_id' => $assessment->id,
            'status' => LessonAssessmentVersion::STATUS_PUBLISHED,
            'schema_version' => 2,
            'content_version' => 37,
            'scoring_policy' => self::HYBRID_POLICY,
            'source_assignment_sha256' => str_repeat('c', 64),
            'source_key_sha256' => str_repeat('d', 64),
            'sections' => [],
            'questions' => [],
            'answer_key' => (object) [],
            'published_at' => $now,
        ]);

        $this->assertSqlStateViolation(function () use ($versionPayload): void {
            DB::table('lesson_assessment_versions')->insert([
                ...$versionPayload,
                'status' => LessonAssessmentVersion::STATUS_PUBLISHED,
                'content_version' => 37,
            ]);
        }, '23505');

        $attemptPayload = [
            'user_id' => $user->id,
            'lesson_assessment_id' => $assessment->id,
            'lesson_assessment_version_id' => $published->id,
            'status' => LessonAssessmentAttempt::STATUS_IN_PROGRESS,
            'correct_count' => 0,
            'revision' => 0,
            'check_count' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ];

        $this->assertCheckViolation(
            fn (): bool => DB::table('lesson_assessment_attempts')->insert([...$attemptPayload, 'status' => 'invalid']),
            'lesson_assessment_attempts_status_check',
        );
        $this->assertCheckViolation(
            fn (): bool => DB::table('lesson_assessment_attempts')->insert([...$attemptPayload, 'correct_count' => -1]),
            'lesson_assessment_attempts_correct_count_check',
        );
        $this->assertTrue(DB::table('lesson_assessment_attempts')->insert([
            ...$attemptPayload,
            'status' => LessonAssessmentAttempt::STATUS_PASSED,
            'correct_count' => 49,
            'automatic_score' => json_encode(['percentBps' => 10000]),
            'manual_score' => json_encode(['percentBps' => 10000]),
            'final_score' => json_encode(['percentBps' => 10000]),
            'submitted_at' => $now,
            'finalized_at' => $now,
        ]));

        $attemptId = DB::table('lesson_assessment_attempts')->insertGetId($attemptPayload);

        $this->assertSqlStateViolation(
            fn (): bool => DB::table('lesson_assessment_attempts')->insert($attemptPayload),
            '23505',
        );
        $this->assertCheckViolation(
            fn (): bool => DB::table('lesson_assessment_attempt_responses')->insert([
                'lesson_assessment_attempt_id' => $attemptId,
                'question_id' => 'question-1',
                'response' => json_encode([]),
                'is_complete' => false,
                'is_locked' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ]),
            'lesson_assessment_attempt_responses_response_check',
        );
    }

    public function test_active_record_lookup_indexes_include_state_columns(): void
    {
        $indexes = collect(DB::select(<<<'SQL'
            SELECT indexname, indexdef
            FROM pg_indexes
            WHERE schemaname = 'public'
              AND indexname IN (
                  'lessons_course_id_is_active_sort_order_index',
                  'lesson_materials_manifest_index',
                  'content_categories_content_type_is_active_sort_order_index'
              )
            SQL))->keyBy('indexname');

        $this->assertStringContainsString('is_active', (string) $indexes->get('lessons_course_id_is_active_sort_order_index')?->indexdef);
        $this->assertStringContainsString('is_published', (string) $indexes->get('lesson_materials_manifest_index')?->indexdef);
        $this->assertStringContainsString('is_active', (string) $indexes->get('content_categories_content_type_is_active_sort_order_index')?->indexdef);
    }

    public function test_two_connections_serialize_order_row_for_update_locks(): void
    {
        $baseConfig = config('database.connections.pgsql');
        config([
            'database.connections.pgsql_lock_one' => $baseConfig,
            'database.connections.pgsql_lock_two' => $baseConfig,
        ]);
        DB::purge('pgsql_lock_one');
        DB::purge('pgsql_lock_two');

        $first = DB::connection('pgsql_lock_one');
        $second = DB::connection('pgsql_lock_two');
        $blocked = false;
        $orderId = null;

        try {
            $orderId = $first->table('orders')->insertGetId([
                'currency' => 'RUB',
                'amount_total' => 1000,
                'amount_discount' => 0,
                'amount_final' => 1000,
                'amount_paid' => 0,
                'amount_refunded' => 0,
                'payment_status' => 'draft',
                'access_status' => 'not_granted',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $first->beginTransaction();
            $first->table('orders')->where('id', $orderId)->lockForUpdate()->first();

            $second->beginTransaction();
            $second->statement("SET LOCAL lock_timeout = '150ms'");
            try {
                $second->table('orders')->where('id', $orderId)->lockForUpdate()->first();
            } catch (QueryException $exception) {
                $this->assertSame(
                    '55P03',
                    (string) ($exception->errorInfo[0] ?? $exception->getCode()),
                    'The competing query failed for a reason other than PostgreSQL lock timeout.',
                );
                $blocked = true;
            }
        } finally {
            if ($second->transactionLevel() > 0) {
                $second->rollBack();
            }
            if ($first->transactionLevel() > 0) {
                $first->rollBack();
            }
            if ($orderId !== null) {
                $first->table('orders')->where('id', $orderId)->delete();
            }
            DB::purge('pgsql_lock_one');
            DB::purge('pgsql_lock_two');
        }

        $this->assertTrue($blocked, 'The second connection acquired a row locked FOR UPDATE.');
    }

    public function test_atomic_batch_grant_rolls_back_on_late_conflict(): void
    {
        $user = User::factory()->create(['role' => 'user_no_purchase']);
        $subject = Subject::query()->create([
            'slug' => 'pgsql-entitlement-subject',
            'title' => 'PostgreSQL entitlement subject',
            'sort_order' => 1,
            'is_active' => true,
        ]);
        $course = Course::query()->create([
            'subject_id' => $subject->id,
            'slug' => 'pgsql-entitlement-course',
            'title' => 'PostgreSQL entitlement course',
            'price_cents' => 1000,
            'sort_order' => 1,
            'is_active' => true,
        ]);
        $lessons = collect([1, 2])->map(fn (int $position): Lesson => Lesson::query()->create([
            'course_id' => $course->id,
            'slug' => "pgsql-entitlement-lesson-{$position}",
            'title' => "PostgreSQL entitlement lesson {$position}",
            'sort_order' => $position,
            'is_active' => true,
        ]));
        $service = app(EntitlementGrantService::class);

        $service->grantAtoms(
            userId: $user->id,
            atoms: [['kind' => 'lesson', 'id' => $lessons[1]->id]],
            sourceId: 'existing-intent',
            idempotencyKey: 'pgsql-atomic-batch',
        );

        try {
            $service->grantAtoms(
                userId: $user->id,
                atoms: [
                    ['kind' => 'lesson', 'id' => $lessons[0]->id],
                    ['kind' => 'lesson', 'id' => $lessons[1]->id],
                ],
                sourceId: 'conflicting-intent',
                idempotencyKey: 'pgsql-atomic-batch',
            );
            $this->fail('The conflicting batch was accepted.');
        } catch (\InvalidArgumentException) {
            $this->assertDatabaseCount('user_entitlement_grants', 1);
            $this->assertDatabaseMissing('user_entitlement_grants', [
                'user_id' => $user->id,
                'atom_id' => $lessons[0]->id,
                'idempotency_key' => 'pgsql-atomic-batch',
            ]);
        }
    }

    public function test_lesson_assessment_review_events_are_append_only_in_the_model_and_postgresql(): void
    {
        $user = User::factory()->create(['role' => 'user_no_purchase']);
        $subject = Subject::query()->create(['slug' => 'pgsql-review-subject', 'title' => 'Subject', 'sort_order' => 1, 'is_active' => true]);
        $course = Course::query()->create(['subject_id' => $subject->id, 'slug' => 'pgsql-review-course', 'title' => 'Course', 'price_cents' => 0, 'sort_order' => 1, 'is_active' => true]);
        $lesson = Lesson::query()->create(['course_id' => $course->id, 'slug' => 'pgsql-review-lesson', 'title' => 'Lesson', 'sort_order' => 1, 'is_active' => true]);
        $assessment = LessonAssessment::query()->create(['lesson_id' => $lesson->id]);
        $version = new LessonAssessmentVersion([
            'lesson_assessment_id' => $assessment->id,
            'schema_version' => 2,
            'content_version' => 37,
            'scoring_policy' => self::HYBRID_POLICY,
            'source_assignment_sha256' => str_repeat('a', 64),
            'source_key_sha256' => str_repeat('b', 64),
            'sections' => [],
            'questions' => [],
            'answer_key' => ['answers' => []],
        ]);
        $version->status = LessonAssessmentVersion::STATUS_DRAFT;
        $version->save();
        $attempt = LessonAssessmentAttempt::query()->forceCreate([
            'user_id' => $user->id,
            'lesson_assessment_id' => $assessment->id,
            'lesson_assessment_version_id' => $version->id,
            'status' => LessonAssessmentAttempt::STATUS_IN_PROGRESS,
        ]);
        $response = LessonAssessmentAttemptResponse::query()->create([
            'lesson_assessment_attempt_id' => $attempt->id,
            'question_id' => 'manual-question',
            'response' => ['values' => []],
        ]);
        $unreviewedResponse = LessonAssessmentAttemptResponse::query()->create([
            'lesson_assessment_attempt_id' => $attempt->id,
            'question_id' => 'cross-attempt-question',
            'response' => ['values' => []],
        ]);
        $review = LessonAssessmentManualReview::query()->create([
            'lesson_assessment_attempt_id' => $attempt->id,
            'lesson_assessment_attempt_response_id' => $response->id,
            'question_id' => 'manual-question',
            'response_snapshot' => ['values' => []],
            'response_snapshot_sha256' => str_repeat('c', 64),
            'rubric_snapshot' => ['criteria' => []],
            'rubric_snapshot_sha256' => str_repeat('d', 64),
        ]);
        $otherAttempt = LessonAssessmentAttempt::query()->forceCreate([
            'user_id' => User::factory()->create(['role' => 'user_no_purchase'])->id,
            'lesson_assessment_id' => $assessment->id,
            'lesson_assessment_version_id' => $version->id,
            'status' => LessonAssessmentAttempt::STATUS_IN_PROGRESS,
        ]);

        $this->assertSqlStateViolation(
            fn (): bool => DB::table('lesson_assessment_manual_reviews')->insert([
                'lesson_assessment_attempt_id' => $otherAttempt->id,
                'lesson_assessment_attempt_response_id' => $unreviewedResponse->id,
                'question_id' => 'cross-attempt-question',
                'status' => LessonAssessmentManualReview::STATUS_PENDING,
                'response_snapshot' => json_encode(['values' => []]),
                'response_snapshot_sha256' => str_repeat('e', 64),
                'rubric_snapshot' => json_encode(['criteria' => []]),
                'rubric_snapshot_sha256' => str_repeat('f', 64),
                'created_at' => now(),
                'updated_at' => now(),
            ]),
            '23503',
        );
        $event = LessonAssessmentReviewEvent::query()->create([
            'lesson_assessment_attempt_id' => $attempt->id,
            'lesson_assessment_manual_review_id' => $review->id,
            'actor_id' => $user->id,
            'event_type' => 'review_updated',
            'previous_review_score_bps' => null,
            'review_score_bps' => 8000,
            'comment_changed' => false,
            'previous_attempt_status' => LessonAssessmentAttempt::STATUS_PENDING_REVIEW,
            'attempt_status' => LessonAssessmentAttempt::STATUS_PENDING_REVIEW,
            'revision' => 0,
            'idempotency_key_hash' => hash('sha256', 'not-plaintext'),
            'request_fingerprint_sha256' => hash('sha256', 'review-score-8000'),
            'created_at' => now(),
        ]);

        $this->assertSame($attempt->id, $event->attempt->id);
        $this->assertSame($review->id, $event->review->id);
        $this->assertSqlStateViolation(
            fn (): bool => DB::table('lesson_assessment_review_events')->insert([
                'lesson_assessment_attempt_id' => $attempt->id,
                'lesson_assessment_manual_review_id' => $review->id,
                'actor_id' => User::query()->max('id') + 1000,
                'event_type' => 'review_updated',
                'review_score_bps' => 8000,
                'comment_changed' => false,
                'previous_attempt_status' => LessonAssessmentAttempt::STATUS_PENDING_REVIEW,
                'attempt_status' => LessonAssessmentAttempt::STATUS_PENDING_REVIEW,
                'revision' => 0,
                'idempotency_key_hash' => hash('sha256', 'unknown-review-actor'),
                'request_fingerprint_sha256' => hash('sha256', 'unknown-review-actor-score-8000'),
                'created_at' => now(),
            ]),
            '23503',
        );
        $this->assertSqlStateViolation(
            fn (): bool => DB::table('lesson_assessment_review_events')->insert([
                'lesson_assessment_attempt_id' => $attempt->id,
                'lesson_assessment_manual_review_id' => $review->id,
                'actor_id' => $user->id,
                'event_type' => 'review_updated',
                'review_score_bps' => 9000,
                'comment_changed' => true,
                'previous_attempt_status' => LessonAssessmentAttempt::STATUS_PENDING_REVIEW,
                'attempt_status' => LessonAssessmentAttempt::STATUS_PENDING_REVIEW,
                'revision' => 1,
                'idempotency_key_hash' => $event->idempotency_key_hash,
                'request_fingerprint_sha256' => hash('sha256', 'review-score-9000'),
                'created_at' => now(),
            ]),
            '23505',
        );

        $finalization = LessonAssessmentReviewEvent::query()->create([
            'lesson_assessment_attempt_id' => $attempt->id,
            'actor_id' => $user->id,
            'event_type' => 'finalized',
            'comment_changed' => false,
            'previous_attempt_status' => LessonAssessmentAttempt::STATUS_PENDING_REVIEW,
            'attempt_status' => LessonAssessmentAttempt::STATUS_PASSED,
            'automatic_bps' => 8000,
            'manual_bps' => 8000,
            'final_bps' => 8000,
            'revision' => 1,
            'idempotency_key_hash' => hash('sha256', 'finalize-not-plaintext'),
            'request_fingerprint_sha256' => hash('sha256', 'finalize-8000'),
            'created_at' => now(),
        ]);
        $this->assertNull($finalization->lesson_assessment_manual_review_id);
        $this->assertSqlStateViolation(
            fn (): bool => DB::table('lesson_assessment_review_events')->insert([
                'lesson_assessment_attempt_id' => $attempt->id,
                'actor_id' => $user->id,
                'event_type' => 'finalized',
                'comment_changed' => false,
                'previous_attempt_status' => LessonAssessmentAttempt::STATUS_PENDING_REVIEW,
                'attempt_status' => LessonAssessmentAttempt::STATUS_PASSED,
                'automatic_bps' => 8000,
                'manual_bps' => 8000,
                'final_bps' => 8000,
                'revision' => 1,
                'idempotency_key_hash' => $finalization->idempotency_key_hash,
                'request_fingerprint_sha256' => hash('sha256', 'different-finalize-payload'),
                'created_at' => now(),
            ]),
            '23505',
        );

        $this->assertSqlStateViolation(
            fn (): int => DB::table('lesson_assessment_review_events')->where('id', $event->id)->update(['revision' => 1]),
            'P0001',
        );
        $this->assertSqlStateViolation(
            fn (): int => DB::table('lesson_assessment_review_events')->where('id', $event->id)->delete(),
            'P0001',
        );
        $this->assertSqlStateViolation(
            fn () => DB::table('lesson_assessment_review_events')->truncate(),
            'P0001',
        );

        $this->expectException(\LogicException::class);
        $event->revision = 1;
        $event->save();
    }

    public function test_local_assessment_reset_removes_only_target_review_graph_and_restores_append_only_trigger(): void
    {
        $suffix = strtolower((string) Str::ulid());
        $student = User::factory()->create(['email' => 'all@test.test', 'role' => 'user_with_purchase']);
        $otherStudent = User::factory()->create(['role' => 'user_with_purchase']);
        $actor = User::factory()->create(['role' => 'admin_full']);
        $subject = Subject::query()->create([
            'slug' => "pgsql-reset-subject-{$suffix}",
            'title' => 'Reset subject',
            'sort_order' => 1,
            'is_active' => true,
        ]);
        $course = Course::query()->create([
            'subject_id' => $subject->id,
            'slug' => "pgsql-reset-course-{$suffix}",
            'title' => 'Reset course',
            'price_cents' => 0,
            'sort_order' => 1,
            'is_active' => true,
        ]);
        $lesson = Lesson::query()->create([
            'course_id' => $course->id,
            'slug' => "pgsql-reset-lesson-{$suffix}",
            'title' => 'Reset lesson',
            'sort_order' => 1,
            'is_active' => true,
        ]);
        $assessment = LessonAssessment::query()->create(['lesson_id' => $lesson->id]);
        $version = LessonAssessmentVersion::query()->forceCreate([
            'lesson_assessment_id' => $assessment->id,
            'status' => LessonAssessmentVersion::STATUS_DRAFT,
            'schema_version' => 2,
            'content_version' => 37,
            'scoring_policy' => self::HYBRID_POLICY,
            'source_assignment_sha256' => str_repeat('1', 64),
            'source_key_sha256' => str_repeat('2', 64),
            'sections' => [],
            'questions' => [],
            'answer_key' => (object) [],
        ]);

        [$attempt, $review, $event] = $this->createPendingReviewGraph(
            $student,
            $actor,
            $assessment,
            $version,
            'target',
        );
        [$otherAttempt, $otherReview, $otherEvent] = $this->createPendingReviewGraph(
            $otherStudent,
            $actor,
            $assessment,
            $version,
            'other',
        );

        $originalDatabase = config('local_development.dev_profiles.database.database');
        try {
            config()->set('local_development.dev_profiles.database.database', 'cms_test');
            $this->assertTrue(app(LessonAssessmentAttemptService::class)->resetDevelopmentState($student, $assessment));
        } finally {
            config()->set('local_development.dev_profiles.database.database', $originalDatabase);
        }

        $this->assertDatabaseMissing('lesson_assessment_attempts', ['id' => $attempt->id]);
        $this->assertDatabaseMissing('lesson_assessment_manual_reviews', ['id' => $review->id]);
        $this->assertDatabaseMissing('lesson_assessment_review_events', ['id' => $event->id]);
        $this->assertDatabaseHas('lesson_assessment_attempts', ['id' => $otherAttempt->id]);
        $this->assertDatabaseHas('lesson_assessment_manual_reviews', ['id' => $otherReview->id]);
        $this->assertDatabaseHas('lesson_assessment_review_events', ['id' => $otherEvent->id]);

        $this->assertSqlStateViolation(
            fn (): int => DB::table('lesson_assessment_review_events')
                ->where('id', $otherEvent->id)
                ->delete(),
            'P0001',
        );
    }

    /** @return array{LessonAssessmentAttempt, LessonAssessmentManualReview, LessonAssessmentReviewEvent} */
    private function createPendingReviewGraph(
        User $student,
        User $actor,
        LessonAssessment $assessment,
        LessonAssessmentVersion $version,
        string $key,
    ): array {
        $attempt = LessonAssessmentAttempt::query()->forceCreate([
            'user_id' => $student->id,
            'lesson_assessment_id' => $assessment->id,
            'lesson_assessment_version_id' => $version->id,
            'status' => LessonAssessmentAttempt::STATUS_PENDING_REVIEW,
            'submitted_at' => now(),
            'automatic_score' => ['passed' => true, 'overall' => ['percentBps' => 10000]],
        ]);
        $response = LessonAssessmentAttemptResponse::query()->create([
            'lesson_assessment_attempt_id' => $attempt->id,
            'question_id' => "manual-{$key}",
            'response' => ['text' => $key],
            'is_complete' => true,
            'is_locked' => true,
        ]);
        $review = LessonAssessmentManualReview::query()->create([
            'lesson_assessment_attempt_id' => $attempt->id,
            'lesson_assessment_attempt_response_id' => $response->id,
            'question_id' => "manual-{$key}",
            'response_snapshot' => ['text' => $key],
            'response_snapshot_sha256' => hash('sha256', $key),
            'rubric_snapshot' => ['expectedAnswer' => ['text' => $key]],
            'rubric_snapshot_sha256' => hash('sha256', "rubric-{$key}"),
        ]);
        $event = LessonAssessmentReviewEvent::query()->create([
            'lesson_assessment_attempt_id' => $attempt->id,
            'lesson_assessment_manual_review_id' => $review->id,
            'actor_id' => $actor->id,
            'event_type' => 'review_updated',
            'previous_review_score_bps' => null,
            'review_score_bps' => 8000,
            'comment_changed' => false,
            'previous_attempt_status' => LessonAssessmentAttempt::STATUS_PENDING_REVIEW,
            'attempt_status' => LessonAssessmentAttempt::STATUS_PENDING_REVIEW,
            'revision' => 1,
            'idempotency_key_hash' => hash('sha256', "reset-{$key}"),
            'request_fingerprint_sha256' => hash('sha256', "reset-fingerprint-{$key}"),
            'created_at' => now(),
        ]);

        return [$attempt, $review, $event];
    }

    private function assertCheckViolation(Closure $operation, string $constraint): void
    {
        try {
            DB::transaction($operation);
        } catch (QueryException $exception) {
            $this->assertSame('23514', (string) ($exception->errorInfo[0] ?? $exception->getCode()));
            $this->assertStringContainsString($constraint, $exception->getMessage());

            return;
        }

        $this->fail("PostgreSQL accepted a row violating {$constraint}.");
    }

    private function assertSqlStateViolation(Closure $operation, string $sqlState): void
    {
        try {
            DB::transaction($operation);
        } catch (QueryException $exception) {
            $this->assertSame($sqlState, (string) ($exception->errorInfo[0] ?? $exception->getCode()));

            return;
        }

        $this->fail("PostgreSQL accepted an operation expected to fail with SQLSTATE {$sqlState}.");
    }
}
