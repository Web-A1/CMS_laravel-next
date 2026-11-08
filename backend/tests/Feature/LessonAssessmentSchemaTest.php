<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('pgsql')]
class LessonAssessmentSchemaTest extends TestCase
{
    public function test_lesson_assessment_schema_has_required_tables_constraints_and_indexes(): void
    {
        foreach ([
            'lesson_assessments',
            'lesson_assessment_versions',
            'lesson_assessment_assets',
            'lesson_assessment_attempts',
            'lesson_assessment_attempt_responses',
            'lesson_assessment_manual_reviews',
            'lesson_assessment_review_events',
            'lesson_assessment_completions',
        ] as $table) {
            $this->assertTrue(Schema::hasTable($table));
        }

        $this->assertTrue(Schema::hasColumns('lesson_assessment_versions', [
            'sections',
            'questions',
            'answer_key',
            'scoring_policy',
        ]));

        $foreignKeys = collect(DB::select(<<<'SQL'
            SELECT conrelid::regclass::text AS table_name, pg_get_constraintdef(oid) AS definition
            FROM pg_constraint
            WHERE contype = 'f'
              AND conrelid::regclass::text IN (
                  'lesson_assessments',
                  'lesson_assessment_versions',
                  'lesson_assessment_assets',
                  'lesson_assessment_attempts',
                  'lesson_assessment_attempt_responses',
                  'lesson_assessment_manual_reviews',
                  'lesson_assessment_review_events',
                  'lesson_assessment_completions'
              )
            SQL))
            ->groupBy('table_name');

        foreach ([
            ['table' => 'lesson_assessments', 'definition' => 'FOREIGN KEY (lesson_id) REFERENCES lessons(id)'],
            ['table' => 'lesson_assessment_versions', 'definition' => 'FOREIGN KEY (lesson_assessment_id) REFERENCES lesson_assessments(id)'],
            ['table' => 'lesson_assessment_assets', 'definition' => 'FOREIGN KEY (lesson_assessment_version_id) REFERENCES lesson_assessment_versions(id)'],
            ['table' => 'lesson_assessment_attempts', 'definition' => 'FOREIGN KEY (user_id) REFERENCES users(id)'],
            ['table' => 'lesson_assessment_attempts', 'definition' => 'FOREIGN KEY (lesson_assessment_id) REFERENCES lesson_assessments(id)'],
            ['table' => 'lesson_assessment_attempts', 'definition' => 'FOREIGN KEY (lesson_assessment_version_id) REFERENCES lesson_assessment_versions(id)'],
            ['table' => 'lesson_assessment_attempt_responses', 'definition' => 'FOREIGN KEY (lesson_assessment_attempt_id) REFERENCES lesson_assessment_attempts(id)'],
            ['table' => 'lesson_assessment_manual_reviews', 'definition' => 'FOREIGN KEY (lesson_assessment_attempt_id) REFERENCES lesson_assessment_attempts(id)'],
            ['table' => 'lesson_assessment_manual_reviews', 'definition' => 'FOREIGN KEY (lesson_assessment_attempt_response_id) REFERENCES lesson_assessment_attempt_responses(id)'],
            ['table' => 'lesson_assessment_manual_reviews', 'definition' => 'FOREIGN KEY (lesson_assessment_attempt_response_id, lesson_assessment_attempt_id) REFERENCES lesson_assessment_attempt_responses(id, lesson_assessment_attempt_id)'],
            ['table' => 'lesson_assessment_review_events', 'definition' => 'FOREIGN KEY (actor_id) REFERENCES users(id)'],
            ['table' => 'lesson_assessment_completions', 'definition' => 'FOREIGN KEY (user_id) REFERENCES users(id)'],
            ['table' => 'lesson_assessment_completions', 'definition' => 'FOREIGN KEY (lesson_id) REFERENCES lessons(id)'],
            ['table' => 'lesson_assessment_completions', 'definition' => 'FOREIGN KEY (lesson_assessment_id) REFERENCES lesson_assessments(id)'],
            ['table' => 'lesson_assessment_completions', 'definition' => 'FOREIGN KEY (passed_attempt_id) REFERENCES lesson_assessment_attempts(id)'],
        ] as $foreignKey) {
            $table = $foreignKey['table'];
            $definition = $foreignKey['definition'];

            $this->assertTrue(
                $foreignKeys->get($table, collect())->pluck('definition')->contains(
                    fn (string $actual): bool => str_contains($actual, $definition),
                ),
                "The {$table} foreign key is missing.",
            );
        }

        // Retention keeps historical attempt/review IDs after account erasure.
        $this->assertTrue(Schema::hasColumns('lesson_assessment_review_events', [
            'lesson_assessment_attempt_id', 'lesson_assessment_manual_review_id',
        ]));
        $this->assertFalse(collect(Schema::getForeignKeys('lesson_assessment_review_events'))
            ->contains(fn (array $foreign): bool => array_intersect($foreign['columns'], [
                'lesson_assessment_attempt_id', 'lesson_assessment_manual_review_id',
            ]) !== []));

        $indexes = collect(DB::select(<<<'SQL'
            SELECT indexname, indexdef
            FROM pg_indexes
            WHERE schemaname = 'public'
            SQL))->keyBy('indexname');

        foreach ([
            'lesson_assessment_versions_assessment_content_version_unique' => 'UNIQUE',
            'lesson_assessment_attempt_responses_attempt_question_unique' => 'UNIQUE',
            'lesson_assessment_attempt_responses_id_attempt_unique' => 'UNIQUE',
            'lesson_assessment_completions_user_lesson_unique' => 'UNIQUE',
            'lesson_assessment_manual_reviews_response_unique' => 'UNIQUE',
            'lesson_assessment_manual_reviews_id_attempt_unique' => 'UNIQUE',
            'lesson_assessment_review_events_review_idempotency_unique' => 'UNIQUE',
            'lesson_assessment_review_events_finalization_idempotency_unique' => 'UNIQUE',
        ] as $name => $fragment) {
            $this->assertStringContainsString($fragment, (string) $indexes->get($name)?->indexdef);
        }

        $checks = collect(DB::select(<<<'SQL'
            SELECT conname, pg_get_constraintdef(oid) AS definition
            FROM pg_constraint
            WHERE conname IN (
                'lesson_assessment_versions_status_check',
                'lesson_assessment_versions_sections_check',
                'lesson_assessment_versions_questions_check',
                'lesson_assessment_versions_answer_key_check',
                'lesson_assessment_attempts_status_check',
                'lesson_assessment_attempts_correct_count_check',
                'lesson_assessment_attempt_responses_response_check'
                , 'lesson_assessment_versions_scoring_policy_check'
                , 'lesson_assessment_attempts_score_snapshot_check'
                , 'lesson_assessment_attempts_failure_reason_check'
                , 'lesson_assessment_attempts_state_snapshot_check'
                , 'lesson_assessment_attempt_responses_revision_check'
                , 'lesson_assessment_manual_reviews_status_check'
                , 'lesson_assessment_manual_reviews_snapshot_check'
                , 'lesson_assessment_manual_reviews_score_check'
                , 'lesson_assessment_manual_reviews_comment_check'
                , 'lesson_assessment_manual_reviews_state_check'
                , 'lesson_assessment_manual_reviews_revision_check'
                , 'lesson_assessment_review_events_type_check'
                , 'lesson_assessment_review_events_score_check'
                , 'lesson_assessment_review_events_state_check'
                , 'lesson_assessment_review_events_revision_check'
                , 'lesson_assessment_versions_schema_version_check'
                , 'lesson_assessment_versions_content_version_check'
            )
            SQL))->keyBy('conname');

        $this->assertSame([
            'lesson_assessment_attempt_responses_response_check',
            'lesson_assessment_attempt_responses_revision_check',
            'lesson_assessment_attempts_correct_count_check',
            'lesson_assessment_attempts_failure_reason_check',
            'lesson_assessment_attempts_score_snapshot_check',
            'lesson_assessment_attempts_state_snapshot_check',
            'lesson_assessment_attempts_status_check',
            'lesson_assessment_manual_reviews_comment_check',
            'lesson_assessment_manual_reviews_revision_check',
            'lesson_assessment_manual_reviews_score_check',
            'lesson_assessment_manual_reviews_snapshot_check',
            'lesson_assessment_manual_reviews_state_check',
            'lesson_assessment_manual_reviews_status_check',
            'lesson_assessment_review_events_revision_check',
            'lesson_assessment_review_events_score_check',
            'lesson_assessment_review_events_state_check',
            'lesson_assessment_review_events_type_check',
            'lesson_assessment_versions_answer_key_check',
            'lesson_assessment_versions_content_version_check',
            'lesson_assessment_versions_questions_check',
            'lesson_assessment_versions_schema_version_check',
            'lesson_assessment_versions_scoring_policy_check',
            'lesson_assessment_versions_sections_check',
            'lesson_assessment_versions_status_check',
        ], $checks->keys()->sort()->values()->all());

        $this->assertStringContainsString(
            "'failed'::character varying",
            (string) $checks->get('lesson_assessment_attempts_status_check')?->definition,
        );

        $this->assertStringContainsString(
            "WHERE ((status)::text = 'published'::text)",
            (string) $indexes->get('lesson_assessment_versions_one_published_unique')?->indexdef,
        );
        $this->assertStringContainsString(
            "WHERE ((status)::text = 'in_progress'::text)",
            (string) $indexes->get('lesson_assessment_attempts_one_active_unique')?->indexdef,
        );
    }
}
