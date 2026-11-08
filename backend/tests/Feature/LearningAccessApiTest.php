<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Lesson;
use App\Models\LessonLearningContent;
use App\Models\OrderItem;
use App\Models\SellableEntity;
use App\Models\Subject;
use App\Models\User;
use App\Models\UserEntitlementGrant;
use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class LearningAccessApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_without_grants_sees_exact_none_access_subject_summary(): void
    {
        $user = User::factory()->create(['role' => 'user_no_purchase']);
        [$subject] = $this->createCourseWithLessons(lessonCount: 2);
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/learning')
            ->assertOk()
            ->assertExactJson([
                'data' => [
                    'subjects' => [$this->expectedIndexSubject($subject, requiredLessonsCount: 2)],
                    'empty' => false,
                ],
            ]);
    }

    public function test_client_supplied_access_hint_does_not_unlock_learning(): void
    {
        $user = User::factory()->create(['role' => 'user_no_purchase']);
        [$subject] = $this->createCourseWithLessons(lessonCount: 1);
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/learning?access=full&paid=true')
            ->assertOk()
            ->assertExactJson([
                'data' => [
                    'subjects' => [$this->expectedIndexSubject($subject, requiredLessonsCount: 1)],
                    'empty' => false,
                ],
            ]);
    }

    public function test_free_preview_subject_course_and_lesson_are_visible_without_grant(): void
    {
        $user = User::factory()->create(['role' => 'user_no_purchase']);
        [$subject, $course, [$freeLesson, $paidLesson]] = $this->createCourseWithLessons('free-preview', lessonCount: 2, freePreviewLessonIndexes: [1]);
        $this->createPublishedContent($freeLesson, [
            'blocks' => [
                [
                    'type' => 'paragraph',
                    'text' => 'Открытый бесплатный урок.',
                ],
            ],
            'toc' => [],
        ]);
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/learning?access=full&paid=true')
            ->assertOk()
            ->assertExactJson([
                'data' => [
                    'subjects' => [[
                        'id' => $subject->id,
                        'slug' => $subject->slug,
                        'title' => $subject->title,
                        'sortOrder' => 0,
                        'access' => 'none',
                        'requiredLessonsCount' => 2,
                        'ownedLessonsCount' => 0,
                        'sellableCoursesCount' => 1,
                        'boughtCoursesCount' => 0,
                        'freeLessonAvailable' => true,
                        'canonicalPath' => "/lk/learning/{$subject->slug}",
                    ]],
                    'empty' => false,
                ],
            ]);

        $this->assertLearningIndexContainsNoDescendants($response);
        $this->getJson("/api/v1/learning/subjects/{$subject->slug}")
            ->assertOk()
            ->assertExactJson([
                'data' => [
                    'subject' => [
                        'id' => $subject->id,
                        'slug' => $subject->slug,
                        'title' => $subject->title,
                        'canonicalPath' => "/lk/learning/{$subject->slug}",
                    ],
                    'access' => 'none',
                    'requiredLessonsCount' => 2,
                    'ownedLessonsCount' => 0,
                    'courses' => [[
                        'id' => $course->id,
                        'itemId' => "course:{$subject->slug}:{$course->slug}",
                        'slug' => $course->slug,
                        'title' => $course->title,
                        'shortTitle' => null,
                        'sortOrder' => 0,
                        'canonicalPath' => "/lk/learning/{$subject->slug}/{$course->slug}",
                        'publicPath' => "/subjects/{$subject->slug}/{$course->slug}",
                        'access' => 'none',
                        'requiredLessonsCount' => 2,
                        'ownedLessonsCount' => 0,
                        'completedLessonsCount' => 0,
                        'freeLessonAvailable' => true,
                    ]],
                    'empty' => false,
                ],
            ]);

        $this->getJson("/api/v1/learning/subjects/{$subject->slug}/courses/{$course->slug}")
            ->assertOk()
            ->assertExactJson([
                'data' => [
                    'subject' => [
                        'id' => $subject->id,
                        'slug' => $subject->slug,
                        'title' => $subject->title,
                        'canonicalPath' => "/lk/learning/{$subject->slug}",
                    ],
                    'course' => [
                        'id' => $course->id,
                        'itemId' => "course:{$subject->slug}:{$course->slug}",
                        'slug' => $course->slug,
                        'title' => $course->title,
                        'shortTitle' => null,
                        'sortOrder' => 0,
                        'canonicalPath' => "/lk/learning/{$subject->slug}/{$course->slug}",
                        'publicPath' => "/subjects/{$subject->slug}/{$course->slug}",
                        'freeLessonAvailable' => true,
                    ],
                    'access' => 'none',
                    'requiredLessonsCount' => 2,
                    'ownedLessonsCount' => 0,
                    'completedLessonsCount' => 0,
                    'lessons' => [[
                        'id' => $freeLesson->id,
                        'slug' => $freeLesson->slug,
                        'title' => $freeLesson->title,
                        'sortOrder' => $freeLesson->sort_order,
                        'access' => 'full',
                        'availability' => 'free_preview',
                        'canonicalPath' => "/lk/learning/{$subject->slug}/{$course->slug}/{$freeLesson->slug}",
                        ...$this->unavailableTestPayload(),
                    ],
                        [
                            'id' => $paidLesson->id,
                            'slug' => $paidLesson->slug,
                            'title' => $paidLesson->title,
                            'sortOrder' => $paidLesson->sort_order,
                            'access' => 'none',
                            'availability' => 'locked',
                            'canonicalPath' => "/lk/learning/{$subject->slug}/{$course->slug}/{$paidLesson->slug}",
                            ...$this->unavailableTestPayload(),
                        ],
                    ],
                    'empty' => false,
                ],
            ]);

        $this->getJson("/api/v1/learning/subjects/{$subject->slug}/courses/{$course->slug}/lessons/{$freeLesson->slug}")
            ->assertOk()
            ->assertJsonPath('data.id', $freeLesson->id)
            ->assertJsonPath('data.access', 'full')
            ->assertJsonPath('data.content.status', 'published')
            ->assertJsonPath('data.content.body.blocks.0.text', 'Открытый бесплатный урок.');

        $this->getJson($this->lessonUrl($freeLesson).'?freeLessonAvailable=true')
            ->assertOk()
            ->assertJsonPath('data.id', $freeLesson->id)
            ->assertJsonPath('data.access', 'full');

        $this->getJson($this->lessonUrl($paidLesson).'?freeLessonAvailable=true')
            ->assertForbidden()
            ->assertJsonPath('error.code', 'LEARNING_ACCESS_DENIED');
    }

    public function test_unauthenticated_user_cannot_read_learning_or_lesson(): void
    {
        [, , [$lesson]] = $this->createCourseWithLessons(lessonCount: 1);

        $this->getJson('/api/v1/learning')->assertUnauthorized();
        $this->getJson($this->lessonUrl($lesson))->assertUnauthorized();
    }

    public function test_user_without_access_permission_cannot_read_learning_index(): void
    {
        $user = User::factory()->create(['role' => 'guest']);
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/learning')->assertForbidden();
    }

    public function test_user_with_lesson_grant_sees_exact_partial_subject_summary(): void
    {
        $user = User::factory()->create(['role' => 'user_no_purchase']);
        [$subject, $course, $lessons] = $this->createCourseWithLessons(lessonCount: 2);
        $this->createGrant($user, $lessons[0]);
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/learning')
            ->assertOk()
            ->assertExactJson([
                'data' => [
                    'subjects' => [[
                        'id' => $subject->id,
                        'slug' => $subject->slug,
                        'title' => $subject->title,
                        'sortOrder' => 0,
                        'access' => 'partial',
                        'requiredLessonsCount' => 2,
                        'ownedLessonsCount' => 1,
                        'sellableCoursesCount' => 1,
                        'boughtCoursesCount' => 0,
                        'freeLessonAvailable' => false,
                        'canonicalPath' => "/lk/learning/{$subject->slug}",
                    ]],
                    'empty' => false,
                ],
            ]);

        $this->assertLearningIndexContainsNoDescendants($response);
        $this->assertTrue($course->exists);
    }

    public function test_user_with_all_course_lesson_grants_sees_exact_full_subject_summary(): void
    {
        $user = User::factory()->create(['role' => 'user_no_purchase']);
        [$subject, $course, $lessons] = $this->createCourseWithLessons(lessonCount: 2);
        $this->createGrant($user, $lessons[0]);
        $this->createGrant($user, $lessons[1]);
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/learning')
            ->assertOk()
            ->assertExactJson([
                'data' => [
                    'subjects' => [[
                        'id' => $subject->id,
                        'slug' => $subject->slug,
                        'title' => $subject->title,
                        'sortOrder' => 0,
                        'access' => 'full',
                        'requiredLessonsCount' => 2,
                        'ownedLessonsCount' => 2,
                        'sellableCoursesCount' => 1,
                        'boughtCoursesCount' => 1,
                        'freeLessonAvailable' => false,
                        'canonicalPath' => "/lk/learning/{$subject->slug}",
                    ]],
                    'empty' => false,
                ],
            ]);

        $this->assertLearningIndexContainsNoDescendants($response);
        $this->assertTrue($course->exists);
    }

    public function test_learning_index_sorts_bought_subjects_by_curriculum_order_then_title(): void
    {
        $user = User::factory()->create(['role' => 'user_no_purchase']);
        [$firstSubject, , [$firstLesson]] = $this->createCourseWithLessons('ordered-first');
        [$alphaSubject, , [$alphaLesson]] = $this->createCourseWithLessons('ordered-alpha');
        [$betaSubject, , [$betaLesson]] = $this->createCourseWithLessons('ordered-beta');

        $firstSubject->forceFill(['sort_order' => 10, 'title' => 'First'])->save();
        $alphaSubject->forceFill(['sort_order' => 20, 'title' => 'Alpha'])->save();
        $betaSubject->forceFill(['sort_order' => 20, 'title' => 'Beta'])->save();

        $this->createGrant($user, $firstLesson);
        $this->createGrant($user, $alphaLesson);
        $this->createGrant($user, $betaLesson);
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/learning')
            ->assertOk()
            ->assertExactJson([
                'data' => [
                    'subjects' => [
                        [
                            'id' => $firstSubject->id,
                            'slug' => $firstSubject->slug,
                            'title' => 'First',
                            'sortOrder' => 10,
                            'access' => 'full',
                            'requiredLessonsCount' => 1,
                            'ownedLessonsCount' => 1,
                            'sellableCoursesCount' => 1,
                            'boughtCoursesCount' => 1,
                            'freeLessonAvailable' => false,
                            'canonicalPath' => "/lk/learning/{$firstSubject->slug}",
                        ],
                        [
                            'id' => $alphaSubject->id,
                            'slug' => $alphaSubject->slug,
                            'title' => 'Alpha',
                            'sortOrder' => 20,
                            'access' => 'full',
                            'requiredLessonsCount' => 1,
                            'ownedLessonsCount' => 1,
                            'sellableCoursesCount' => 1,
                            'boughtCoursesCount' => 1,
                            'freeLessonAvailable' => false,
                            'canonicalPath' => "/lk/learning/{$alphaSubject->slug}",
                        ],
                        [
                            'id' => $betaSubject->id,
                            'slug' => $betaSubject->slug,
                            'title' => 'Beta',
                            'sortOrder' => 20,
                            'access' => 'full',
                            'requiredLessonsCount' => 1,
                            'ownedLessonsCount' => 1,
                            'sellableCoursesCount' => 1,
                            'boughtCoursesCount' => 1,
                            'freeLessonAvailable' => false,
                            'canonicalPath' => "/lk/learning/{$betaSubject->slug}",
                        ],
                    ],
                    'empty' => false,
                ],
            ]);

        $this->assertLearningIndexContainsNoDescendants($response);
    }

    public function test_learning_index_query_count_does_not_scale_with_subject_count(): void
    {
        $user = User::factory()->create(['role' => 'user_no_purchase']);

        for ($index = 0; $index < 8; $index++) {
            [, , [$lesson]] = $this->createCourseWithLessons("query-count-{$index}");
            $this->createGrant($user, $lesson);
        }

        Sanctum::actingAs($user);
        // Includes one account-state read by the migration hold middleware, independent of subject count.
        $this->expectsDatabaseQueryCount(6);

        $response = $this->getJson('/api/v1/learning')
            ->assertOk()
            ->assertJsonCount(8, 'data.subjects');

        $this->assertLearningIndexContainsNoDescendants($response);
    }

    public function test_learning_index_excludes_reserved_subjects_and_keeps_active_empty_subjects(): void
    {
        $user = User::factory()->create(['role' => 'user_no_purchase']);
        [$visibleSubject, , [$visibleLesson]] = $this->createCourseWithLessons('visible');
        [$lessonsSlugSubject, , [$lessonsSlugLesson]] = $this->createCourseWithLessons('reserved-lessons');
        [$favoritesSubject, , [$favoritesLesson]] = $this->createCourseWithLessons('reserved-favorites');
        [$zeroLessonSubject] = $this->createCourseWithLessons('zero-lesson', lessonCount: 0);

        $lessonsSlugSubject->forceFill(['slug' => 'lessons', 'title' => 'Reserved Lessons'])->save();
        $favoritesSubject->forceFill(['slug' => 'favorites', 'title' => 'Reserved Favorites'])->save();

        $this->createGrant($user, $visibleLesson);
        $this->createGrant($user, $lessonsSlugLesson);
        $this->createGrant($user, $favoritesLesson);
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/learning')
            ->assertOk()
            ->assertExactJson([
                'data' => [
                    'subjects' => [[
                        'id' => $visibleSubject->id,
                        'slug' => $visibleSubject->slug,
                        'title' => $visibleSubject->title,
                        'sortOrder' => 0,
                        'access' => 'full',
                        'requiredLessonsCount' => 1,
                        'ownedLessonsCount' => 1,
                        'sellableCoursesCount' => 1,
                        'boughtCoursesCount' => 1,
                        'freeLessonAvailable' => false,
                        'canonicalPath' => "/lk/learning/{$visibleSubject->slug}",
                    ], [
                        'id' => $zeroLessonSubject->id,
                        'slug' => $zeroLessonSubject->slug,
                        'title' => $zeroLessonSubject->title,
                        'sortOrder' => 0,
                        'access' => 'none',
                        'requiredLessonsCount' => 0,
                        'ownedLessonsCount' => 0,
                        'sellableCoursesCount' => 0,
                        'boughtCoursesCount' => 0,
                        'freeLessonAvailable' => false,
                        'canonicalPath' => "/lk/learning/{$zeroLessonSubject->slug}",
                    ]],
                    'empty' => false,
                ],
            ]);

        $this->assertLearningIndexContainsNoDescendants($response);
        $this->assertTrue($zeroLessonSubject->exists);
    }

    public function test_user_cannot_open_closed_lesson_directly(): void
    {
        $user = User::factory()->create(['role' => 'user_no_purchase']);
        [, , [$lesson]] = $this->createCourseWithLessons(lessonCount: 1);
        Sanctum::actingAs($user);

        $this->getJson($this->lessonUrl($lesson))
            ->assertForbidden()
            ->assertJsonPath('error.code', 'LEARNING_ACCESS_DENIED');
    }

    public function test_missing_lesson_uses_learning_not_found_error_contract(): void
    {
        $user = User::factory()->create(['role' => 'user_no_purchase']);
        [$subject, $course] = $this->createCourseWithLessons('missing-lesson', lessonCount: 0);
        Sanctum::actingAs($user);

        $this->getJson("/api/v1/learning/subjects/{$subject->slug}/courses/{$course->slug}/lessons/missing")
            ->assertNotFound()
            ->assertJsonPath('error.code', 'LEARNING_LESSON_NOT_FOUND');
    }

    public function test_client_supplied_access_hint_does_not_unlock_direct_lesson(): void
    {
        $user = User::factory()->create(['role' => 'user_no_purchase']);
        [, , [$lesson]] = $this->createCourseWithLessons(lessonCount: 1);
        Sanctum::actingAs($user);

        $this->getJson($this->lessonUrl($lesson).'?access=full&paid=true')
            ->assertForbidden()
            ->assertJsonPath('error.code', 'LEARNING_ACCESS_DENIED');
    }

    public function test_user_can_open_owned_lesson_directly(): void
    {
        $user = User::factory()->create(['role' => 'user_no_purchase']);
        [$subject, $course, [$lesson]] = $this->createCourseWithLessons(lessonCount: 1);
        $this->createGrant($user, $lesson);
        Sanctum::actingAs($user);

        $response = $this->getJson($this->lessonUrl($lesson))
            ->assertOk()
            ->assertJsonPath('data.id', $lesson->id)
            ->assertJsonPath('data.access', 'full')
            ->assertJsonPath('data.subject.id', $subject->id)
            ->assertJsonPath('data.course.id', $course->id)
            ->assertJsonPath('data.content.status', 'metadata_only');

        $this->assertNoMaterialPayload($response);
    }

    public function test_learning_does_not_require_verified_phone_for_free_content(): void
    {
        $user = User::factory()->create([
            'phone_verified_at' => null,
            'role' => 'user_no_purchase',
        ]);
        [, , [$lesson]] = $this->createCourseWithLessons(lessonCount: 1, freePreviewLessonIndexes: [1]);
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/learning')
            ->assertOk();

        $this->getJson($this->lessonUrl($lesson))
            ->assertOk()
            ->assertJsonPath('data.id', $lesson->id);
    }

    public function test_owned_lesson_metadata_contract_does_not_expose_body_media_or_files(): void
    {
        $user = User::factory()->create(['role' => 'user_no_purchase']);
        [, , [$lesson]] = $this->createCourseWithLessons(lessonCount: 1);
        $this->createGrant($user, $lesson);
        Sanctum::actingAs($user);

        $response = $this->getJson($this->lessonUrl($lesson))
            ->assertOk()
            ->assertJsonPath('data.content.status', 'metadata_only');

        $this->assertNoMaterialPayload($response);
    }

    public function test_owned_lesson_with_published_content_returns_lesson_blocks_contract(): void
    {
        $user = User::factory()->create(['role' => 'user_no_purchase']);
        [, , [$lesson]] = $this->createCourseWithLessons(lessonCount: 1);
        $this->createPublishedContent($lesson, [
            'blocks' => [
                [
                    'type' => 'heading',
                    'level' => 2,
                    'id' => 'intro',
                    'text' => 'Введение',
                ],
                [
                    'type' => 'paragraph',
                    'text' => 'Текст учебного материала.',
                ],
                [
                    'type' => 'list',
                    'items' => ['Первый пункт', 'Второй пункт'],
                ],
            ],
            'toc' => [
                [
                    'id' => 'intro',
                    'title' => 'Введение',
                ],
            ],
        ]);
        $this->createGrant($user, $lesson);
        Sanctum::actingAs($user);

        $response = $this->getJson($this->lessonUrl($lesson))
            ->assertOk()
            ->assertJsonPath('data.content.status', 'published')
            ->assertJsonPath('data.content.format', 'lesson_blocks_v1')
            ->assertJsonPath('data.content.version', 1)
            ->assertJsonPath('data.content.body.blocks.0.type', 'heading')
            ->assertJsonPath('data.content.body.blocks.0.text', 'Введение')
            ->assertJsonPath('data.content.body.blocks.1.type', 'paragraph')
            ->assertJsonPath('data.content.body.blocks.1.text', 'Текст учебного материала.')
            ->assertJsonPath('data.content.body.toc.0.id', 'intro');

        $this->assertNoForbiddenMaterialFields($response);
        $this->assertNoForbiddenStudentKeys($response);
        $this->assertStringNotContainsString('source_fingerprint_hash', $response->getContent() ?: '');
        $this->assertStringNotContainsString('import_status', $response->getContent() ?: '');
    }

    public function test_learning_index_returns_only_subject_summary_for_published_lesson(): void
    {
        $user = User::factory()->create(['role' => 'user_no_purchase']);
        [$subject, , [$lesson]] = $this->createCourseWithLessons(lessonCount: 1);
        $this->createPublishedContent($lesson);
        $this->createGrant($user, $lesson);
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/learning')
            ->assertOk()
            ->assertExactJson([
                'data' => [
                    'subjects' => [[
                        'id' => $subject->id,
                        'slug' => $subject->slug,
                        'title' => $subject->title,
                        'sortOrder' => 0,
                        'access' => 'full',
                        'requiredLessonsCount' => 1,
                        'ownedLessonsCount' => 1,
                        'sellableCoursesCount' => 1,
                        'boughtCoursesCount' => 1,
                        'freeLessonAvailable' => false,
                        'canonicalPath' => "/lk/learning/{$subject->slug}",
                    ]],
                    'empty' => false,
                ],
            ]);

        $this->assertLearningIndexContainsNoDescendants($response);
    }

    public function test_seeded_catalog_exposes_all_canonical_subjects_through_free_previews_without_grants(): void
    {
        $user = User::factory()->create(['role' => 'user_no_purchase']);

        $this->seed(CatalogSeeder::class);
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/learning')
            ->assertOk()
            ->assertJsonCount(14, 'data.subjects')
            ->assertJsonPath('data.empty', false);

        $subjects = collect($response->json('data.subjects'));

        $this->assertSame([
            'anatomiya',
            'gistologiya',
            'latinskij-yazyk',
            'bioorganicheskaya-himiya',
            'mikrobiologiya',
            'fiziologiya',
            'biohimiya',
            'patologicheskaya-anatomiya',
            'patofiziologiya',
            'farmakologiya',
            'propedevtika-vnutrennih-boleznej',
            'hirurgiya',
            'nevrologiya',
            'luchevaya-diagnostika',
        ], $subjects->pluck('slug')->all());
        $this->assertTrue($subjects->every(fn (array $subject): bool => $subject['access'] === 'none'));
        $this->assertTrue($subjects->every(fn (array $subject): bool => $subject['ownedLessonsCount'] === 0));
        $this->assertTrue($subjects->every(fn (array $subject): bool => $subject['freeLessonAvailable'] === true));
    }

    public function test_closed_lesson_does_not_expose_published_body(): void
    {
        $user = User::factory()->create(['role' => 'user_no_purchase']);
        [, , [$lesson]] = $this->createCourseWithLessons(lessonCount: 1);
        $this->createPublishedContent($lesson);
        Sanctum::actingAs($user);

        $this->getJson($this->lessonUrl($lesson).'?access=full&paid=true')
            ->assertForbidden()
            ->assertJsonPath('error.code', 'LEARNING_ACCESS_DENIED')
            ->assertJsonMissingPath('data')
            ->assertJsonMissingPath('data.content')
            ->assertJsonMissingPath('data.content.body');
    }

    public function test_unsafe_published_body_falls_back_to_metadata_only(): void
    {
        $user = User::factory()->create(['role' => 'user_no_purchase']);
        [, , [$lesson]] = $this->createCourseWithLessons(lessonCount: 1);
        $this->createPublishedContent($lesson, [
            'blocks' => [
                [
                    'type' => 'paragraph',
                    'text' => 'Скачать файл {file::123}',
                ],
            ],
            'toc' => [],
        ]);
        $this->createGrant($user, $lesson);
        Sanctum::actingAs($user);

        $response = $this->getJson($this->lessonUrl($lesson))
            ->assertOk()
            ->assertJsonPath('data.content.status', 'metadata_only');

        $this->assertNoMaterialPayload($response);
        $this->assertStringNotContainsString('{file::123}', $response->getContent() ?: '');
    }

    public function test_unsafe_nested_media_fields_fall_back_to_metadata_only(): void
    {
        $user = User::factory()->create(['role' => 'user_no_purchase']);
        [, , [$lesson]] = $this->createCourseWithLessons(lessonCount: 1);
        $this->createPublishedContent($lesson, [
            'blocks' => [
                [
                    'type' => 'paragraph',
                    'text' => 'Материал с вложенной ссылкой.',
                    'attrs' => [
                        'signedUrl' => 'https://cdn.example.test/private.pdf',
                    ],
                ],
            ],
            'toc' => [],
        ]);
        $this->createGrant($user, $lesson);
        Sanctum::actingAs($user);

        $response = $this->getJson($this->lessonUrl($lesson))
            ->assertOk()
            ->assertJsonPath('data.content.status', 'metadata_only');

        $this->assertNoMaterialPayload($response);
        $this->assertStringNotContainsString('https://cdn.example.test/private.pdf', $response->getContent() ?: '');
        $this->assertStringNotContainsString('signedUrl', $response->getContent() ?: '');
    }

    public function test_internal_legacy_asset_reference_blocks_fall_back_to_metadata_only(): void
    {
        $user = User::factory()->create(['role' => 'user_no_purchase']);
        [, , [$lesson]] = $this->createCourseWithLessons(lessonCount: 1);
        $this->createPublishedContent($lesson, [
            'blocks' => [
                [
                    'type' => 'paragraph',
                    'text' => 'Текст перед защищённым материалом.',
                ],
                [
                    'type' => 'legacy_protected_asset_reference',
                    'assetKind' => 'legacy_video',
                    'legacyAssetId' => 55,
                    'origin' => 'legacy_shortcode',
                ],
            ],
            'toc' => [],
        ]);
        $this->createGrant($user, $lesson);
        Sanctum::actingAs($user);

        $response = $this->getJson($this->lessonUrl($lesson))
            ->assertOk()
            ->assertJsonPath('data.content.status', 'metadata_only');

        $this->assertNoMaterialPayload($response);
        $this->assertNoForbiddenStudentKeys($response);
        $this->assertStringNotContainsString('legacy_protected_asset_reference', $response->getContent() ?: '');
        $this->assertStringNotContainsString('legacyAssetId', $response->getContent() ?: '');
        $this->assertStringNotContainsString('legacy_video', $response->getContent() ?: '');
    }

    public function test_direct_protected_material_url_in_text_falls_back_to_metadata_only(): void
    {
        $user = User::factory()->create(['role' => 'user_no_purchase']);
        [, , [$lesson]] = $this->createCourseWithLessons(lessonCount: 1);
        $this->createPublishedContent($lesson, [
            'blocks' => [
                [
                    'type' => 'paragraph',
                    'text' => 'Скачать конспект: https://cdn.example.test/private/lesson.pdf',
                ],
            ],
            'toc' => [],
        ]);
        $this->createGrant($user, $lesson);
        Sanctum::actingAs($user);

        $response = $this->getJson($this->lessonUrl($lesson))
            ->assertOk()
            ->assertJsonPath('data.content.status', 'metadata_only');

        $this->assertNoMaterialPayload($response);
        $this->assertNoForbiddenStudentKeys($response);
        $this->assertStringNotContainsString('https://cdn.example.test/private/lesson.pdf', $response->getContent() ?: '');
    }

    public function test_other_users_grant_does_not_unlock_learning_or_direct_lesson(): void
    {
        $owner = User::factory()->create(['role' => 'user_no_purchase']);
        $viewer = User::factory()->create(['role' => 'user_no_purchase']);
        [$subject, , [$lesson]] = $this->createCourseWithLessons(lessonCount: 1);
        $this->createGrant($owner, $lesson);
        Sanctum::actingAs($viewer);

        $this->getJson('/api/v1/learning')
            ->assertOk()
            ->assertExactJson([
                'data' => [
                    'subjects' => [$this->expectedIndexSubject($subject, requiredLessonsCount: 1)],
                    'empty' => false,
                ],
            ]);

        $this->getJson($this->lessonUrl($lesson))
            ->assertForbidden()
            ->assertJsonPath('error.code', 'LEARNING_ACCESS_DENIED');
    }

    public function test_non_active_grants_do_not_unlock_learning_or_direct_lesson(): void
    {
        $statuses = [
            UserEntitlementGrant::STATUS_PENDING,
            UserEntitlementGrant::STATUS_REVOKED,
            UserEntitlementGrant::STATUS_EXPIRED,
        ];
        $expectedSubjects = [];

        foreach ($statuses as $status) {
            $user = User::factory()->create(['role' => 'user_no_purchase']);
            [$subject, , [$lesson]] = $this->createCourseWithLessons("course-{$status}", lessonCount: 1);
            $this->createGrant($user, $lesson, $status);
            Sanctum::actingAs($user);
            $expectedSubjects[] = $this->expectedIndexSubject($subject, requiredLessonsCount: 1);
            usort(
                $expectedSubjects,
                fn (array $first, array $second): int => $first['title'] <=> $second['title'],
            );

            $this->getJson('/api/v1/learning')
                ->assertOk()
                ->assertExactJson([
                    'data' => [
                        'subjects' => $expectedSubjects,
                        'empty' => false,
                    ],
                ]);

            $this->getJson($this->lessonUrl($lesson))
                ->assertForbidden()
                ->assertJsonPath('error.code', 'LEARNING_ACCESS_DENIED');
        }
    }

    public function test_expired_active_grant_does_not_unlock_learning_or_direct_lesson(): void
    {
        $user = User::factory()->create(['role' => 'user_no_purchase']);
        [$subject, , [$lesson]] = $this->createCourseWithLessons(lessonCount: 1);
        $this->createGrant($user, $lesson, UserEntitlementGrant::STATUS_ACTIVE, expired: true);
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/learning')
            ->assertOk()
            ->assertExactJson([
                'data' => [
                    'subjects' => [$this->expectedIndexSubject($subject, requiredLessonsCount: 1)],
                    'empty' => false,
                ],
            ]);

        $this->getJson($this->lessonUrl($lesson))
            ->assertForbidden()
            ->assertJsonPath('error.code', 'LEARNING_ACCESS_DENIED');
    }

    public function test_inactive_lesson_course_or_subject_is_hidden_and_not_openable(): void
    {
        $user = User::factory()->create(['role' => 'user_no_purchase']);
        [$inactiveLessonSubject, $inactiveLessonCourse, [$inactiveLesson]] = $this->createCourseWithLessons('inactive-lesson', lessonCount: 1);
        [$inactiveCourseSubject, $inactiveCourse, [$lessonInInactiveCourse]] = $this->createCourseWithLessons('inactive-course', lessonCount: 1);
        [$inactiveSubject, $courseInInactiveSubject, [$lessonInInactiveSubject]] = $this->createCourseWithLessons('inactive-subject', lessonCount: 1);

        $inactiveLesson->forceFill(['is_active' => false])->save();
        $inactiveCourse->forceFill(['is_active' => false])->save();
        $inactiveSubject->forceFill(['is_active' => false])->save();

        $this->createGrant($user, $inactiveLesson);
        $this->createGrant($user, $lessonInInactiveCourse);
        $this->createGrant($user, $lessonInInactiveSubject);
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/learning')
            ->assertOk()
            ->assertExactJson([
                'data' => [
                    'subjects' => [
                        $this->expectedIndexSubject($inactiveCourseSubject),
                        $this->expectedIndexSubject($inactiveLessonSubject),
                    ],
                    'empty' => false,
                ],
            ]);

        foreach ([$inactiveLesson, $lessonInInactiveCourse, $lessonInInactiveSubject] as $lesson) {
            $this->getJson($this->lessonUrl($lesson))
                ->assertNotFound()
                ->assertJsonPath('error.code', 'LEARNING_LESSON_NOT_FOUND');
        }

        $this->assertTrue($inactiveLessonSubject->exists);
        $this->assertTrue($inactiveLessonCourse->exists);
        $this->assertTrue($inactiveCourseSubject->exists);
        $this->assertTrue($courseInInactiveSubject->exists);
    }

    /**
     * @param  array<int, int>  $freePreviewLessonIndexes
     * @return array{Subject, Course, array<int, Lesson>}
     */
    private function createCourseWithLessons(
        string $courseSlug = 'course',
        int $lessonCount = 1,
        array $freePreviewLessonIndexes = [],
    ): array {
        $subject = Subject::query()->create([
            'slug' => 'subject-'.$courseSlug,
            'title' => 'Subject '.$courseSlug,
            'is_active' => true,
        ]);

        $course = Course::query()->create([
            'subject_id' => $subject->id,
            'slug' => $courseSlug,
            'title' => 'Course '.$courseSlug,
            'price_cents' => 100000,
            'is_active' => true,
        ]);

        $lessons = [];
        for ($index = 1; $index <= $lessonCount; $index++) {
            $lessons[] = Lesson::query()->create([
                'course_id' => $course->id,
                'slug' => "lesson-{$courseSlug}-{$index}",
                'title' => "Lesson {$index}",
                'sort_order' => $index,
                'is_active' => true,
                'is_free_preview' => in_array($index, $freePreviewLessonIndexes, true),
            ]);
        }

        SellableEntity::query()->create([
            'sellable_kind' => OrderItem::KIND_COURSE,
            'entity_type' => OrderItem::KIND_COURSE,
            'entity_id' => $course->id,
            'sku' => $course->itemId(),
            'title' => $course->title,
            'currency' => 'RUB',
            'price_cents' => 100000,
            'price_version' => 1,
            'status' => SellableEntity::STATUS_ACTIVE,
            'metadata' => ['source' => 'test'],
        ]);

        return [$subject, $course, $lessons];
    }

    /**
     * @return array<string, int|string|bool>
     */
    private function expectedIndexSubject(
        Subject $subject,
        string $access = 'none',
        int $requiredLessonsCount = 0,
        int $ownedLessonsCount = 0,
        int $sellableCoursesCount = 1,
        int $boughtCoursesCount = 0,
        bool $freeLessonAvailable = false,
    ): array {
        return [
            'id' => $subject->id,
            'slug' => $subject->slug,
            'title' => $subject->title,
            'sortOrder' => (int) $subject->sort_order,
            'access' => $access,
            'requiredLessonsCount' => $requiredLessonsCount,
            'ownedLessonsCount' => $ownedLessonsCount,
            'sellableCoursesCount' => $requiredLessonsCount === 0 ? 0 : $sellableCoursesCount,
            'boughtCoursesCount' => $boughtCoursesCount,
            'freeLessonAvailable' => $freeLessonAvailable,
            'canonicalPath' => "/lk/learning/{$subject->slug}",
        ];
    }

    private function lessonUrl(Lesson $lesson): string
    {
        $lesson->loadMissing('course.subject');

        return "/api/v1/learning/subjects/{$lesson->course->subject->slug}/courses/{$lesson->course->slug}/lessons/{$lesson->slug}";
    }

    /** @return array{testAvailable:bool,testPassed:bool,testProgress:null} */
    private function unavailableTestPayload(): array
    {
        return [
            'testAvailable' => false,
            'testPassed' => false,
            'testProgress' => null,
        ];
    }

    private function createGrant(
        User $user,
        Lesson $lesson,
        string $status = UserEntitlementGrant::STATUS_ACTIVE,
        bool $expired = false,
    ): UserEntitlementGrant {
        return UserEntitlementGrant::query()->create([
            'user_id' => $user->id,
            'atom_kind' => UserEntitlementGrant::ATOM_LESSON,
            'atom_id' => $lesson->id,
            'status' => $status,
            'source_type' => UserEntitlementGrant::SOURCE_TEST_FIXTURE,
            'source_id' => "lesson-{$lesson->id}",
            'idempotency_key' => "lesson-{$lesson->id}-{$user->id}-{$status}-".($expired ? 'expired' : 'current'),
            'granted_at' => $status === UserEntitlementGrant::STATUS_ACTIVE ? now() : null,
            'revoked_at' => $status === UserEntitlementGrant::STATUS_REVOKED ? now() : null,
            'expires_at' => $expired ? now()->subMinute() : null,
        ]);
    }

    /**
     * @param  array<string, mixed>|null  $body
     */
    private function createPublishedContent(Lesson $lesson, ?array $body = null): LessonLearningContent
    {
        return LessonLearningContent::query()->create([
            'lesson_id' => $lesson->id,
            'status' => LessonLearningContent::STATUS_PUBLISHED,
            'format' => LessonLearningContent::FORMAT_LESSON_BLOCKS_V1,
            'version' => 1,
            'body' => $body ?? [
                'blocks' => [
                    [
                        'type' => 'paragraph',
                        'text' => 'Опубликованный учебный текст.',
                    ],
                ],
                'toc' => [],
            ],
            'published_at' => now(),
            'import_status' => LessonLearningContent::IMPORT_STATUS_MANUAL,
        ]);
    }

    private function assertLearningIndexContainsNoDescendants(TestResponse $response): void
    {
        $this->assertArrayDoesNotContainKeysRecursively($response->json(), [
            'body',
            'content',
            'courses',
            'guides',
            'html',
            'lessons',
            'lessons',
        ]);
    }

    private function assertNoMaterialPayload(TestResponse $response): void
    {
        foreach ([
            'data.body',
            'data.html',
            'data.video',
            'data.videoUrl',
            'data.media',
            'data.mediaUrl',
            'data.files',
            'data.fileUrl',
            'data.downloadUrl',
            'data.signedUrl',
            'data.contentUrl',
            'data.content.body',
            'data.content.html',
            'data.content.video',
            'data.content.videoUrl',
            'data.content.media',
            'data.content.mediaUrl',
            'data.content.files',
            'data.content.fileUrl',
            'data.content.downloadUrl',
            'data.content.signedUrl',
            'data.content.contentUrl',
        ] as $path) {
            $response->assertJsonMissingPath($path);
        }
    }

    private function assertNoForbiddenMaterialFields(TestResponse $response): void
    {
        foreach ([
            'data.video',
            'data.videoUrl',
            'data.media',
            'data.mediaUrl',
            'data.files',
            'data.fileUrl',
            'data.downloadUrl',
            'data.signedUrl',
            'data.contentUrl',
            'data.content.video',
            'data.content.videoUrl',
            'data.content.media',
            'data.content.mediaUrl',
            'data.content.files',
            'data.content.fileUrl',
            'data.content.downloadUrl',
            'data.content.signedUrl',
            'data.content.contentUrl',
            'data.content.body.video',
            'data.content.body.videoUrl',
            'data.content.body.media',
            'data.content.body.mediaUrl',
            'data.content.body.files',
            'data.content.body.fileUrl',
            'data.content.body.downloadUrl',
            'data.content.body.signedUrl',
            'data.content.body.contentUrl',
        ] as $path) {
            $response->assertJsonMissingPath($path);
        }
    }

    private function assertNoForbiddenStudentKeys(TestResponse $response): void
    {
        $this->assertArrayDoesNotContainKeysRecursively($response->json(), [
            'contentUrl',
            'downloadUrl',
            'fileUrl',
            'files',
            'import_status',
            'manual_review_reason',
            'media',
            'mediaUrl',
            'metadata',
            'providerId',
            'signedUrl',
            'source_fingerprint_hash',
            'source_id',
            'source_table',
            'storageKey',
            'video',
            'videoUrl',
        ]);
    }

    /**
     * @param  array<mixed>  $payload
     * @param  array<int, string>  $forbiddenKeys
     */
    private function assertArrayDoesNotContainKeysRecursively(array $payload, array $forbiddenKeys): void
    {
        foreach ($payload as $key => $value) {
            if (is_string($key)) {
                $this->assertNotContains($key, $forbiddenKeys);
            }

            if (is_array($value)) {
                $this->assertArrayDoesNotContainKeysRecursively($value, $forbiddenKeys);
            }
        }
    }
}
