<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE lesson_assessment_attempts DROP CONSTRAINT IF EXISTS lesson_assessment_attempts_correct_count_check');
        DB::statement('ALTER TABLE lesson_assessment_attempts ADD CONSTRAINT lesson_assessment_attempts_correct_count_check CHECK (correct_count >= 0)');
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE lesson_assessment_attempts DROP CONSTRAINT IF EXISTS lesson_assessment_attempts_correct_count_check');
        DB::statement('ALTER TABLE lesson_assessment_attempts ADD CONSTRAINT lesson_assessment_attempts_correct_count_check CHECK (correct_count BETWEEN 0 AND 48)');
    }
};
