<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('subject_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedTinyInteger('rating');
            $table->text('text');
            $table->string('display_name');
            $table->boolean('is_anonymous')->default(false);
            $table->string('status')->default('draft');
            $table->string('source')->default('student');
            $table->string('source_ref')->nullable()->unique();
            $table->timestamp('review_written_at')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['status', 'published_at']);
            $table->index(['status', 'review_written_at']);
            $table->index(['course_id', 'status']);
            $table->index(['subject_id', 'status']);
            $table->index(['rating', 'status']);
        });

    }

    public function down(): void
    {
        Schema::dropIfExists('reviews');
    }
};
