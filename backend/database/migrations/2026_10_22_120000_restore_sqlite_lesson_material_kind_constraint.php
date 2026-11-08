<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            return;
        }

        if (DB::table('lesson_materials')->whereNotIn('kind', ['video', 'pdf', 'notes', 'file'])->exists()) {
            throw new RuntimeException('Correct invalid lesson material kinds before restoring the constraint.');
        }

        // SQLite's table rebuild for nullable lesson_id discarded the original enum CHECK.
        foreach (['INSERT', 'UPDATE'] as $event) {
            $name = 'lesson_materials_kind_'.strtolower($event);
            DB::statement("CREATE TRIGGER {$name} BEFORE {$event} ON lesson_materials WHEN NEW.kind NOT IN ('video', 'pdf', 'notes', 'file') BEGIN SELECT RAISE(ABORT, 'lesson_materials_kind_check'); END");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            return;
        }

        DB::statement('DROP TRIGGER IF EXISTS lesson_materials_kind_insert');
        DB::statement('DROP TRIGGER IF EXISTS lesson_materials_kind_update');
    }
};
