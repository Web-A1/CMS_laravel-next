<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Lesson;
use App\Models\LessonMaterial;
use App\Models\Subject;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class LearningMaterialRegistrySchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_registry_schema_is_minimal_and_matches_manifest_query(): void
    {
        $this->assertSame([
            'id',
            'lesson_id',
            'kind',
            'title',
            'sort_order',
            'is_published',
            'guide_id',
        ], Schema::getColumnListing('lesson_materials'));

        $manifestIndex = collect(Schema::getIndexes('lesson_materials'))
            ->firstWhere('name', 'lesson_materials_manifest_index');

        $this->assertNotNull($manifestIndex);
        $this->assertSame([
            'lesson_id',
            'is_published',
            'sort_order',
            'id',
        ], $manifestIndex['columns']);

        $lessonForeignKey = collect(Schema::getForeignKeys('lesson_materials'))
            ->first(fn (array $foreignKey): bool => $foreignKey['columns'] === ['lesson_id']);

        $this->assertNotNull($lessonForeignKey);
        $this->assertSame('lessons', $lessonForeignKey['foreign_table']);
        $this->assertSame(['id'], $lessonForeignKey['foreign_columns']);
        $this->assertSame('cascade', strtolower($lessonForeignKey['on_delete']));
    }

    public function test_registry_defaults_to_unpublished_and_cascades_with_lesson(): void
    {
        $lesson = $this->createLesson();
        $material = LessonMaterial::query()->create([
            'lesson_id' => $lesson->id,
            'kind' => LessonMaterial::KIND_VIDEO,
            'title' => 'Видеолекция',
        ]);

        $this->assertFalse($material->is_published);
        $this->assertSame(0, $material->sort_order);

        $lesson->delete();

        $this->assertDatabaseMissing('lesson_materials', ['id' => $material->id]);
    }

    public function test_registry_rejects_unknown_material_kind(): void
    {
        $lesson = $this->createLesson();

        $this->expectException(QueryException::class);

        DB::table('lesson_materials')->insert([
            'lesson_id' => $lesson->id,
            'kind' => 'provider_video',
            'title' => 'Недопустимый тип',
            'sort_order' => 0,
            'is_published' => false,
        ]);
    }

    public function test_registry_rejects_unknown_material_kind_on_update(): void
    {
        $material = $this->createLesson()->materials()->create(['kind' => 'pdf', 'title' => 'PDF']);

        $this->expectException(QueryException::class);

        DB::table('lesson_materials')->where('id', $material->id)->update(['kind' => 'provider_video']);
    }

    public function test_registry_accepts_all_supported_lesson_material_kinds(): void
    {
        $lesson = $this->createLesson();
        foreach (['video', 'pdf', 'notes', 'file'] as $kind) {
            $id = DB::table('lesson_materials')->insertGetId([
                'lesson_id' => $lesson->id,
                'kind' => $kind,
                'title' => $kind,
            ]);
            DB::table('lesson_materials')->where('id', $id)->update(['kind' => 'pdf']);
            DB::table('lesson_materials')->where('id', $id)->update(['kind' => $kind]);
            $this->assertDatabaseHas('lesson_materials', ['id' => $id, 'kind' => $kind]);
        }
    }

    public function test_sqlite_kind_migration_can_be_reapplied_without_removing_owner_guards(): void
    {
        $migration = require database_path('migrations/2026_10_22_120000_restore_sqlite_lesson_material_kind_constraint.php');
        $ownerTriggers = DB::table('sqlite_master')->where('type', 'trigger')
            ->whereIn('name', ['lesson_materials_owner_insert', 'lesson_materials_owner_update'])
            ->orderBy('name')->get(['name', 'sql'])->all();
        $this->assertCount(2, $ownerTriggers);

        $migration->down();
        $this->assertSame(0, DB::table('sqlite_master')->where('type', 'trigger')
            ->whereIn('name', ['lesson_materials_kind_insert', 'lesson_materials_kind_update'])->count());
        $this->assertEquals($ownerTriggers, DB::table('sqlite_master')->where('type', 'trigger')
            ->whereIn('name', ['lesson_materials_owner_insert', 'lesson_materials_owner_update'])
            ->orderBy('name')->get(['name', 'sql'])->all());

        $migration->up();
        $lesson = $this->createLesson();
        $this->expectException(QueryException::class);
        DB::table('lesson_materials')->insert(['lesson_id' => $lesson->id, 'kind' => 'unknown', 'title' => 'Invalid']);
    }

    public function test_sqlite_kind_migration_refuses_existing_invalid_data_without_changing_it(): void
    {
        $migration = require database_path('migrations/2026_10_22_120000_restore_sqlite_lesson_material_kind_constraint.php');
        $migration->down();
        $lesson = $this->createLesson();
        $id = DB::table('lesson_materials')->insertGetId(['lesson_id' => $lesson->id, 'kind' => 'unknown', 'title' => 'Invalid']);

        try {
            $migration->up();
            $this->fail('Migration must reject existing invalid kinds.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Correct invalid lesson material kinds before restoring the constraint.', $exception->getMessage());
        }

        $this->assertDatabaseHas('lesson_materials', ['id' => $id, 'kind' => 'unknown']);
        $this->assertSame(0, DB::table('sqlite_master')->where('type', 'trigger')
            ->whereIn('name', ['lesson_materials_kind_insert', 'lesson_materials_kind_update'])->count());
    }

    public function test_registry_migration_down_removes_only_registry_table(): void
    {
        $renameMigration = require database_path('migrations/2026_07_25_120000_rename_course_topics_to_lessons.php');
        $createMigration = require database_path('migrations/2026_07_17_120000_create_course_topic_materials_table.php');

        $renameMigration->down();
        $createMigration->down();

        $this->assertFalse(Schema::hasTable('lesson_materials'));
        $this->assertFalse(Schema::hasTable('course_topic_materials'));
        $this->assertTrue(Schema::hasTable('course_topics'));
    }

    private function createLesson(): Lesson
    {
        $subject = Subject::query()->create([
            'slug' => 'anatomy',
            'title' => 'Анатомия',
        ]);
        $course = Course::query()->create([
            'subject_id' => $subject->id,
            'slug' => 'osteology',
            'title' => 'Остеология',
            'price_cents' => 100000,
        ]);

        return Lesson::query()->create([
            'course_id' => $course->id,
            'slug' => 'bones',
            'title' => 'Кости',
        ]);
    }
}
