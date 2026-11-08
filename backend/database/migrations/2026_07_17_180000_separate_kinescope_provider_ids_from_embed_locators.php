<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('course_topic_material_kinescope_videos', function (Blueprint $table): void {
            $table->dropIndex('course_topic_material_kinescope_video_id_index');
            $table->renameColumn('video_id', 'embed_locator');
        });
        Schema::table('course_topic_material_kinescope_videos', function (Blueprint $table): void {
            $table->string('provider_video_id')->nullable();
            $table->unique('embed_locator', 'course_topic_material_kinescope_embed_locator_unique');
            $table->unique('provider_video_id', 'course_topic_material_kinescope_provider_video_id_unique');
        });

        Schema::table('kinescope_legacy_video_allowlist_entries', function (Blueprint $table): void {
            $table->renameColumn('video_id', 'embed_locator');
        });
        Schema::table('kinescope_legacy_video_allowlist_entries', function (Blueprint $table): void {
            $table->string('provider_video_id')->nullable();
            $table->unique(
                ['batch_id', 'provider_video_id'],
                'kinescope_legacy_allowlist_batch_provider_video_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::table('kinescope_legacy_video_allowlist_entries', function (Blueprint $table): void {
            $table->dropUnique('kinescope_legacy_allowlist_batch_provider_video_unique');
            $table->dropColumn('provider_video_id');
        });
        Schema::table('kinescope_legacy_video_allowlist_entries', function (Blueprint $table): void {
            $table->renameColumn('embed_locator', 'video_id');
        });

        Schema::table('course_topic_material_kinescope_videos', function (Blueprint $table): void {
            $table->dropUnique('course_topic_material_kinescope_embed_locator_unique');
            $table->dropUnique('course_topic_material_kinescope_provider_video_id_unique');
            $table->dropColumn('provider_video_id');
        });
        Schema::table('course_topic_material_kinescope_videos', function (Blueprint $table): void {
            $table->renameColumn('embed_locator', 'video_id');
            $table->index('video_id', 'course_topic_material_kinescope_video_id_index');
        });
    }
};
