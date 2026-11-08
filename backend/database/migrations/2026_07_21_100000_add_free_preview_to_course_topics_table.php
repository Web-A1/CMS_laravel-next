<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('course_topics', function (Blueprint $table): void {
            $table->boolean('is_free_preview')
                ->default(false)
                ->after('is_active');
            $table->index(['course_id', 'is_active', 'is_free_preview'], 'course_topics_free_preview_index');
        });
    }

    public function down(): void
    {
        Schema::table('course_topics', function (Blueprint $table): void {
            $table->dropIndex('course_topics_free_preview_index');
            $table->dropColumn('is_free_preview');
        });
    }
};
