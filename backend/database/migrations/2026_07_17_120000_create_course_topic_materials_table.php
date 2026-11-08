<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('course_topic_materials', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('course_topic_id')->constrained('course_topics')->cascadeOnDelete();
            $table->enum('kind', ['video', 'pdf', 'notes', 'file']);
            $table->string('title');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_published')->default(false);

            $table->index(
                ['course_topic_id', 'is_published', 'sort_order', 'id'],
                'course_topic_materials_manifest_index'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('course_topic_materials');
    }
};
