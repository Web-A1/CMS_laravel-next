<?php

namespace Tests\Feature;

use App\Models\Guide;
use App\Models\Lesson;
use App\Models\LessonMaterial;
use App\Models\Subject;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class GuideSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_existing_lesson_material_and_new_guide_material_have_independent_owners(): void
    {
        $lesson = $this->lesson();
        $guide = Guide::factory()->create();
        $old = LessonMaterial::query()->create(['lesson_id' => $lesson->id, 'kind' => 'notes', 'title' => 'Notes']);
        $pdf = $guide->materials()->create(['kind' => 'pdf', 'title' => 'PDF']);
        $this->assertNull($old->guide_id);
        $this->assertNull($pdf->lesson_id);
        $this->assertSame($guide->id, $pdf->guide->id);
        $this->assertSame([$old->id], $lesson->materials()->pluck('id')->all());
        $this->assertFalse($guide->is_active);
    }

    #[DataProvider('invalidOwners')]
    public function test_database_rejects_invalid_owners_on_insert(bool $lessonOwner, bool $guideOwner, string $kind): void
    {
        $lesson = $this->lesson();
        $guide = Guide::factory()->create();
        $this->expectException(QueryException::class);
        DB::table('lesson_materials')->insert(['lesson_id' => $lessonOwner ? $lesson->id : null, 'guide_id' => $guideOwner ? $guide->id : null, 'kind' => $kind, 'title' => 'Invalid']);
    }

    public static function invalidOwners(): array
    {
        return ['no owner' => [false, false, 'pdf'], 'both owners' => [true, true, 'pdf'], 'guide video' => [false, true, 'video'], 'guide notes' => [false, true, 'notes']];
    }

    private function lesson(): Lesson
    {
        $subject = Subject::query()->create(['slug' => 'subject', 'title' => 'Subject']);
        $course = $subject->courses()->create(['slug' => 'course', 'title' => 'Course', 'price_cents' => 100]);

        return $course->lessons()->create(['slug' => 'lesson', 'title' => 'Lesson']);
    }

    public function test_database_rejects_owner_corruption_on_update(): void
    {
        $guide = Guide::factory()->create();
        $pdf = $guide->materials()->create(['kind' => 'pdf', 'title' => 'PDF']);
        $this->expectException(QueryException::class);
        DB::table('lesson_materials')->where('id', $pdf->id)->update(['guide_id' => null]);
    }
}
