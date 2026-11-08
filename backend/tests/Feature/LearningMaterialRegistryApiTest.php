<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\LegacyProtectedAssetMapping;
use App\Models\Lesson;
use App\Models\LessonLearningContent;
use App\Models\LessonMaterial;
use App\Models\Subject;
use App\Models\User;
use App\Models\UserEntitlementGrant;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class LearningMaterialRegistryApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_entitled_user_receives_only_published_safe_metadata_in_manifest_order(): void
    {
        $user = User::factory()->create(['role' => 'user_no_purchase']);
        [$subject, $course, $lesson] = $this->createLesson();
        [, , $otherLesson] = $this->createLesson('physiology', 'physiology-course', 'heart');

        $first = $this->createMaterial($lesson, LessonMaterial::KIND_PDF, 'Конспект PDF', 10, true);
        $second = $this->createMaterial($lesson, LessonMaterial::KIND_NOTES, 'Краткий конспект', 10, true);
        $this->createMaterial($lesson, LessonMaterial::KIND_VIDEO, 'Видеолекция', 20, true);
        $this->createMaterial($lesson, LessonMaterial::KIND_FILE, 'Черновик', 0, false);
        $this->createMaterial($otherLesson, LessonMaterial::KIND_FILE, 'Материал другого урока', 0, true);
        $this->createLegacyLocatorDriftFixture($lesson, 'legacy-locator-sentinel-20260717');
        $this->createGrant($user, $lesson);
        Sanctum::actingAs($user);

        $this->assertLessThan($second->id, $first->id);

        $response = $this->getJson($this->materialsUrl($subject, $course, $lesson))
            ->assertOk()
            ->assertExactJson([
                'data' => [
                    'materials' => [
                        [
                            'kind' => 'pdf',
                            'title' => 'Конспект PDF',
                        ],
                        [
                            'kind' => 'notes',
                            'title' => 'Краткий конспект',
                        ],
                        [
                            'kind' => 'video',
                            'title' => 'Видеолекция',
                        ],
                    ],
                ],
            ]);

        $payload = $response->getContent() ?: '';
        foreach ([
            'legacy-locator-sentinel-20260717',
            'providerId',
            'storageKey',
            'signedUrl',
            'downloadUrl',
            'token',
            'metadata',
            'is_published',
            'sort_order',
        ] as $forbiddenValue) {
            $this->assertStringNotContainsString($forbiddenValue, $payload);
        }
    }

    public function test_access_is_denied_before_registry_query(): void
    {
        $user = User::factory()->create(['role' => 'user_no_purchase']);
        [$subject, $course, $lesson] = $this->createLesson();
        $this->createMaterial($lesson, LessonMaterial::KIND_VIDEO, 'Закрытая видеолекция', 0, true);
        Sanctum::actingAs($user);

        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $response = $this->getJson($this->materialsUrl($subject, $course, $lesson))
            ->assertForbidden()
            ->assertExactJson([
                'error' => [
                    'code' => 'LEARNING_ACCESS_DENIED',
                    'message' => 'У пользователя нет доступа к этому уроку.',
                ],
            ]);

        $response->assertJsonMissingPath('data');
        $this->assertFalse(collect($queries)->contains(
            fn (string $query): bool => str_contains($query, 'lesson_materials')
        ));
    }

    public function test_free_preview_user_receives_manifest_without_grant(): void
    {
        $user = User::factory()->create(['role' => 'user_no_purchase']);
        [$subject, $course, $lesson] = $this->createLesson();
        $lesson->forceFill(['is_free_preview' => true])->save();
        $this->createMaterial($lesson, LessonMaterial::KIND_VIDEO, 'Бесплатная видеолекция', 10, true);
        Sanctum::actingAs($user);

        $this->getJson($this->materialsUrl($subject, $course, $lesson))
            ->assertOk()
            ->assertExactJson([
                'data' => [
                    'materials' => [[
                        'kind' => 'video',
                        'title' => 'Бесплатная видеолекция',
                    ]],
                ],
            ]);
    }

    public function test_empty_published_registry_returns_an_empty_array(): void
    {
        $user = User::factory()->create(['role' => 'user_no_purchase']);
        [$subject, $course, $lesson] = $this->createLesson();
        $this->createMaterial($lesson, LessonMaterial::KIND_PDF, 'Черновик', 0, false);
        $this->createGrant($user, $lesson);
        Sanctum::actingAs($user);

        $this->getJson($this->materialsUrl($subject, $course, $lesson))
            ->assertOk()
            ->assertExactJson([
                'data' => [
                    'materials' => [],
                ],
            ]);
    }

    public function test_missing_or_inactive_lesson_uses_existing_not_found_contract(): void
    {
        $user = User::factory()->create(['role' => 'user_no_purchase']);
        [$subject, $course, $lesson] = $this->createLesson();
        Sanctum::actingAs($user);

        foreach ([$subject, $course, $lesson] as $model) {
            $model->update(['is_active' => false]);

            $this->getJson($this->materialsUrl($subject, $course, $lesson))
                ->assertNotFound()
                ->assertExactJson($this->lessonNotFoundPayload());

            $model->update(['is_active' => true]);
        }

        $this->getJson(
            "/api/v1/learning/subjects/{$subject->slug}/courses/{$course->slug}/lessons/missing/materials"
        )
            ->assertNotFound()
            ->assertExactJson($this->lessonNotFoundPayload());
    }

    /**
     * @return array{Subject, Course, Lesson}
     */
    private function createLesson(
        string $subjectSlug = 'anatomy',
        string $courseSlug = 'osteology',
        string $lessonSlug = 'bones',
    ): array {
        $subject = Subject::query()->create([
            'slug' => $subjectSlug,
            'title' => "Предмет {$subjectSlug}",
        ]);
        $course = Course::query()->create([
            'subject_id' => $subject->id,
            'slug' => $courseSlug,
            'title' => "Курс {$courseSlug}",
            'price_cents' => 100000,
        ]);
        $lesson = Lesson::query()->create([
            'course_id' => $course->id,
            'slug' => $lessonSlug,
            'title' => "Урок {$lessonSlug}",
        ]);

        return [$subject, $course, $lesson];
    }

    private function createMaterial(
        Lesson $lesson,
        string $kind,
        string $title,
        int $sortOrder,
        bool $published,
    ): LessonMaterial {
        return LessonMaterial::query()->create([
            'lesson_id' => $lesson->id,
            'kind' => $kind,
            'title' => $title,
            'sort_order' => $sortOrder,
            'is_published' => $published,
        ]);
    }

    private function createGrant(User $user, Lesson $lesson): void
    {
        UserEntitlementGrant::query()->create([
            'user_id' => $user->id,
            'atom_kind' => UserEntitlementGrant::ATOM_LESSON,
            'atom_id' => $lesson->id,
            'status' => UserEntitlementGrant::STATUS_ACTIVE,
            'source_type' => UserEntitlementGrant::SOURCE_TEST_FIXTURE,
            'source_id' => "material-registry-lesson-{$lesson->id}",
            'idempotency_key' => "material-registry-{$user->id}-{$lesson->id}",
            'granted_at' => now(),
        ]);
    }

    private function createLegacyLocatorDriftFixture(Lesson $lesson, string $sentinel): void
    {
        $content = LessonLearningContent::query()->create([
            'lesson_id' => $lesson->id,
            'status' => LessonLearningContent::STATUS_DRAFT,
            'format' => LessonLearningContent::FORMAT_LESSON_BLOCKS_V1,
            'import_status' => LessonLearningContent::IMPORT_STATUS_MANUAL,
        ]);

        LegacyProtectedAssetMapping::query()->create([
            'lesson_learning_content_id' => $content->id,
            'lesson_id' => $lesson->id,
            'asset_kind' => LegacyProtectedAssetMapping::ASSET_KIND_VIDEO,
            'legacy_asset_id' => 987654,
            'metadata' => [
                'providerId' => $sentinel,
                'storageKey' => $sentinel,
                'signedUrl' => "https://protected.invalid/{$sentinel}",
            ],
        ]);
    }

    private function materialsUrl(Subject $subject, Course $course, Lesson $lesson): string
    {
        return "/api/v1/learning/subjects/{$subject->slug}/courses/{$course->slug}/lessons/{$lesson->slug}/materials";
    }

    /**
     * @return array{error: array{code: string, message: string}}
     */
    private function lessonNotFoundPayload(): array
    {
        return [
            'error' => [
                'code' => 'LEARNING_LESSON_NOT_FOUND',
                'message' => 'Урок не найден.',
            ],
        ];
    }
}
