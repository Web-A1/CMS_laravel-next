<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lesson_assessments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('lesson_id')
                ->unique()
                ->constrained('lessons')
                ->restrictOnDelete();
            $table->timestampsTz();
        });

        Schema::create('lesson_assessment_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('lesson_assessment_id')
                ->constrained('lesson_assessments')
                ->restrictOnDelete();
            $table->string('status', 20);
            $table->unsignedInteger('schema_version');
            $table->unsignedInteger('content_version');
            $table->char('source_assignment_sha256', 64);
            $table->char('source_key_sha256', 64);
            $table->jsonb('sections');
            $table->jsonb('questions');
            $table->jsonb('answer_key');
            $table->timestampTz('published_at')->nullable();
            $table->timestampsTz();

            $table->unique(
                ['lesson_assessment_id', 'content_version'],
                'lesson_assessment_versions_assessment_content_version_unique',
            );
            $table->index('lesson_assessment_id', 'lesson_assessment_versions_assessment_index');
        });

        Schema::create('lesson_assessment_assets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('lesson_assessment_version_id')
                ->constrained('lesson_assessment_versions')
                ->restrictOnDelete();
            $table->string('asset_key');
            $table->string('disk');
            $table->string('object_key', 1024);
            $table->char('sha256', 64);
            $table->string('mime_type', 100);
            $table->unsignedInteger('width');
            $table->unsignedInteger('height');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestampsTz();

            $table->unique(
                ['lesson_assessment_version_id', 'asset_key'],
                'lesson_assessment_assets_version_asset_key_unique',
            );
        });

        Schema::create('lesson_assessment_attempts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('lesson_assessment_id')
                ->constrained('lesson_assessments')
                ->restrictOnDelete();
            $table->foreignId('lesson_assessment_version_id')
                ->constrained('lesson_assessment_versions')
                ->restrictOnDelete();
            $table->string('status', 20);
            $table->unsignedSmallInteger('correct_count')->default(0);
            $table->string('last_question_id')->nullable();
            $table->unsignedInteger('revision')->default(0);
            $table->unsignedInteger('check_count')->default(0);
            $table->timestampTz('checked_at')->nullable();
            $table->timestampTz('passed_at')->nullable();
            $table->timestampsTz();

            $table->index('user_id', 'lesson_assessment_attempts_user_index');
            $table->index('lesson_assessment_id', 'lesson_assessment_attempts_assessment_index');
            $table->index('lesson_assessment_version_id', 'lesson_assessment_attempts_version_index');
        });

        Schema::create('lesson_assessment_attempt_responses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('lesson_assessment_attempt_id')
                ->constrained('lesson_assessment_attempts')
                ->cascadeOnDelete();
            $table->string('question_id');
            $table->jsonb('response');
            $table->boolean('is_complete')->default(false);
            $table->boolean('is_locked')->default(false);
            $table->timestampTz('checked_at')->nullable();
            $table->timestampsTz();

            $table->unique(
                ['lesson_assessment_attempt_id', 'question_id'],
                'lesson_assessment_attempt_responses_attempt_question_unique',
            );
        });

        Schema::create('lesson_assessment_completions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('lesson_id')->constrained('lessons')->restrictOnDelete();
            $table->foreignId('lesson_assessment_id')
                ->constrained('lesson_assessments')
                ->restrictOnDelete();
            $table->foreignId('passed_attempt_id')
                ->constrained('lesson_assessment_attempts')
                ->restrictOnDelete();
            $table->timestampTz('completed_at');

            $table->unique(
                ['user_id', 'lesson_id'],
                'lesson_assessment_completions_user_lesson_unique',
            );
            $table->index('lesson_id', 'lesson_assessment_completions_lesson_index');
            $table->index('lesson_assessment_id', 'lesson_assessment_completions_assessment_index');
            $table->index('passed_attempt_id', 'lesson_assessment_completions_passed_attempt_index');
        });

        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement("ALTER TABLE lesson_assessment_versions ADD CONSTRAINT lesson_assessment_versions_status_check CHECK (status IN ('draft', 'published', 'retired'))");
        DB::statement('ALTER TABLE lesson_assessment_versions ADD CONSTRAINT lesson_assessment_versions_schema_version_check CHECK (schema_version > 0)');
        DB::statement('ALTER TABLE lesson_assessment_versions ADD CONSTRAINT lesson_assessment_versions_content_version_check CHECK (content_version > 0)');
        DB::statement("ALTER TABLE lesson_assessment_versions ADD CONSTRAINT lesson_assessment_versions_sections_check CHECK (jsonb_typeof(sections) = 'array')");
        DB::statement("ALTER TABLE lesson_assessment_versions ADD CONSTRAINT lesson_assessment_versions_questions_check CHECK (jsonb_typeof(questions) = 'array')");
        DB::statement("ALTER TABLE lesson_assessment_versions ADD CONSTRAINT lesson_assessment_versions_answer_key_check CHECK (jsonb_typeof(answer_key) = 'object')");
        DB::statement('ALTER TABLE lesson_assessment_assets ADD CONSTRAINT lesson_assessment_assets_width_check CHECK (width > 0)');
        DB::statement('ALTER TABLE lesson_assessment_assets ADD CONSTRAINT lesson_assessment_assets_height_check CHECK (height > 0)');
        DB::statement("ALTER TABLE lesson_assessment_attempts ADD CONSTRAINT lesson_assessment_attempts_status_check CHECK (status IN ('in_progress', 'passed'))");
        DB::statement('ALTER TABLE lesson_assessment_attempts ADD CONSTRAINT lesson_assessment_attempts_correct_count_check CHECK (correct_count BETWEEN 0 AND 48)');
        DB::statement('ALTER TABLE lesson_assessment_attempts ADD CONSTRAINT lesson_assessment_attempts_revision_check CHECK (revision >= 0)');
        DB::statement('ALTER TABLE lesson_assessment_attempts ADD CONSTRAINT lesson_assessment_attempts_check_count_check CHECK (check_count >= 0)');
        DB::statement("ALTER TABLE lesson_assessment_attempt_responses ADD CONSTRAINT lesson_assessment_attempt_responses_response_check CHECK (jsonb_typeof(response) = 'object')");
        DB::statement("CREATE UNIQUE INDEX lesson_assessment_versions_one_published_unique ON lesson_assessment_versions (lesson_assessment_id) WHERE status = 'published'");
        DB::statement("CREATE UNIQUE INDEX lesson_assessment_attempts_one_active_unique ON lesson_assessment_attempts (user_id, lesson_assessment_version_id) WHERE status = 'in_progress'");
    }

    public function down(): void
    {
        Schema::dropIfExists('lesson_assessment_completions');
        Schema::dropIfExists('lesson_assessment_attempt_responses');
        Schema::dropIfExists('lesson_assessment_attempts');
        Schema::dropIfExists('lesson_assessment_assets');
        Schema::dropIfExists('lesson_assessment_versions');
        Schema::dropIfExists('lesson_assessments');
    }
};
