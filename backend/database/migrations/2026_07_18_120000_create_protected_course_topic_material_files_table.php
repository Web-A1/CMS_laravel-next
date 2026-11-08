<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('course_topic_material_files', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('course_topic_material_id')
                ->unique('course_topic_material_files_material_unique')
                ->constrained('course_topic_materials')
                ->cascadeOnDelete();
            $table->string('source_object_key', 1024);
            $table->char('source_sha256', 64);
            $table->unsignedBigInteger('source_size_bytes');
            $table->string('source_mime_type', 100);
            $table->string('download_filename');
            $table->timestamps();

            $table->index('source_sha256', 'course_topic_material_files_source_sha_index');
        });

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE course_topic_material_files ADD CONSTRAINT course_topic_material_files_source_size_check CHECK (source_size_bytes > 0)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('course_topic_material_files');
    }
};
