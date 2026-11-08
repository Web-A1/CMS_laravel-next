<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->renameColumnIfExists('course_topic_learning_contents', 'course_topic_id', 'lesson_id');
        $this->renameColumnIfExists('legacy_protected_asset_mappings', 'course_topic_learning_content_id', 'lesson_learning_content_id');
        $this->renameColumnIfExists('legacy_protected_asset_mappings', 'course_topic_id', 'lesson_id');
        $this->renameColumnIfExists('course_topic_materials', 'course_topic_id', 'lesson_id');
        $this->renameColumnIfExists('course_topic_material_kinescope_videos', 'course_topic_material_id', 'lesson_material_id');
        $this->renameColumnIfExists('course_topic_material_files', 'course_topic_material_id', 'lesson_material_id');
        $this->renameColumnIfExists('protected_file_deliveries', 'course_topic_material_file_id', 'lesson_material_file_id');

        $this->renameTableIfExists('course_topics', 'lessons');
        $this->renameTableIfExists('course_topic_learning_contents', 'lesson_learning_contents');
        $this->renameTableIfExists('course_topic_materials', 'lesson_materials');
        $this->renameTableIfExists('course_topic_material_kinescope_videos', 'lesson_material_kinescope_videos');
        $this->renameTableIfExists('course_topic_material_files', 'lesson_material_files');

        $this->renamePgSequenceIfExists('course_topics_id_seq', 'lessons_id_seq');
        $this->renamePgSequenceIfExists('course_topic_learning_contents_id_seq', 'lesson_learning_contents_id_seq');
        $this->renamePgSequenceIfExists('course_topic_materials_id_seq', 'lesson_materials_id_seq');
        $this->renamePgSequenceIfExists('course_topic_material_kinescope_videos_id_seq', 'lesson_material_kinescope_videos_id_seq');
        $this->renamePgSequenceIfExists('course_topic_material_files_id_seq', 'lesson_material_files_id_seq');

        $this->renamePgConstraintIfExists('lessons', 'course_topics_pkey', 'lessons_pkey');
        $this->renamePgConstraintIfExists('lessons', 'course_topics_course_id_foreign', 'lessons_course_id_foreign');
        $this->renamePgConstraintIfExists('lessons', 'course_topics_course_id_slug_unique', 'lessons_course_id_slug_unique');
        $this->renamePgConstraintIfExists('lesson_learning_contents', 'course_topic_learning_contents_pkey', 'lesson_learning_contents_pkey');
        $this->renamePgConstraintIfExists('lesson_learning_contents', 'course_topic_learning_contents_course_topic_id_foreign', 'lesson_learning_contents_lesson_id_foreign');
        $this->renamePgConstraintIfExists('lesson_learning_contents', 'course_topic_learning_contents_course_topic_id_unique', 'lesson_learning_contents_lesson_id_unique');
        $this->renamePgConstraintIfExists('lesson_materials', 'course_topic_materials_pkey', 'lesson_materials_pkey');
        $this->renamePgConstraintIfExists('lesson_materials', 'course_topic_materials_course_topic_id_foreign', 'lesson_materials_lesson_id_foreign');
        $this->renamePgConstraintIfExists('lesson_materials', 'course_topic_materials_kind_check', 'lesson_materials_kind_check');
        $this->renamePgConstraintIfExists('lesson_material_kinescope_videos', 'course_topic_material_kinescope_videos_pkey', 'lesson_material_kinescope_videos_pkey');
        $this->renamePgConstraintIfExists('lesson_material_kinescope_videos', 'course_topic_material_kinescope_videos_course_topic_material_id_foreign', 'lesson_material_kinescope_videos_lesson_material_id_foreign');
        $this->renamePgConstraintIfExists('lesson_material_kinescope_videos', 'course_topic_material_kinescope_videos_course_topic_material_id', 'lesson_material_kinescope_videos_lesson_material_id_foreign');
        $this->renamePgConstraintIfExists('lesson_material_kinescope_videos', 'course_topic_material_kinescope_video_material_unique', 'lesson_material_kinescope_video_material_unique');
        $this->renamePgConstraintIfExists('lesson_material_kinescope_videos', 'course_topic_material_kinescope_embed_locator_unique', 'lesson_material_kinescope_embed_locator_unique');
        $this->renamePgConstraintIfExists('lesson_material_kinescope_videos', 'course_topic_material_kinescope_provider_video_id_unique', 'lesson_material_kinescope_provider_video_id_unique');
        $this->renamePgConstraintIfExists('lesson_material_files', 'course_topic_material_files_pkey', 'lesson_material_files_pkey');
        $this->renamePgConstraintIfExists('lesson_material_files', 'course_topic_material_files_course_topic_material_id_foreign', 'lesson_material_files_lesson_material_id_foreign');
        $this->renamePgConstraintIfExists('lesson_material_files', 'course_topic_material_files_material_unique', 'lesson_material_files_material_unique');
        $this->renamePgConstraintIfExists('lesson_material_files', 'course_topic_material_files_source_size_check', 'lesson_material_files_source_size_check');
        $this->renamePgConstraintIfExists('legacy_protected_asset_mappings', 'legacy_protected_asset_mappings_topic_fk', 'legacy_protected_asset_mappings_lesson_fk');
        $this->renamePgConstraintIfExists('protected_file_deliveries', 'protected_file_deliveries_course_topic_material_file_id_foreign', 'protected_file_deliveries_lesson_material_file_id_foreign');

        $this->renamePgIndexIfExists('course_topics_course_id_slug_unique', 'lessons_course_id_slug_unique');
        $this->renamePgIndexIfExists('course_topics_course_id_is_active_sort_order_index', 'lessons_course_id_is_active_sort_order_index');
        $this->renamePgIndexIfExists('course_topics_free_preview_index', 'lessons_free_preview_index');
        $this->renamePgIndexIfExists('course_topics_legacy_product_id_index', 'lessons_legacy_product_id_index');
        $this->renamePgIndexIfExists('course_topic_learning_contents_course_topic_id_unique', 'lesson_learning_contents_lesson_id_unique');
        $this->renamePgIndexIfExists('course_topic_learning_contents_status_format_published_at_index', 'lesson_learning_contents_status_format_published_at_index');
        $this->renamePgIndexIfExists('course_topic_learning_contents_source_table_source_id_index', 'lesson_learning_contents_source_table_source_id_index');
        $this->renamePgIndexIfExists('course_topic_learning_contents_import_status_manual_review_reason_index', 'lesson_learning_contents_import_review_index');
        $this->renamePgIndexIfExists('course_topic_learning_contents_import_status_manual_review_reas', 'lesson_learning_contents_import_review_index');
        $this->renamePgIndexIfExists('legacy_protected_asset_mappings_topic_kind_index', 'legacy_protected_asset_mappings_lesson_kind_index');
        $this->renamePgIndexIfExists('course_topic_materials_manifest_index', 'lesson_materials_manifest_index');
        $this->renamePgIndexIfExists('course_topic_material_kinescope_embed_locator_unique', 'lesson_material_kinescope_embed_locator_unique');
        $this->renamePgIndexIfExists('course_topic_material_kinescope_provider_video_id_unique', 'lesson_material_kinescope_provider_video_id_unique');
        $this->renamePgIndexIfExists('course_topic_material_kinescope_video_material_unique', 'lesson_material_kinescope_video_material_unique');
        $this->renamePgIndexIfExists('course_topic_material_files_material_unique', 'lesson_material_files_material_unique');
        $this->renamePgIndexIfExists('course_topic_material_files_source_sha_index', 'lesson_material_files_source_sha_index');
        $this->recreateSqliteIndexIfNeeded(
            'lesson_materials',
            'course_topic_materials_manifest_index',
            'lesson_materials_manifest_index',
            ['lesson_id', 'is_published', 'sort_order', 'id'],
        );
    }

    public function down(): void
    {
        $this->renamePgConstraintIfExists('protected_file_deliveries', 'protected_file_deliveries_lesson_material_file_id_foreign', 'protected_file_deliveries_course_topic_material_file_id_foreign');
        $this->renamePgConstraintIfExists('legacy_protected_asset_mappings', 'legacy_protected_asset_mappings_lesson_fk', 'legacy_protected_asset_mappings_topic_fk');
        $this->renamePgConstraintIfExists('lesson_material_files', 'lesson_material_files_source_size_check', 'course_topic_material_files_source_size_check');
        $this->renamePgConstraintIfExists('lesson_material_files', 'lesson_material_files_material_unique', 'course_topic_material_files_material_unique');
        $this->renamePgConstraintIfExists('lesson_material_files', 'lesson_material_files_lesson_material_id_foreign', 'course_topic_material_files_course_topic_material_id_foreign');
        $this->renamePgConstraintIfExists('lesson_material_files', 'lesson_material_files_pkey', 'course_topic_material_files_pkey');
        $this->renamePgConstraintIfExists('lesson_material_kinescope_videos', 'lesson_material_kinescope_provider_video_id_unique', 'course_topic_material_kinescope_provider_video_id_unique');
        $this->renamePgConstraintIfExists('lesson_material_kinescope_videos', 'lesson_material_kinescope_embed_locator_unique', 'course_topic_material_kinescope_embed_locator_unique');
        $this->renamePgConstraintIfExists('lesson_material_kinescope_videos', 'lesson_material_kinescope_video_material_unique', 'course_topic_material_kinescope_video_material_unique');
        $this->renamePgConstraintIfExists('lesson_material_kinescope_videos', 'lesson_material_kinescope_videos_lesson_material_id_foreign', 'course_topic_material_kinescope_videos_course_topic_material_id_foreign');
        $this->renamePgConstraintIfExists('lesson_material_kinescope_videos', 'lesson_material_kinescope_videos_pkey', 'course_topic_material_kinescope_videos_pkey');
        $this->renamePgConstraintIfExists('lesson_materials', 'lesson_materials_lesson_id_foreign', 'course_topic_materials_course_topic_id_foreign');
        $this->renamePgConstraintIfExists('lesson_materials', 'lesson_materials_kind_check', 'course_topic_materials_kind_check');
        $this->renamePgConstraintIfExists('lesson_materials', 'lesson_materials_pkey', 'course_topic_materials_pkey');
        $this->renamePgConstraintIfExists('lesson_learning_contents', 'lesson_learning_contents_lesson_id_unique', 'course_topic_learning_contents_course_topic_id_unique');
        $this->renamePgConstraintIfExists('lesson_learning_contents', 'lesson_learning_contents_lesson_id_foreign', 'course_topic_learning_contents_course_topic_id_foreign');
        $this->renamePgConstraintIfExists('lesson_learning_contents', 'lesson_learning_contents_pkey', 'course_topic_learning_contents_pkey');
        $this->renamePgConstraintIfExists('lessons', 'lessons_course_id_slug_unique', 'course_topics_course_id_slug_unique');
        $this->renamePgConstraintIfExists('lessons', 'lessons_course_id_foreign', 'course_topics_course_id_foreign');
        $this->renamePgConstraintIfExists('lessons', 'lessons_pkey', 'course_topics_pkey');

        $this->renamePgIndexIfExists('lessons_course_id_slug_unique', 'course_topics_course_id_slug_unique');
        $this->renamePgIndexIfExists('lessons_course_id_is_active_sort_order_index', 'course_topics_course_id_is_active_sort_order_index');
        $this->renamePgIndexIfExists('lessons_free_preview_index', 'course_topics_free_preview_index');
        $this->renamePgIndexIfExists('lessons_legacy_product_id_index', 'course_topics_legacy_product_id_index');
        $this->renamePgIndexIfExists('lesson_learning_contents_lesson_id_unique', 'course_topic_learning_contents_course_topic_id_unique');
        $this->renamePgIndexIfExists('lesson_learning_contents_status_format_published_at_index', 'course_topic_learning_contents_status_format_published_at_index');
        $this->renamePgIndexIfExists('lesson_learning_contents_source_table_source_id_index', 'course_topic_learning_contents_source_table_source_id_index');
        $this->renamePgIndexIfExists('lesson_learning_contents_import_review_index', 'course_topic_learning_contents_import_status_manual_review_reason_index');
        $this->renamePgIndexIfExists('legacy_protected_asset_mappings_lesson_kind_index', 'legacy_protected_asset_mappings_topic_kind_index');
        $this->renamePgIndexIfExists('lesson_materials_manifest_index', 'course_topic_materials_manifest_index');
        $this->renamePgIndexIfExists('lesson_material_kinescope_embed_locator_unique', 'course_topic_material_kinescope_embed_locator_unique');
        $this->renamePgIndexIfExists('lesson_material_kinescope_provider_video_id_unique', 'course_topic_material_kinescope_provider_video_id_unique');
        $this->renamePgIndexIfExists('lesson_material_kinescope_video_material_unique', 'course_topic_material_kinescope_video_material_unique');
        $this->renamePgIndexIfExists('lesson_material_files_material_unique', 'course_topic_material_files_material_unique');
        $this->renamePgIndexIfExists('lesson_material_files_source_sha_index', 'course_topic_material_files_source_sha_index');

        $this->renameTableIfExists('lesson_material_files', 'course_topic_material_files');
        $this->renameTableIfExists('lesson_material_kinescope_videos', 'course_topic_material_kinescope_videos');
        $this->renameTableIfExists('lesson_materials', 'course_topic_materials');
        $this->renameTableIfExists('lesson_learning_contents', 'course_topic_learning_contents');
        $this->renameTableIfExists('lessons', 'course_topics');

        $this->renamePgSequenceIfExists('lesson_material_files_id_seq', 'course_topic_material_files_id_seq');
        $this->renamePgSequenceIfExists('lesson_material_kinescope_videos_id_seq', 'course_topic_material_kinescope_videos_id_seq');
        $this->renamePgSequenceIfExists('lesson_materials_id_seq', 'course_topic_materials_id_seq');
        $this->renamePgSequenceIfExists('lesson_learning_contents_id_seq', 'course_topic_learning_contents_id_seq');
        $this->renamePgSequenceIfExists('lessons_id_seq', 'course_topics_id_seq');

        $this->renameColumnIfExists('protected_file_deliveries', 'lesson_material_file_id', 'course_topic_material_file_id');
        $this->renameColumnIfExists('course_topic_material_files', 'lesson_material_id', 'course_topic_material_id');
        $this->renameColumnIfExists('course_topic_material_kinescope_videos', 'lesson_material_id', 'course_topic_material_id');
        $this->renameColumnIfExists('course_topic_materials', 'lesson_id', 'course_topic_id');
        $this->renameColumnIfExists('legacy_protected_asset_mappings', 'lesson_id', 'course_topic_id');
        $this->renameColumnIfExists('legacy_protected_asset_mappings', 'lesson_learning_content_id', 'course_topic_learning_content_id');
        $this->renameColumnIfExists('course_topic_learning_contents', 'lesson_id', 'course_topic_id');
        $this->recreateSqliteIndexIfNeeded(
            'course_topic_materials',
            'lesson_materials_manifest_index',
            'course_topic_materials_manifest_index',
            ['course_topic_id', 'is_published', 'sort_order', 'id'],
        );
    }

    private function renameTableIfExists(string $from, string $to): void
    {
        if (! Schema::hasTable($from) || Schema::hasTable($to)) {
            return;
        }

        Schema::rename($from, $to);
    }

    private function renameColumnIfExists(string $tableName, string $from, string $to): void
    {
        if (! Schema::hasTable($tableName) || ! Schema::hasColumn($tableName, $from) || Schema::hasColumn($tableName, $to)) {
            return;
        }

        Schema::table($tableName, function (Blueprint $table) use ($from, $to): void {
            $table->renameColumn($from, $to);
        });
    }

    private function renamePgIndexIfExists(string $from, string $to): void
    {
        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement(sprintf('ALTER INDEX IF EXISTS %s RENAME TO %s', $from, $to));
    }

    private function renamePgConstraintIfExists(string $tableName, string $from, string $to): void
    {
        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement(sprintf(
            'DO $$ BEGIN IF EXISTS (SELECT 1 FROM pg_constraint WHERE conname = %s AND conrelid = to_regclass(%s)) THEN ALTER TABLE %s RENAME CONSTRAINT %s TO %s; END IF; END $$;',
            $this->quotePgLiteral($from),
            $this->quotePgLiteral($tableName),
            $this->quotePgIdentifier($tableName),
            $this->quotePgIdentifier($from),
            $this->quotePgIdentifier($to),
        ));
    }

    private function renamePgSequenceIfExists(string $from, string $to): void
    {
        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement(sprintf('ALTER SEQUENCE IF EXISTS %s RENAME TO %s', $from, $to));
    }

    private function quotePgIdentifier(string $value): string
    {
        return '"'.str_replace('"', '""', $value).'"';
    }

    private function quotePgLiteral(string $value): string
    {
        return "'".str_replace("'", "''", $value)."'";
    }

    /**
     * @param  array<int, string>  $columns
     */
    private function recreateSqliteIndexIfNeeded(string $tableName, string $from, string $to, array $columns): void
    {
        if (Schema::getConnection()->getDriverName() !== 'sqlite' || ! Schema::hasTable($tableName)) {
            return;
        }

        DB::statement("DROP INDEX IF EXISTS {$from}");
        DB::statement("DROP INDEX IF EXISTS {$to}");

        Schema::table($tableName, function (Blueprint $table) use ($columns, $to): void {
            $table->index($columns, $to);
        });
    }
};
