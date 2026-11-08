<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->dropUniqueIfExists('lesson_material_kinescope_videos', 'lesson_material_kinescope_embed_locator_unique');
        $this->dropUniqueIfExists('lesson_material_kinescope_videos', 'course_topic_material_kinescope_embed_locator_unique');
        $this->dropUniqueIfExists('lesson_material_kinescope_videos', 'lesson_material_kinescope_provider_video_id_unique');
        $this->dropUniqueIfExists('lesson_material_kinescope_videos', 'course_topic_material_kinescope_provider_video_id_unique');

        Schema::table('lesson_material_kinescope_videos', function (Blueprint $table): void {
            if (! Schema::hasIndex('lesson_material_kinescope_videos', 'lesson_material_kinescope_embed_locator_index')) {
                $table->index('embed_locator', 'lesson_material_kinescope_embed_locator_index');
            }
            if (! Schema::hasIndex('lesson_material_kinescope_videos', 'lesson_material_kinescope_provider_video_id_index')) {
                $table->index('provider_video_id', 'lesson_material_kinescope_provider_video_id_index');
            }
        });
    }

    public function down(): void
    {
        Schema::table('lesson_material_kinescope_videos', function (Blueprint $table): void {
            if (Schema::hasIndex('lesson_material_kinescope_videos', 'lesson_material_kinescope_embed_locator_index')) {
                $table->dropIndex('lesson_material_kinescope_embed_locator_index');
            }
            if (Schema::hasIndex('lesson_material_kinescope_videos', 'lesson_material_kinescope_provider_video_id_index')) {
                $table->dropIndex('lesson_material_kinescope_provider_video_id_index');
            }

            if (! Schema::hasIndex('lesson_material_kinescope_videos', 'lesson_material_kinescope_embed_locator_unique')) {
                $table->unique('embed_locator', 'lesson_material_kinescope_embed_locator_unique');
            }
            if (! Schema::hasIndex('lesson_material_kinescope_videos', 'lesson_material_kinescope_provider_video_id_unique')) {
                $table->unique('provider_video_id', 'lesson_material_kinescope_provider_video_id_unique');
            }
        });
    }

    private function dropUniqueIfExists(string $tableName, string $indexName): void
    {
        if (! Schema::hasIndex($tableName, $indexName)) {
            return;
        }

        Schema::table($tableName, function (Blueprint $table) use ($indexName): void {
            $table->dropUnique($indexName);
        });
    }
};
