<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('course_topic_learning_contents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('course_topic_id')->unique()->constrained('course_topics')->cascadeOnDelete();
            $table->string('status', 32)->default('draft');
            $table->string('format', 64)->default('lesson_blocks_v1');
            $table->unsignedInteger('version')->default(1);
            $table->json('body')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->string('source_table', 128)->nullable();
            $table->string('source_id', 128)->nullable();
            $table->char('source_fingerprint_hash', 64)->nullable();
            $table->string('import_status', 32)->default('manual');
            $table->string('manual_review_reason')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['status', 'format', 'published_at']);
            $table->index(['source_table', 'source_id']);
            $table->index(['import_status', 'manual_review_reason']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('course_topic_learning_contents');
    }
};
