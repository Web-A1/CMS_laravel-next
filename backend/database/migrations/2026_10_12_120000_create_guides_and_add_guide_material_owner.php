<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('guides', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('legacy_file_id')->unique();
            $table->string('slug')->unique();
            $table->string('title');
            $table->boolean('is_active')->default(false);
            $table->timestamps();
        });
        Schema::table('lesson_materials', function (Blueprint $table): void {
            $table->unsignedBigInteger('lesson_id')->nullable()->change();
            $table->foreignId('guide_id')->nullable()->constrained('guides')->cascadeOnDelete();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE lesson_materials ADD CONSTRAINT lesson_materials_owner_check CHECK (((lesson_id IS NOT NULL) <> (guide_id IS NOT NULL)) AND (guide_id IS NULL OR kind = 'pdf'))");
        } elseif (DB::getDriverName() === 'sqlite') {
            // SQLite cannot add a CHECK to an existing table; triggers enforce the same invariant.
            foreach (['INSERT', 'UPDATE'] as $event) {
                $name = 'lesson_materials_owner_'.strtolower($event);
                DB::statement("CREATE TRIGGER {$name} BEFORE {$event} ON lesson_materials WHEN ((NEW.lesson_id IS NULL) = (NEW.guide_id IS NULL)) OR (NEW.guide_id IS NOT NULL AND NEW.kind <> 'pdf') BEGIN SELECT RAISE(ABORT, 'lesson_materials_owner_check'); END");
            }
        } else {
            throw new RuntimeException('Guide material ownership requires PostgreSQL or SQLite.');
        }
    }

    public function down(): void
    {
        if (DB::table('lesson_materials')->whereNotNull('guide_id')->exists()) {
            throw new RuntimeException('Remove guide-owned materials explicitly before rolling back ownership.');
        }
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE lesson_materials DROP CONSTRAINT lesson_materials_owner_check');
        } else {
            DB::statement('DROP TRIGGER IF EXISTS lesson_materials_owner_insert');
            DB::statement('DROP TRIGGER IF EXISTS lesson_materials_owner_update');
        }
        Schema::table('lesson_materials', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('guide_id');
            $table->unsignedBigInteger('lesson_id')->nullable(false)->change();
        });
        Schema::dropIfExists('guides');
    }
};
