<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (
            DB::table('lesson_assessment_versions')->exists()
            || DB::table('lesson_assessment_attempts')->exists()
        ) {
            throw new RuntimeException(
                'Obsolete lesson assessment rows require the separately authorized local-only assessment reset before schema v2/content v37 can be installed.',
            );
        }

        Schema::table('lesson_assessment_versions', function (Blueprint $table): void {
            $table->jsonb('scoring_policy')->after('content_version');
        });

        Schema::table('lesson_assessment_attempts', function (Blueprint $table): void {
            $table->jsonb('automatic_score')->nullable()->after('correct_count');
            $table->jsonb('manual_score')->nullable()->after('automatic_score');
            $table->jsonb('final_score')->nullable()->after('manual_score');
            $table->timestampTz('submitted_at')->nullable()->after('checked_at');
            $table->timestampTz('finalized_at')->nullable()->after('submitted_at');
            $table->string('failure_reason', 40)->nullable()->after('finalized_at');
        });

        Schema::table('lesson_assessment_attempt_responses', function (Blueprint $table): void {
            $table->unsignedInteger('revision')->default(0)->after('is_locked');
            $table->unique(
                ['id', 'lesson_assessment_attempt_id'],
                'lesson_assessment_attempt_responses_id_attempt_unique',
            );
        });

        Schema::create('lesson_assessment_manual_reviews', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('lesson_assessment_attempt_id')
                ->constrained('lesson_assessment_attempts')
                ->restrictOnDelete();
            $table->foreignId('lesson_assessment_attempt_response_id')
                ->constrained('lesson_assessment_attempt_responses')
                ->restrictOnDelete();
            $table->foreign(
                ['lesson_assessment_attempt_response_id', 'lesson_assessment_attempt_id'],
                'lesson_assessment_manual_reviews_response_attempt_foreign',
            )
                ->references(['id', 'lesson_assessment_attempt_id'])
                ->on('lesson_assessment_attempt_responses')
                ->restrictOnDelete();
            $table->string('question_id');
            $table->string('status', 20)->default('pending');
            $table->jsonb('response_snapshot');
            $table->char('response_snapshot_sha256', 64);
            $table->jsonb('rubric_snapshot');
            $table->char('rubric_snapshot_sha256', 64);
            $table->unsignedSmallInteger('score_bps')->nullable();
            $table->text('comment')->nullable();
            $table->foreignId('reviewer_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->unsignedInteger('revision')->default(0);
            $table->timestampTz('decided_at')->nullable();
            $table->timestampsTz();

            $table->unique('lesson_assessment_attempt_response_id', 'lesson_assessment_manual_reviews_response_unique');
            $table->unique(
                ['id', 'lesson_assessment_attempt_id'],
                'lesson_assessment_manual_reviews_id_attempt_unique',
            );
            $table->index(['lesson_assessment_attempt_id', 'status'], 'lesson_assessment_manual_reviews_attempt_status_index');
        });

        Schema::create('lesson_assessment_review_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('lesson_assessment_attempt_id')
                ->constrained('lesson_assessment_attempts')
                ->restrictOnDelete();
            $table->foreignId('lesson_assessment_manual_review_id')
                ->nullable()
                ->constrained('lesson_assessment_manual_reviews')
                ->restrictOnDelete();
            $table->foreign(
                ['lesson_assessment_manual_review_id', 'lesson_assessment_attempt_id'],
                'lesson_assessment_review_events_review_attempt_foreign',
            )
                ->references(['id', 'lesson_assessment_attempt_id'])
                ->on('lesson_assessment_manual_reviews')
                ->restrictOnDelete();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->string('event_type', 30);
            $table->unsignedSmallInteger('previous_review_score_bps')->nullable();
            $table->unsignedSmallInteger('review_score_bps')->nullable();
            $table->boolean('comment_changed')->default(false);
            $table->string('previous_attempt_status', 20);
            $table->string('attempt_status', 20);
            $table->unsignedSmallInteger('automatic_bps')->nullable();
            $table->unsignedSmallInteger('manual_bps')->nullable();
            $table->unsignedSmallInteger('final_bps')->nullable();
            $table->string('failure_reason', 40)->nullable();
            $table->unsignedInteger('revision');
            $table->char('idempotency_key_hash', 64);
            $table->char('request_fingerprint_sha256', 64);
            $table->timestampTz('created_at');

            $table->index('lesson_assessment_attempt_id', 'lesson_assessment_review_events_attempt_index');
            $table->index('lesson_assessment_manual_review_id', 'lesson_assessment_review_events_review_index');
        });

        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE lesson_assessment_attempts DROP CONSTRAINT lesson_assessment_attempts_status_check');
        DB::statement('ALTER TABLE lesson_assessment_versions DROP CONSTRAINT lesson_assessment_versions_schema_version_check');
        DB::statement('ALTER TABLE lesson_assessment_versions DROP CONSTRAINT lesson_assessment_versions_content_version_check');
        DB::statement('ALTER TABLE lesson_assessment_versions ADD CONSTRAINT lesson_assessment_versions_schema_version_check CHECK (schema_version = 2)');
        DB::statement('ALTER TABLE lesson_assessment_versions ADD CONSTRAINT lesson_assessment_versions_content_version_check CHECK (content_version > 0)');
        DB::statement("ALTER TABLE lesson_assessment_attempts ADD CONSTRAINT lesson_assessment_attempts_status_check CHECK (status IN ('in_progress', 'pending_review', 'failed', 'passed'))");
        DB::statement("ALTER TABLE lesson_assessment_versions ADD CONSTRAINT lesson_assessment_versions_scoring_policy_check CHECK (scoring_policy = '{\"schemaVersion\":2,\"mode\":\"hybrid\",\"automaticWeightBps\":7000,\"manualWeightBps\":3000,\"automaticGateOverallRequiredBps\":8000,\"automaticGateSectionRequiredBps\":6000,\"manualRequiredBps\":6000,\"finalRequiredBps\":8000}'::jsonb)");
        DB::statement("ALTER TABLE lesson_assessment_attempts ADD CONSTRAINT lesson_assessment_attempts_score_snapshot_check CHECK ((automatic_score IS NULL OR jsonb_typeof(automatic_score) = 'object') AND (manual_score IS NULL OR jsonb_typeof(manual_score) = 'object') AND (final_score IS NULL OR jsonb_typeof(final_score) = 'object'))");
        DB::statement("ALTER TABLE lesson_assessment_attempts ADD CONSTRAINT lesson_assessment_attempts_failure_reason_check CHECK (failure_reason IS NULL OR failure_reason IN ('automatic_threshold_not_met', 'manual_threshold_not_met', 'combined_threshold_not_met'))");
        DB::statement("ALTER TABLE lesson_assessment_attempts ADD CONSTRAINT lesson_assessment_attempts_state_snapshot_check CHECK (status NOT IN ('in_progress', 'pending_review', 'failed', 'passed') OR (status = 'in_progress' AND submitted_at IS NULL AND finalized_at IS NULL AND automatic_score IS NULL AND manual_score IS NULL AND final_score IS NULL AND failure_reason IS NULL) OR (status = 'pending_review' AND submitted_at IS NOT NULL AND finalized_at IS NULL AND automatic_score IS NOT NULL AND manual_score IS NULL AND final_score IS NULL AND failure_reason IS NULL) OR (status = 'passed' AND submitted_at IS NOT NULL AND finalized_at IS NOT NULL AND automatic_score IS NOT NULL AND manual_score IS NOT NULL AND final_score IS NOT NULL AND failure_reason IS NULL) OR (status = 'failed' AND submitted_at IS NOT NULL AND automatic_score IS NOT NULL AND ((failure_reason = 'automatic_threshold_not_met' AND manual_score IS NULL AND final_score IS NULL AND finalized_at IS NULL) OR (failure_reason IN ('manual_threshold_not_met', 'combined_threshold_not_met') AND manual_score IS NOT NULL AND final_score IS NOT NULL AND finalized_at IS NOT NULL))))");
        DB::statement('ALTER TABLE lesson_assessment_attempt_responses ADD CONSTRAINT lesson_assessment_attempt_responses_revision_check CHECK (revision >= 0)');
        DB::statement("ALTER TABLE lesson_assessment_manual_reviews ADD CONSTRAINT lesson_assessment_manual_reviews_status_check CHECK (status IN ('pending', 'decided'))");
        DB::statement("ALTER TABLE lesson_assessment_manual_reviews ADD CONSTRAINT lesson_assessment_manual_reviews_snapshot_check CHECK (jsonb_typeof(response_snapshot) = 'object' AND jsonb_typeof(rubric_snapshot) = 'object')");
        DB::statement('ALTER TABLE lesson_assessment_manual_reviews ADD CONSTRAINT lesson_assessment_manual_reviews_score_check CHECK (score_bps BETWEEN 0 AND 10000 OR score_bps IS NULL)');
        DB::statement('ALTER TABLE lesson_assessment_manual_reviews ADD CONSTRAINT lesson_assessment_manual_reviews_comment_check CHECK (comment IS NULL OR (length(trim(comment)) > 0 AND char_length(comment) <= 2000))');
        DB::statement("ALTER TABLE lesson_assessment_manual_reviews ADD CONSTRAINT lesson_assessment_manual_reviews_state_check CHECK ((status = 'pending' AND score_bps IS NULL AND reviewer_id IS NULL AND decided_at IS NULL) OR (status = 'decided' AND score_bps IS NOT NULL AND reviewer_id IS NOT NULL AND decided_at IS NOT NULL))");
        DB::statement('ALTER TABLE lesson_assessment_manual_reviews ADD CONSTRAINT lesson_assessment_manual_reviews_revision_check CHECK (revision >= 0)');
        DB::statement("ALTER TABLE lesson_assessment_review_events ADD CONSTRAINT lesson_assessment_review_events_type_check CHECK (event_type IN ('review_updated', 'finalized'))");
        DB::statement('ALTER TABLE lesson_assessment_review_events ADD CONSTRAINT lesson_assessment_review_events_score_check CHECK ((previous_review_score_bps BETWEEN 0 AND 10000 OR previous_review_score_bps IS NULL) AND (review_score_bps BETWEEN 0 AND 10000 OR review_score_bps IS NULL) AND (automatic_bps BETWEEN 0 AND 10000 OR automatic_bps IS NULL) AND (manual_bps BETWEEN 0 AND 10000 OR manual_bps IS NULL) AND (final_bps BETWEEN 0 AND 10000 OR final_bps IS NULL))');
        DB::statement("ALTER TABLE lesson_assessment_review_events ADD CONSTRAINT lesson_assessment_review_events_state_check CHECK ((event_type = 'review_updated' AND lesson_assessment_manual_review_id IS NOT NULL AND previous_attempt_status = 'pending_review' AND attempt_status = 'pending_review' AND automatic_bps IS NULL AND manual_bps IS NULL AND final_bps IS NULL AND failure_reason IS NULL) OR (event_type = 'finalized' AND lesson_assessment_manual_review_id IS NULL AND previous_attempt_status = 'pending_review' AND attempt_status IN ('failed', 'passed') AND automatic_bps IS NOT NULL AND manual_bps IS NOT NULL AND final_bps IS NOT NULL AND (failure_reason IS NULL OR failure_reason IN ('manual_threshold_not_met', 'combined_threshold_not_met'))))");
        DB::statement('ALTER TABLE lesson_assessment_review_events ADD CONSTRAINT lesson_assessment_review_events_revision_check CHECK (revision >= 0)');
        DB::statement('CREATE UNIQUE INDEX lesson_assessment_review_events_review_idempotency_unique ON lesson_assessment_review_events (lesson_assessment_manual_review_id, event_type, idempotency_key_hash) WHERE lesson_assessment_manual_review_id IS NOT NULL');
        DB::statement("CREATE UNIQUE INDEX lesson_assessment_review_events_finalization_idempotency_unique ON lesson_assessment_review_events (lesson_assessment_attempt_id, event_type, idempotency_key_hash) WHERE event_type = 'finalized'");
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION lesson_assessment_review_events_append_only()
            RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'lesson assessment review events are append-only';
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER lesson_assessment_review_events_append_only_trigger
            BEFORE UPDATE OR DELETE OR TRUNCATE ON lesson_assessment_review_events
            FOR EACH STATEMENT EXECUTE FUNCTION lesson_assessment_review_events_append_only();
        SQL);
    }

    public function down(): void
    {
        if (
            DB::table('lesson_assessment_review_events')->exists()
            || DB::table('lesson_assessment_manual_reviews')->exists()
            || DB::table('lesson_assessment_attempts')
                ->where('status', 'pending_review')
                ->orWhereNotNull('submitted_at')
                ->orWhereNotNull('finalized_at')
                ->orWhereNotNull('automatic_score')
                ->orWhereNotNull('manual_score')
                ->orWhereNotNull('final_score')
                ->orWhereNotNull('failure_reason')
                ->exists()
        ) {
            throw new RuntimeException('Cannot roll back hybrid lesson assessment review schema while review, submission, finalization, or score snapshot data exists.');
        }

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement('DROP TRIGGER IF EXISTS lesson_assessment_review_events_append_only_trigger ON lesson_assessment_review_events');
            DB::statement('DROP FUNCTION IF EXISTS lesson_assessment_review_events_append_only()');
            DB::statement('DROP INDEX IF EXISTS lesson_assessment_review_events_review_idempotency_unique');
            DB::statement('DROP INDEX IF EXISTS lesson_assessment_review_events_finalization_idempotency_unique');
            DB::statement('ALTER TABLE lesson_assessment_attempts DROP CONSTRAINT lesson_assessment_attempts_state_snapshot_check');
            DB::statement('ALTER TABLE lesson_assessment_attempts DROP CONSTRAINT lesson_assessment_attempts_failure_reason_check');
            DB::statement('ALTER TABLE lesson_assessment_attempts DROP CONSTRAINT lesson_assessment_attempts_score_snapshot_check');
            DB::statement('ALTER TABLE lesson_assessment_attempts DROP CONSTRAINT lesson_assessment_attempts_status_check');
            DB::statement("ALTER TABLE lesson_assessment_attempts ADD CONSTRAINT lesson_assessment_attempts_status_check CHECK (status IN ('in_progress', 'failed', 'passed'))");
            DB::statement('ALTER TABLE lesson_assessment_attempt_responses DROP CONSTRAINT lesson_assessment_attempt_responses_revision_check');
            DB::statement('ALTER TABLE lesson_assessment_versions DROP CONSTRAINT lesson_assessment_versions_scoring_policy_check');
            DB::statement('ALTER TABLE lesson_assessment_versions DROP CONSTRAINT lesson_assessment_versions_schema_version_check');
            DB::statement('ALTER TABLE lesson_assessment_versions DROP CONSTRAINT lesson_assessment_versions_content_version_check');
            DB::statement('ALTER TABLE lesson_assessment_versions ADD CONSTRAINT lesson_assessment_versions_schema_version_check CHECK (schema_version > 0)');
            DB::statement('ALTER TABLE lesson_assessment_versions ADD CONSTRAINT lesson_assessment_versions_content_version_check CHECK (content_version > 0)');
        }

        Schema::dropIfExists('lesson_assessment_review_events');
        Schema::dropIfExists('lesson_assessment_manual_reviews');
        Schema::table('lesson_assessment_attempt_responses', function (Blueprint $table): void {
            $table->dropUnique('lesson_assessment_attempt_responses_id_attempt_unique');
            $table->dropColumn('revision');
        });
        Schema::table('lesson_assessment_attempts', function (Blueprint $table): void {
            $table->dropColumn(['automatic_score', 'manual_score', 'final_score', 'submitted_at', 'finalized_at', 'failure_reason']);
        });
        Schema::table('lesson_assessment_versions', function (Blueprint $table): void {
            $table->dropColumn('scoring_policy');
        });
    }
};
