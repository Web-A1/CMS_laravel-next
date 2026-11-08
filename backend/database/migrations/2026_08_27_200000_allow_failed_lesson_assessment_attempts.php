<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE lesson_assessment_attempts DROP CONSTRAINT lesson_assessment_attempts_status_check');
        }

        DB::table('lesson_assessment_attempts')
            ->where('status', 'in_progress')
            ->whereNotNull('checked_at')
            ->update(['status' => 'failed']);

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE lesson_assessment_attempts ADD CONSTRAINT lesson_assessment_attempts_status_check CHECK (status IN ('in_progress', 'failed', 'passed'))");
        }
    }

    public function down(): void
    {
        if (DB::table('lesson_assessment_attempts')->where('status', 'failed')->exists()) {
            throw new RuntimeException('Cannot roll back terminal failed assessment attempts without losing their meaning.');
        }

        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE lesson_assessment_attempts DROP CONSTRAINT lesson_assessment_attempts_status_check');
        DB::statement("ALTER TABLE lesson_assessment_attempts ADD CONSTRAINT lesson_assessment_attempts_status_check CHECK (status IN ('in_progress', 'passed'))");
    }
};
