<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Lesson;
use App\Models\Subject;
use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CatalogSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeder_deactivates_stale_lessons_with_stale_parent_nodes(): void
    {
        $staleSubject = Subject::query()->create([
            'slug' => 'stale-subject',
            'title' => 'Устаревший предмет',
            'sort_order' => 999,
            'is_active' => true,
        ]);
        $staleSubjectCourse = $this->createCourse($staleSubject, 'stale-subject-course');
        $staleSubjectLesson = $this->createLesson($staleSubjectCourse, 'stale-subject-lesson');

        $existingSubject = Subject::query()->create([
            'slug' => 'anatomiya',
            'title' => 'Старое название',
            'sort_order' => 1,
            'is_active' => true,
        ]);
        $staleCourse = $this->createCourse($existingSubject, 'stale-course');
        $staleCourseLesson = $this->createLesson($staleCourse, 'stale-course-lesson');

        $existingCourse = $this->createCourse($existingSubject, 'artrologiya');
        $staleLesson = $this->createLesson($existingCourse, 'stale-lesson');

        $this->seed(CatalogSeeder::class);

        $this->assertFalse($staleSubject->fresh()->is_active);
        $this->assertFalse($staleSubjectCourse->fresh()->is_active);
        $this->assertFalse($staleSubjectLesson->fresh()->is_active);

        $this->assertTrue($existingSubject->fresh()->is_active);
        $this->assertFalse($staleCourse->fresh()->is_active);
        $this->assertFalse($staleCourseLesson->fresh()->is_active);

        $this->assertTrue($existingCourse->fresh()->is_active);
        $this->assertFalse($staleLesson->fresh()->is_active);
    }

    private function createCourse(Subject $subject, string $slug): Course
    {
        return Course::query()->create([
            'subject_id' => $subject->id,
            'slug' => $slug,
            'title' => 'Тестовый курс',
            'short_title' => 'Тестовый курс',
            'price_cents' => 0,
            'sort_order' => 999,
            'is_active' => true,
        ]);
    }

    private function createLesson(Course $course, string $slug): Lesson
    {
        return Lesson::query()->create([
            'course_id' => $course->id,
            'slug' => $slug,
            'title' => 'Тестовый урок',
            'sort_order' => 999,
            'is_active' => true,
            'is_free_preview' => false,
        ]);
    }
}
