<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Lesson;
use App\Models\LessonAssessment;
use App\Models\LessonAssessmentAsset;
use App\Models\LessonAssessmentVersion;
use App\Models\Subject;
use App\Services\Learning\LessonAssessmentPublisher;
use Database\Seeders\LessonAssessmentSeeder;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class LessonAssessmentPublicationCommandTest extends TestCase
{
    use RefreshDatabase;

    private const LESSON_SLUG = 'soedineniya-kostej-tulovishcha-i-cherepa';

    private const KEY_SHA256 = '9b28fdad5337337fc2edd5689bfdaec63765b82b6a726b815c23f7c0466ff6d7';

    private const CONTENT_VERSION = 38;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('protected_files.source_disk', 'lesson-assessment-assets');
        config()->set('filesystems.disks.lesson-assessment-assets', [
            'driver' => 'local',
            'root' => storage_path('framework/testing/lesson-assessment-assets'),
            'throw' => true,
        ]);
        Storage::fake('lesson-assessment-assets');
    }

    public function test_it_refuses_a_wrong_key_hash_without_writing_or_publishing(): void
    {
        $this->seedDraft();

        $this->artisan('lesson-assessments:publish', [
            'lessonSlug' => self::LESSON_SLUG,
            '--content-version' => (string) self::CONTENT_VERSION,
            '--confirm-key-sha256' => str_repeat('0', 64),
        ])
            ->expectsOutput('status=error code=confirmation_mismatch')
            ->assertExitCode(1);

        $this->assertSame(LessonAssessmentVersion::STATUS_DRAFT, LessonAssessmentVersion::query()->sole()->status);
        $this->assertSame([], Storage::disk('lesson-assessment-assets')->allFiles());
    }

    public function test_it_does_not_publish_a_same_slug_draft_from_another_catalog_chain(): void
    {
        $subject = Subject::query()->create([
            'slug' => 'other-subject',
            'title' => 'Другой предмет',
            'sort_order' => 1,
            'is_active' => true,
        ]);
        $course = Course::query()->create([
            'subject_id' => $subject->id,
            'slug' => 'other-course',
            'title' => 'Другой курс',
            'price_cents' => 0,
            'sort_order' => 1,
            'is_active' => true,
        ]);
        $lesson = Lesson::query()->create([
            'course_id' => $course->id,
            'slug' => self::LESSON_SLUG,
            'title' => 'Другое совпадение slug',
            'sort_order' => 1,
            'is_active' => true,
            'is_free_preview' => false,
        ]);
        $assessment = LessonAssessment::query()->create(['lesson_id' => $lesson->id]);
        $version = new LessonAssessmentVersion([
            'lesson_assessment_id' => $assessment->id,
            'schema_version' => 2,
            'content_version' => self::CONTENT_VERSION,
            'scoring_policy' => LessonAssessmentSeeder::validatedFixture()['scoringPolicy'],
            'source_assignment_sha256' => str_repeat('a', 64),
            'source_key_sha256' => self::KEY_SHA256,
            'sections' => [],
            'questions' => [],
            'answer_key' => [],
        ]);
        $version->status = LessonAssessmentVersion::STATUS_DRAFT;
        $version->save();

        $this->artisan('lesson-assessments:publish', [
            'lessonSlug' => self::LESSON_SLUG,
            '--content-version' => (string) self::CONTENT_VERSION,
            '--confirm-key-sha256' => self::KEY_SHA256,
        ])
            ->expectsOutput('status=error code=publication_failed')
            ->assertExitCode(1);

        $this->assertSame(LessonAssessmentVersion::STATUS_DRAFT, $version->fresh()->status);
        $this->assertSame([], Storage::disk('lesson-assessment-assets')->allFiles());
    }

    public function test_it_copies_verified_assets_retires_the_previous_version_and_is_idempotent(): void
    {
        $draft = $this->seedDraft();
        $sectionsSnapshot = $draft->sections;
        $previousPublished = $this->createPreviousPublishedVersion($draft->assessment);
        LessonAssessmentAsset::query()->create([
            'lesson_assessment_version_id' => $draft->id,
            'asset_key' => 'noncanonical',
            'disk' => 'lesson-assessment-assets',
            'object_key' => 'lesson-assessments/noncanonical.png',
            'sha256' => str_repeat('a', 64),
            'mime_type' => 'image/png',
            'width' => 1,
            'height' => 1,
            'sort_order' => 999,
        ]);

        $this->artisan('lesson-assessments:publish', [
            'lessonSlug' => self::LESSON_SLUG,
            '--content-version' => (string) self::CONTENT_VERSION,
            '--confirm-key-sha256' => self::KEY_SHA256,
        ])
            ->expectsOutput(sprintf(
                'status=published version=%d assets=9 key_sha256=%s',
                self::CONTENT_VERSION,
                self::KEY_SHA256,
            ))
            ->assertExitCode(0);

        $draft->refresh();
        $previousPublished->refresh();
        $this->assertSame(LessonAssessmentVersion::STATUS_PUBLISHED, $draft->status);
        $this->assertSame($sectionsSnapshot, $draft->sections);
        $this->assertNotNull($draft->published_at);
        $this->assertSame(LessonAssessmentVersion::STATUS_RETIRED, $previousPublished->status);
        $this->assertNotNull($previousPublished->published_at);
        $this->assertDatabaseCount('lesson_assessment_assets', 9);
        $this->assertDatabaseMissing('lesson_assessment_assets', ['asset_key' => 'noncanonical']);

        foreach ($draft->assets()->orderBy('sort_order')->get() as $asset) {
            $this->assertSame('lesson-assessment-assets', $asset->disk);
            $extension = match ($asset->mime_type) {
                'image/png' => 'png',
                'image/webp' => 'webp',
            };
            $this->assertSame(
                "lesson-assessments/{$draft->lesson_assessment_id}/v".self::CONTENT_VERSION."/{$asset->sha256}.{$extension}",
                $asset->object_key,
            );
            Storage::disk($asset->disk)->assertExists($asset->object_key);
            $this->assertSame($asset->sha256, hash('sha256', Storage::disk($asset->disk)->get($asset->object_key)));
        }

        $frozenPublishedAt = now()->subHour()->setMicrosecond(0);
        $draft->published_at = $frozenPublishedAt;
        $draft->save();
        $registry = $this->registrySnapshot($draft);

        $this->artisan('lesson-assessments:publish', [
            'lessonSlug' => self::LESSON_SLUG,
            '--content-version' => (string) self::CONTENT_VERSION,
            '--confirm-key-sha256' => self::KEY_SHA256,
        ])->assertExitCode(0);

        $draft->refresh();
        $this->assertSame(1, LessonAssessmentVersion::query()
            ->where('status', LessonAssessmentVersion::STATUS_PUBLISHED)
            ->count());
        $this->assertDatabaseCount('lesson_assessment_assets', 9);
        $this->assertSame($sectionsSnapshot, $draft->sections);
        $this->assertTrue($frozenPublishedAt->equalTo($draft->published_at));
        $this->assertSame($registry, $this->registrySnapshot($draft));
    }

    public function test_it_rejects_a_draft_whose_sections_do_not_match_the_canonical_snapshot(): void
    {
        $draft = $this->seedDraft();
        $draft->sections = [['id' => 'fundamentals', 'title' => 'Tampered section title']];
        $draft->save();

        $this->artisan('lesson-assessments:publish', [
            'lessonSlug' => self::LESSON_SLUG,
            '--content-version' => (string) self::CONTENT_VERSION,
            '--confirm-key-sha256' => self::KEY_SHA256,
        ])
            ->expectsOutput('status=error code=publication_failed')
            ->assertExitCode(1);

        $draft->refresh();
        $this->assertSame(LessonAssessmentVersion::STATUS_DRAFT, $draft->status);
        $this->assertSame(
            [['id' => 'fundamentals', 'title' => 'Tampered section title']],
            $draft->sections,
        );
        $this->assertDatabaseCount('lesson_assessment_assets', 0);
        $this->assertSame([], Storage::disk('lesson-assessment-assets')->allFiles());
    }

    public function test_storage_put_failure_happens_before_database_transition(): void
    {
        $draft = $this->seedDraft();
        $previousPublished = $this->createPreviousPublishedVersion($draft->assessment);
        $disk = Mockery::mock(FilesystemAdapter::class);
        $disk->shouldReceive('exists')->once()->andReturn(false);
        $disk->shouldReceive('put')->once()->andReturn(false);
        Storage::set('lesson-assessment-assets', $disk);

        $this->assertStorageFailureLeavesDatabaseUntouched($draft, $previousPublished);
    }

    public function test_repeated_publish_rejects_a_noncanonical_published_registry_without_mutation(): void
    {
        $draft = $this->seedDraft();
        $this->artisan('lesson-assessments:publish', [
            'lessonSlug' => self::LESSON_SLUG,
            '--content-version' => (string) self::CONTENT_VERSION,
            '--confirm-key-sha256' => self::KEY_SHA256,
        ])->assertExitCode(0);
        $draft->refresh();
        $publishedAt = $draft->published_at;
        LessonAssessmentAsset::query()->create([
            'lesson_assessment_version_id' => $draft->id,
            'asset_key' => 'noncanonical-published',
            'disk' => 'lesson-assessment-assets',
            'object_key' => 'lesson-assessments/noncanonical-published.png',
            'sha256' => str_repeat('b', 64),
            'mime_type' => 'image/png',
            'width' => 1,
            'height' => 1,
            'sort_order' => 999,
        ]);

        $this->artisan('lesson-assessments:publish', [
            'lessonSlug' => self::LESSON_SLUG,
            '--content-version' => (string) self::CONTENT_VERSION,
            '--confirm-key-sha256' => self::KEY_SHA256,
        ])
            ->expectsOutput('status=error code=publication_failed')
            ->assertExitCode(1);

        $draft->refresh();
        $this->assertSame(LessonAssessmentVersion::STATUS_PUBLISHED, $draft->status);
        $this->assertTrue($publishedAt->equalTo($draft->published_at));
        $this->assertSame(10, $draft->assets()->count());
        $this->assertDatabaseHas('lesson_assessment_assets', ['asset_key' => 'noncanonical-published']);
    }

    public function test_storage_readback_failure_leaves_an_unreferenced_object_without_database_transition(): void
    {
        $draft = $this->seedDraft();
        $previousPublished = $this->createPreviousPublishedVersion($draft->assessment);
        $disk = Mockery::mock(FilesystemAdapter::class);
        $disk->shouldReceive('exists')->once()->andReturn(false);
        $disk->shouldReceive('put')->once()->andReturn(true);
        $disk->shouldReceive('get')->once()->andReturn('corrupted-storage-object');
        Storage::set('lesson-assessment-assets', $disk);

        $this->assertStorageFailureLeavesDatabaseUntouched($draft, $previousPublished);
    }

    private function seedDraft(): LessonAssessmentVersion
    {
        $this->createCanonicalLesson();
        $this->seed(LessonAssessmentSeeder::class);

        return LessonAssessmentVersion::query()->sole();
    }

    private function createPreviousPublishedVersion(LessonAssessment $assessment): LessonAssessmentVersion
    {
        $version = new LessonAssessmentVersion([
            'lesson_assessment_id' => $assessment->id,
            'schema_version' => 2,
            'content_version' => 1,
            'scoring_policy' => LessonAssessmentSeeder::validatedFixture()['scoringPolicy'],
            'source_assignment_sha256' => str_repeat('c', 64),
            'source_key_sha256' => str_repeat('d', 64),
            'sections' => [['id' => 'previous', 'title' => 'Previous published snapshot']],
            'questions' => [],
            'answer_key' => [],
            'published_at' => now(),
        ]);
        $version->status = LessonAssessmentVersion::STATUS_PUBLISHED;
        $version->save();

        return $version;
    }

    private function assertStorageFailureLeavesDatabaseUntouched(
        LessonAssessmentVersion $draft,
        LessonAssessmentVersion $previousPublished,
    ): void {
        try {
            app(LessonAssessmentPublisher::class)->publish(
                $draft,
                LessonAssessmentSeeder::validatedFixture(),
            );
            $this->fail('The storage failure published the assessment.');
        } catch (RuntimeException) {
            $draft->refresh();
            $previousPublished->refresh();
            $this->assertSame(LessonAssessmentVersion::STATUS_DRAFT, $draft->status);
            $this->assertSame(LessonAssessmentVersion::STATUS_PUBLISHED, $previousPublished->status);
            $this->assertDatabaseCount('lesson_assessment_assets', 0);
        }
    }

    /** @return list<array<string, int|string>> */
    private function registrySnapshot(LessonAssessmentVersion $version): array
    {
        return $version->assets()
            ->orderBy('asset_key')
            ->get()
            ->map(fn (LessonAssessmentAsset $asset): array => [
                'asset_key' => $asset->asset_key,
                'disk' => $asset->disk,
                'object_key' => $asset->object_key,
                'sha256' => $asset->sha256,
                'mime_type' => $asset->mime_type,
                'width' => $asset->width,
                'height' => $asset->height,
                'sort_order' => $asset->sort_order,
                'created_at' => $asset->created_at->format('Y-m-d H:i:s.uP'),
                'updated_at' => $asset->updated_at->format('Y-m-d H:i:s.uP'),
            ])
            ->all();
    }

    private function createCanonicalLesson(): Lesson
    {
        $subject = Subject::query()->create([
            'slug' => 'anatomiya',
            'title' => 'Анатомия',
            'sort_order' => 1,
            'is_active' => true,
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
            'slug' => self::LESSON_SLUG,
            'title' => 'Соединения костей',
            'sort_order' => 1,
            'is_active' => true,
            'is_free_preview' => false,
        ]);
    }
}
