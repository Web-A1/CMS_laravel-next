<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('course_topic_material_kinescope_videos', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('course_topic_material_id')
                ->unique('course_topic_material_kinescope_video_material_unique')
                ->constrained('course_topic_materials')
                ->cascadeOnDelete();
            $table->string('video_id');

            $table->index('video_id', 'course_topic_material_kinescope_video_id_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('course_topic_material_kinescope_videos');
    }
};
