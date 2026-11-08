<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Lesson;
use App\Models\Subject;
use App\Models\User;
use App\Models\UserEntitlementGrant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AccessApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_without_grants_has_no_course_access(): void
    {
        $user = User::factory()->create(['role' => 'user_no_purchase']);
        [, $course] = $this->createCourseWithLessons(lessonCount: 1);
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/access/check', [
            'entity_type' => 'course',
            'entity_id' => $course->id,
        ])
            ->assertOk()
            ->assertJsonPath('data.access', 'none')
            ->assertJsonPath('data.required_atoms_count', 1)
            ->assertJsonPath('data.owned_atoms_count', 0);
    }

    public function test_backend_grant_operation_gives_access_to_one_lesson(): void
    {
        $admin = User::factory()->create(['role' => 'admin_full']);
        $user = User::factory()->create(['role' => 'user_no_purchase']);
        [, , [$lesson]] = $this->createCourseWithLessons(lessonCount: 1);
        Sanctum::actingAs($admin);

        $this->postJson('/api/v1/access/grants', [
            'user_id' => $user->id,
            'atoms' => [
                ['kind' => 'lesson', 'id' => $lesson->id],
            ],
            'source_type' => 'admin_grant',
            'source_id' => 'manual-1',
            'idempotency_key' => 'manual-lesson-1',
        ])
            ->assertCreated()
            ->assertJsonPath('created_count', 1)
            ->assertJsonPath('data.0.atom_kind', 'lesson')
            ->assertJsonPath('data.0.atom_id', $lesson->id);

        Sanctum::actingAs($user);

        $this->postJson('/api/v1/access/check', [
            'entity_type' => 'lesson',
            'entity_id' => $lesson->id,
        ])
            ->assertOk()
            ->assertJsonPath('data.access', 'full')
            ->assertJsonPath('data.required_atoms_count', 1)
            ->assertJsonPath('data.owned_atoms_count', 1);
    }

    public function test_course_access_is_computed_from_all_active_lessons(): void
    {
        $user = User::factory()->create(['role' => 'user_no_purchase']);
        [, $course, $lessons] = $this->createCourseWithLessons(lessonCount: 2);
        $this->createGrant($user, $lessons[0]);
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/access/check', [
            'entity_type' => 'course',
            'entity_id' => $course->id,
        ])
            ->assertOk()
            ->assertJsonPath('data.access', 'partial')
            ->assertJsonPath('data.required_atoms_count', 2)
            ->assertJsonPath('data.owned_atoms_count', 1);

        $this->createGrant($user, $lessons[1]);

        $this->postJson('/api/v1/access/check', [
            'entity_type' => 'course',
            'entity_id' => $course->id,
        ])
            ->assertOk()
            ->assertJsonPath('data.access', 'full')
            ->assertJsonPath('data.required_atoms_count', 2)
            ->assertJsonPath('data.owned_atoms_count', 2);
    }

    public function test_subject_access_is_computed_from_courses_and_lessons(): void
    {
        $user = User::factory()->create(['role' => 'user_no_purchase']);
        $subject = Subject::query()->create([
            'slug' => 'math',
            'title' => 'Math',
            'is_active' => true,
        ]);
        [, , [$firstLesson]] = $this->createCourseWithLessons($subject, 'algebra', lessonCount: 1);
        [, , [$secondLesson]] = $this->createCourseWithLessons($subject, 'geometry', lessonCount: 1);
        $this->createGrant($user, $firstLesson);
        $this->createGrant($user, $secondLesson);
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/access/check', [
            'entity_type' => 'subject',
            'entity_id' => $subject->id,
        ])
            ->assertOk()
            ->assertJsonPath('data.access', 'full')
            ->assertJsonPath('data.required_atoms_count', 2)
            ->assertJsonPath('data.owned_atoms_count', 2);
    }

    public function test_box_access_can_be_computed_from_explicit_atomic_components(): void
    {
        $user = User::factory()->create(['role' => 'user_no_purchase']);
        [, , $lessons] = $this->createCourseWithLessons(lessonCount: 2);
        $this->createGrant($user, $lessons[0]);
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/access/check', [
            'entity_type' => 'box',
            'atoms' => [
                ['kind' => 'lesson', 'id' => $lessons[0]->id],
                ['kind' => 'lesson', 'id' => $lessons[1]->id],
            ],
        ])
            ->assertOk()
            ->assertJsonPath('data.access', 'partial')
            ->assertJsonPath('data.required_atoms_count', 2)
            ->assertJsonPath('data.owned_atoms_count', 1);
    }

    public function test_repeated_grant_with_same_idempotency_key_does_not_duplicate_rows(): void
    {
        $admin = User::factory()->create(['role' => 'admin_full']);
        $user = User::factory()->create(['role' => 'user_no_purchase']);
        [, , [$lesson]] = $this->createCourseWithLessons(lessonCount: 1);
        Sanctum::actingAs($admin);

        $payload = [
            'user_id' => $user->id,
            'atoms' => [
                ['kind' => 'lesson', 'id' => $lesson->id],
            ],
            'source_type' => 'test_fixture',
            'source_id' => 'fixture-1',
            'idempotency_key' => 'fixture-lesson-1',
        ];

        $this->postJson('/api/v1/access/grants', $payload)
            ->assertCreated()
            ->assertJsonPath('created_count', 1);

        $this->postJson('/api/v1/access/grants', $payload)
            ->assertCreated()
            ->assertJsonPath('created_count', 0);

        $this->assertDatabaseCount('user_entitlement_grants', 1);
    }

    public function test_repeated_grant_with_same_idempotency_key_rejects_drift(): void
    {
        $admin = User::factory()->create(['role' => 'admin_full']);
        $user = User::factory()->create(['role' => 'user_no_purchase']);
        [, , [$lesson]] = $this->createCourseWithLessons(lessonCount: 1);
        Sanctum::actingAs($admin);

        $payload = [
            'user_id' => $user->id,
            'atoms' => [
                ['kind' => 'lesson', 'id' => $lesson->id],
            ],
            'source_type' => 'test_fixture',
            'source_id' => 'fixture-1',
            'idempotency_key' => 'fixture-lesson-1',
            'status' => 'active',
        ];

        $this->postJson('/api/v1/access/grants', $payload)->assertCreated();

        $this->postJson('/api/v1/access/grants', [
            ...$payload,
            'source_id' => 'fixture-2',
        ])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'ACCESS_GRANT_INVALID_REQUEST');

        $this->assertDatabaseCount('user_entitlement_grants', 1);
    }

    public function test_batch_grant_rolls_back_earlier_atoms_when_a_later_atom_conflicts(): void
    {
        $admin = User::factory()->create(['role' => 'admin_full']);
        $user = User::factory()->create(['role' => 'user_no_purchase']);
        [, , $lessons] = $this->createCourseWithLessons(lessonCount: 2);
        Sanctum::actingAs($admin);

        $this->postJson('/api/v1/access/grants', [
            'user_id' => $user->id,
            'atoms' => [
                ['kind' => 'lesson', 'id' => $lessons[1]->id],
            ],
            'source_type' => 'test_fixture',
            'source_id' => 'existing-intent',
            'idempotency_key' => 'atomic-batch',
        ])->assertCreated();

        $before = UserEntitlementGrant::query()
            ->orderBy('id')
            ->get()
            ->map->only(['id', 'user_id', 'atom_kind', 'atom_id', 'source_type', 'source_id', 'idempotency_key', 'status'])
            ->all();

        $this->postJson('/api/v1/access/grants', [
            'user_id' => $user->id,
            'atoms' => [
                ['kind' => 'lesson', 'id' => $lessons[0]->id],
                ['kind' => 'lesson', 'id' => $lessons[1]->id],
            ],
            'source_type' => 'test_fixture',
            'source_id' => 'conflicting-intent',
            'idempotency_key' => 'atomic-batch',
        ])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'ACCESS_GRANT_INVALID_REQUEST');

        $after = UserEntitlementGrant::query()
            ->orderBy('id')
            ->get()
            ->map->only(['id', 'user_id', 'atom_kind', 'atom_id', 'source_type', 'source_id', 'idempotency_key', 'status'])
            ->all();

        $this->assertSame($before, $after);
        $this->assertDatabaseMissing('user_entitlement_grants', [
            'user_id' => $user->id,
            'atom_kind' => 'lesson',
            'atom_id' => $lessons[0]->id,
            'idempotency_key' => 'atomic-batch',
        ]);
    }

    public function test_pending_and_revoked_grants_do_not_count_as_access(): void
    {
        $user = User::factory()->create(['role' => 'user_no_purchase']);
        [, $course, $lessons] = $this->createCourseWithLessons(lessonCount: 2);
        $this->createGrant($user, $lessons[0], UserEntitlementGrant::STATUS_PENDING);
        $this->createGrant($user, $lessons[1], UserEntitlementGrant::STATUS_REVOKED);
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/access/check', [
            'entity_type' => 'course',
            'entity_id' => $course->id,
        ])
            ->assertOk()
            ->assertJsonPath('data.access', 'none')
            ->assertJsonPath('data.required_atoms_count', 2)
            ->assertJsonPath('data.owned_atoms_count', 0);
    }

    public function test_regular_user_cannot_grant_access(): void
    {
        $user = User::factory()->create(['role' => 'user_no_purchase']);
        [, , [$lesson]] = $this->createCourseWithLessons(lessonCount: 1);
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/access/grants', [
            'user_id' => $user->id,
            'atoms' => [
                ['kind' => 'lesson', 'id' => $lesson->id],
            ],
        ])->assertForbidden();
    }

    /**
     * @return array{Subject, Course, array<int, Lesson>}
     */
    private function createCourseWithLessons(
        ?Subject $subject = null,
        string $courseSlug = 'course',
        int $lessonCount = 1,
    ): array {
        $subject ??= Subject::query()->create([
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
            ]);
        }

        return [$subject, $course, $lessons];
    }

    private function createGrant(
        User $user,
        Lesson $lesson,
        string $status = UserEntitlementGrant::STATUS_ACTIVE,
    ): UserEntitlementGrant {
        return UserEntitlementGrant::query()->create([
            'user_id' => $user->id,
            'atom_kind' => UserEntitlementGrant::ATOM_LESSON,
            'atom_id' => $lesson->id,
            'status' => $status,
            'source_type' => UserEntitlementGrant::SOURCE_TEST_FIXTURE,
            'source_id' => "lesson-{$lesson->id}",
            'idempotency_key' => "lesson-{$lesson->id}-{$status}",
            'granted_at' => $status === UserEntitlementGrant::STATUS_ACTIVE ? now() : null,
            'revoked_at' => $status === UserEntitlementGrant::STATUS_REVOKED ? now() : null,
        ]);
    }
}
