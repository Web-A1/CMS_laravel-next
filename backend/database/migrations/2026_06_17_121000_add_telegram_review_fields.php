<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reviews', function (Blueprint $table) {
            if (! Schema::hasColumn('reviews', 'subject_id')) {
                $table->foreignId('subject_id')->nullable()->after('course_id')->constrained()->nullOnDelete();
                $table->index(['subject_id', 'status']);
            }

            if (! Schema::hasColumn('reviews', 'source_ref')) {
                $table->string('source_ref')->nullable()->after('source')->unique();
            }

            if (! Schema::hasColumn('reviews', 'review_written_at')) {
                $table->timestamp('review_written_at')->nullable()->after('source_ref');
                $table->index(['status', 'review_written_at']);
            }
        });

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE reviews ALTER COLUMN course_id DROP NOT NULL');
        }
    }

    public function down(): void
    {
        // This migration is an existing-database compatibility bridge.
        // Fresh installs get these columns from create_reviews_table.
    }
};
