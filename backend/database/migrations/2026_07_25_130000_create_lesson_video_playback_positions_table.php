<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lesson_video_playback_positions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lesson_material_id')->constrained('lesson_materials')->cascadeOnDelete();
            $table->unsignedInteger('position_milliseconds');
            $table->unsignedInteger('duration_milliseconds')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'lesson_material_id'], 'lesson_video_positions_user_material_unique');
            $table->index(['user_id', 'updated_at'], 'lesson_video_positions_user_updated_index');
        });

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE lesson_video_playback_positions ADD CONSTRAINT lesson_video_positions_position_check CHECK (position_milliseconds >= 0)');
            DB::statement('ALTER TABLE lesson_video_playback_positions ADD CONSTRAINT lesson_video_positions_duration_check CHECK (duration_milliseconds IS NULL OR duration_milliseconds >= 0)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('lesson_video_playback_positions');
    }
};
