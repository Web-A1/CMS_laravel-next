<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Lesson;
use App\Models\LessonAssessment;
use App\Models\LessonAssessmentVersion;
use App\Models\Subject;
use Database\Seeders\LessonAssessmentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class LessonAssessmentSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_seeds_the_canonical_active_lesson_as_an_idempotent_draft(): void
    {
        $fixture = LessonAssessmentSeeder::validatedFixture();
        $lesson = $this->createCanonicalLesson();

        $this->seed(LessonAssessmentSeeder::class);
        $firstVersion = LessonAssessmentVersion::query()->sole();
        $firstVersion->source_assignment_sha256 = str_repeat('a', 64);
        $firstVersion->sections = [];
        $firstVersion->questions = [];
        $firstVersion->answer_key = [];
        $firstVersion->save();

        $this->seed(LessonAssessmentSeeder::class);

        $assessment = LessonAssessment::query()->sole();
        $version = LessonAssessmentVersion::query()->sole();

        $this->assertSame($lesson->id, $assessment->lesson_id);
        $this->assertSame($firstVersion->id, $version->id);
        $this->assertSame(LessonAssessmentVersion::STATUS_DRAFT, $version->status);
        $this->assertSame(2, $version->schema_version);
        $this->assertSame($fixture['scoringPolicy'], $version->scoring_policy);
        $this->assertSame($fixture['contentVersion'], $version->content_version);
        $this->assertSame(
            '9f32f66d9d8957061a1e0738a7adc444f9a850b1e3da1235716c51317a9def87',
            $version->source_assignment_sha256,
        );
        $this->assertSame($fixture['sections'], $version->sections);
        $this->assertCount(48, $version->questions);
        $this->assertCount(48, $version->answer_key);
    }

    public function test_it_refreshes_an_existing_published_canonical_version_from_the_fixture(): void
    {
        $fixture = LessonAssessmentSeeder::validatedFixture();
        $lesson = $this->createCanonicalLesson();
        $assessment = LessonAssessment::query()->create(['lesson_id' => $lesson->id]);
        $version = new LessonAssessmentVersion([
            'lesson_assessment_id' => $assessment->id,
            'schema_version' => 2,
            'content_version' => 37,
            'scoring_policy' => $fixture['scoringPolicy'],
            'source_assignment_sha256' => str_repeat('a', 64),
            'source_key_sha256' => str_repeat('b', 64),
            'sections' => [['id' => 'published', 'title' => 'Published section snapshot']],
            'questions' => [],
            'answer_key' => [],
        ]);
        $version->status = LessonAssessmentVersion::STATUS_PUBLISHED;
        $version->save();

        $this->seed(LessonAssessmentSeeder::class);

        $version->refresh();
        $draft = LessonAssessmentVersion::query()
            ->where('content_version', 38)
            ->sole();
        $this->assertSame(LessonAssessmentVersion::STATUS_PUBLISHED, $version->status);
        $this->assertSame(str_repeat('a', 64), $version->source_assignment_sha256);
        $this->assertSame(str_repeat('b', 64), $version->source_key_sha256);
        $this->assertSame([['id' => 'published', 'title' => 'Published section snapshot']], $version->sections);
        $this->assertSame([], $version->questions);
        $this->assertSame([], $version->answer_key);
        $this->assertSame(LessonAssessmentVersion::STATUS_DRAFT, $draft->status);
        $this->assertSame($fixture['sources']['assignmentSha256'], $draft->source_assignment_sha256);
        $this->assertSame($fixture['sources']['keySha256'], $draft->source_key_sha256);
        $this->assertSame($fixture['sections'], $draft->sections);
        $this->assertSame($fixture['questions'], $draft->questions);
        $this->assertSame($fixture['answerKey'], $draft->answer_key);
        $this->assertDatabaseCount('lesson_assessment_versions', 2);
        $this->assertDatabaseHas('lesson_assessment_versions', [
            'lesson_assessment_id' => $assessment->id,
            'content_version' => 37,
            'status' => LessonAssessmentVersion::STATUS_PUBLISHED,
        ]);
        $this->assertDatabaseHas('lesson_assessment_versions', [
            'lesson_assessment_id' => $assessment->id,
            'content_version' => 38,
            'status' => LessonAssessmentVersion::STATUS_DRAFT,
        ]);
    }

    public function test_it_rejects_an_inactive_catalog_chain(): void
    {
        $this->createCanonicalLesson(subjectActive: false);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Active canonical lesson catalog chain was not found.');

        $this->seed(LessonAssessmentSeeder::class);
    }

    private function createCanonicalLesson(bool $subjectActive = true): Lesson
    {
        $subject = Subject::query()->create([
            'slug' => 'anatomiya',
            'title' => 'Анатомия',
            'sort_order' => 1,
            'is_active' => $subjectActive,
        ]);
        $course = Course::query()->create([
            'subject_id' => $subject->id,
            'slug' => 'artrologiya',
            'title' => 'Артрология',
            'price_cents' => 0,
            'sort_order' => 1,
            'is_active' => true,
        ]);

        return Lesson::query()->create([
            'course_id' => $course->id,
            'slug' => 'soedineniya-kostej-tulovishcha-i-cherepa',
            'title' => 'Соединения костей',
            'sort_order' => 1,
            'is_active' => true,
            'is_free_preview' => false,
        ]);
    }
}
