<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Lesson;
use App\Models\LessonMaterial;
use App\Models\LessonMaterialFile;
use App\Models\Subject;
use App\Models\User;
use App\Models\UserEntitlementGrant;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class LearningFileMaterialSelectionApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_user_cannot_list_file_materials(): void
    {
        [$subject, $course, $lesson] = $this->createLesson();

        $this->getJson($this->fileMaterialsUrl($subject, $course, $lesson))
            ->assertUnauthorized();
    }

    public function test_selection_allows_unverified_users_but_keeps_permission_check(): void
    {
        [$subject, $course, $lesson] = $this->createLesson();
        $url = $this->fileMaterialsUrl($subject, $course, $lesson);

        $unverified = User::factory()->create([
            'role' => 'user_no_purchase',
            'phone_verified_at' => null,
        ]);
        $this->createGrant($unverified, $lesson, 'unverified');
        Sanctum::actingAs($unverified);

        $this->getJson($url)
            ->assertOk()
            ->assertExactJson(['data' => ['files' => []]]);

        $withoutPermission = User::factory()->create(['role' => 'guest']);
        Sanctum::actingAs($withoutPermission);

        $this->getJson($url)->assertForbidden();
    }

    public function test_entitlement_is_denied_before_material_or_source_queries(): void
    {
        $user = User::factory()->create(['role' => 'user_no_purchase']);
        [$subject, $course, $lesson] = $this->createLesson();
        $material = $this->createMaterial($lesson, LessonMaterial::KIND_PDF, true, 'Закрытый PDF');
        $this->createSource($material, 'private-source-sentinel');
        Sanctum::actingAs($user);

        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $this->getJson($this->fileMaterialsUrl($subject, $course, $lesson))
            ->assertForbidden()
            ->assertExactJson($this->accessDeniedPayload());

        $this->assertFalse(collect($queries)->contains(
            fn (string $query): bool => str_contains($query, 'lesson_materials')
                || str_contains($query, 'lesson_material_files')
                || str_contains($query, 'protected_file_deliveries'),
        ));
    }

    public function test_entitled_user_lists_only_published_pdfs_in_manifest_order_without_source_state(): void
    {
        $user = User::factory()->create(['role' => 'user_no_purchase']);
        [$subject, $course, $lesson] = $this->createLesson();

        $later = $this->createMaterial($lesson, LessonMaterial::KIND_PDF, true, 'Дополнительный материал', 20);
        $first = $this->createMaterial($lesson, LessonMaterial::KIND_PDF, true, 'Конспект урока', 10);
        $unsafeSource = $this->createMaterial($lesson, LessonMaterial::KIND_PDF, true, 'Рабочая тетрадь', 30);
        $this->createSource($later, 'protected-sources/later.pdf');
        $this->createSource($unsafeSource, '../private-source.pdf', '../private-name.pdf');
        $this->createMaterial($lesson, LessonMaterial::KIND_PDF, false, 'Черновик PDF', 1);
        $this->createMaterial($lesson, LessonMaterial::KIND_VIDEO, true, 'Видеолекция', 2);
        $this->createMaterial($lesson, LessonMaterial::KIND_NOTES, true, 'Текстовый конспект', 3);
        $this->createGrant($user, $lesson);
        Sanctum::actingAs($user);

        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $response = $this->getJson($this->fileMaterialsUrl($subject, $course, $lesson))
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertExactJson([
                'data' => [
                    'files' => [
                        [
                            'materialId' => $first->id,
                            'title' => 'Конспект урока',
                        ],
                        [
                            'materialId' => $later->id,
                            'title' => 'Дополнительный материал',
                        ],
                        [
                            'materialId' => $unsafeSource->id,
                            'title' => 'Рабочая тетрадь',
                        ],
                    ],
                ],
            ]);

        $payload = $response->getContent() ?: '';
        foreach ([
            'private-source',
            'sourceObjectKey',
            'sourceSha256',
            'downloadFilename',
            'deliveryId',
            'copyCode',
            'canRequestAccess',
        ] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $payload);
        }

        $this->assertFalse(collect($queries)->contains(
            fn (string $query): bool => str_contains($query, 'lesson_material_files')
                || str_contains($query, 'protected_file_deliveries'),
        ));
    }

    public function test_free_preview_user_lists_published_pdfs_without_grant(): void
    {
        $user = User::factory()->create(['role' => 'user_no_purchase']);
        [$subject, $course, $lesson] = $this->createLesson();
        $lesson->forceFill(['is_free_preview' => true])->save();
        $material = $this->createMaterial($lesson, LessonMaterial::KIND_PDF, true, 'Бесплатный PDF');
        Sanctum::actingAs($user);

        $this->getJson($this->fileMaterialsUrl($subject, $course, $lesson))
            ->assertOk()
            ->assertExactJson([
                'data' => [
                    'files' => [[
                        'materialId' => $material->id,
                        'title' => 'Бесплатный PDF',
                    ]],
                ],
            ]);
    }

    public function test_mismatched_or_inactive_lesson_chain_is_not_found(): void
    {
        $user = User::factory()->create(['role' => 'user_no_purchase']);
        [$subject, $course, $lesson] = $this->createLesson();
        [, $otherCourse, $otherLesson] = $this->createLesson('physiology', 'physiology-course', 'heart');
        $this->createGrant($user, $lesson);
        $this->createGrant($user, $otherLesson);
        Sanctum::actingAs($user);

        $this->getJson($this->fileMaterialsUrl($subject, $otherCourse, $otherLesson))
            ->assertNotFound()
            ->assertExactJson($this->lessonNotFoundPayload());

        $lesson->update(['is_active' => false]);

        $this->getJson($this->fileMaterialsUrl($subject, $course, $lesson))
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
        bool $published,
        string $title,
        int $sortOrder = 0,
    ): LessonMaterial {
        return LessonMaterial::query()->create([
            'lesson_id' => $lesson->id,
            'kind' => $kind,
            'title' => $title,
            'sort_order' => $sortOrder,
            'is_published' => $published,
        ]);
    }

    private function createSource(
        LessonMaterial $material,
        string $objectKey,
        string $filename = 'notes.pdf',
    ): LessonMaterialFile {
        return LessonMaterialFile::query()->create([
            'lesson_material_id' => $material->id,
            'source_object_key' => $objectKey,
            'source_sha256' => str_repeat('a', 64),
            'source_size_bytes' => 1024,
            'source_mime_type' => 'application/pdf',
            'download_filename' => $filename,
        ]);
    }

    private function createGrant(
        User $user,
        Lesson $lesson,
        string $suffix = 'default',
    ): UserEntitlementGrant {
        return UserEntitlementGrant::query()->create([
            'user_id' => $user->id,
            'atom_kind' => UserEntitlementGrant::ATOM_LESSON,
            'atom_id' => $lesson->id,
            'status' => UserEntitlementGrant::STATUS_ACTIVE,
            'source_type' => UserEntitlementGrant::SOURCE_TEST_FIXTURE,
            'source_id' => "file-selection-{$lesson->id}-{$suffix}",
            'idempotency_key' => "file-selection-{$user->id}-{$lesson->id}-{$suffix}",
            'granted_at' => now(),
        ]);
    }

    private function fileMaterialsUrl(Subject $subject, Course $course, Lesson $lesson): string
    {
        return "/api/v1/learning/subjects/{$subject->slug}/courses/{$course->slug}/lessons/{$lesson->slug}/file-materials";
    }

    /**
     * @return array{error: array{code: string, message: string}}
     */
    private function accessDeniedPayload(): array
    {
        return ['error' => [
            'code' => 'LEARNING_ACCESS_DENIED',
            'message' => 'У пользователя нет доступа к этому уроку.',
        ]];
    }

    /**
     * @return array{error: array{code: string, message: string}}
     */
    private function lessonNotFoundPayload(): array
    {
        return ['error' => [
            'code' => 'LEARNING_LESSON_NOT_FOUND',
            'message' => 'Урок не найден.',
        ]];
    }
}
