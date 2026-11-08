<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Lesson;
use App\Models\Subject;
use App\Models\User;
use App\Models\UserEntitlementGrant;
use App\Services\AccessResolver;
use App\Support\Rbac;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class LocalDevProfilesCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_refuses_to_run_outside_the_local_environment(): void
    {
        $this->createCatalog();

        $exitCode = Artisan::call('dev:profiles');

        $this->assertSame(1, $exitCode);
        $this->assertSame("status=rejected reason=local_environment_required\n", Artisan::output());
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('user_entitlement_grants', 0);
    }

    public function test_command_refuses_a_non_local_database_target_before_writing(): void
    {
        $this->allowLocalFixture();
        $originalConnection = config('database.default');

        try {
            config()->set('database.default', 'pgsql');
            config()->set('database.connections.pgsql.url', null);
            config()->set('database.connections.pgsql.host', 'db.staging.internal');
            config()->set('database.connections.pgsql.database', 'cms');

            $exitCode = Artisan::call('dev:profiles');
            $output = Artisan::output();
        } finally {
            config()->set('database.default', $originalConnection);
        }

        $this->assertSame(1, $exitCode);
        $this->assertSame("status=rejected reason=local_database_required\n", $output);
    }

    public function test_command_refuses_a_local_host_with_a_non_cms_database_before_writing(): void
    {
        $this->allowLocalFixture();
        $originalConnection = config('database.default');

        try {
            config()->set('database.default', 'pgsql');
            config()->set('database.connections.pgsql.url', null);
            config()->set('database.connections.pgsql.host', '127.0.0.1');
            config()->set('database.connections.pgsql.database', 'not_cms');

            $exitCode = Artisan::call('dev:profiles');
            $output = Artisan::output();
        } finally {
            config()->set('database.default', $originalConnection);
        }

        $this->assertSame(1, $exitCode);
        $this->assertSame("status=rejected reason=local_database_required\n", $output);
    }

    public function test_command_refuses_a_nonempty_database_url_even_for_the_local_cms_database(): void
    {
        $this->allowLocalFixture();
        $originalConnection = config('database.default');

        try {
            config()->set('database.default', 'pgsql');
            config()->set('database.connections.pgsql.url', 'postgresql://local-user:local-password@127.0.0.1/cms');
            config()->set('database.connections.pgsql.host', '127.0.0.1');
            config()->set('database.connections.pgsql.database', 'cms');

            $exitCode = Artisan::call('dev:profiles');
            $output = Artisan::output();
        } finally {
            config()->set('database.default', $originalConnection);
        }

        $this->assertSame(1, $exitCode);
        $this->assertSame("status=rejected reason=local_database_required\n", $output);
    }

    public function test_command_creates_the_four_profiles_assigned_reviewer_and_staging_equivalent_access_states(): void
    {
        $catalog = $this->createCatalog();
        $this->allowLocalFixture();

        $exitCode = Artisan::call('dev:profiles');
        $output = Artisan::output();

        $this->assertSame(0, $exitCode, $output);
        $this->assertSame(
            "status=ready profiles=4 all_lessons=5 partial_lessons=3 empty_lessons=0\n",
            $output,
        );
        $this->assertDatabaseCount('users', 4);

        $all = User::query()->where('email', 'all@test.test')->sole();
        $partial = User::query()->where('email', 'partial@test.test')->sole();
        $empty = User::query()->where('email', 'empty@test.test')->sole();
        $reviewer = User::query()->where('email', 'reviewer@test.test')->sole();

        $this->assertProfile($all, 'Demo Full Access', 'user_with_purchase', '+79990000001');
        $this->assertProfile($partial, 'Demo Partial Buyer', 'user_with_purchase', '+79990000002');
        $this->assertProfile($empty, 'Demo Registered Only', 'user_no_purchase', '+79990000003');
        $this->assertProfile($reviewer, 'Demo Assessment Reviewer', 'teacher', '+79990000004');
        $this->assertSame($reviewer->id, $all->manager_id);
        $this->assertNull($partial->manager_id);
        $this->assertNull($empty->manager_id);
        $this->assertNull($reviewer->manager_id);
        $this->assertTrue(Rbac::hasPermission($reviewer, 'assessment-reviews.read'));
        $this->assertTrue(Rbac::hasPermission($reviewer, 'assessment-reviews.review'));
        $this->assertTrue(Rbac::canReviewAssessmentUserScope($reviewer, $all));
        $this->assertFalse(Rbac::canReviewAssessmentUserScope($reviewer, $partial));

        $this->assertSame(5, $this->managedGrantCount($all));
        $this->assertSame(3, $this->managedGrantCount($partial));
        $this->assertSame(0, $this->managedGrantCount($empty));
        $this->assertSame(0, $this->managedGrantCount($reviewer));

        $resolver = app(AccessResolver::class);
        $this->assertSame('full', $resolver->check($partial, 'course', $catalog['owned']->id)['access']);
        $this->assertSame('partial', $resolver->check($partial, 'course', $catalog['mixed']->id)['access']);
        $this->assertSame('none', $resolver->check($partial, 'course', $catalog['locked']->id)['access']);
        $this->assertSame('full', $resolver->check($all, 'subject', $catalog['subject']->id)['access']);
        $this->assertSame('none', $resolver->check($empty, 'subject', $catalog['subject']->id)['access']);
    }

    public function test_repeated_runs_reconcile_only_owned_fixture_grants(): void
    {
        $catalog = $this->createCatalog();
        $unmanagedUser = User::factory()->create([
            'email' => 'unmanaged@example.test',
            'name' => 'Unmanaged User',
            'role' => 'user_no_purchase',
        ]);
        $this->allowLocalFixture();
        $this->assertSame(0, Artisan::call('dev:profiles'), Artisan::output());

        $partial = User::query()->where('email', 'partial@test.test')->sole();
        $reviewer = User::query()->where('email', 'reviewer@test.test')->sole();
        $initialPasswordHash = $partial->password;
        $initialGrantIds = $this->managedGrants($partial)->pluck('id')->sort()->values()->all();
        $foreignGrant = UserEntitlementGrant::query()->create([
            'user_id' => $partial->id,
            'atom_kind' => UserEntitlementGrant::ATOM_LESSON,
            'atom_id' => $catalog['lockedLesson']->id,
            'status' => UserEntitlementGrant::STATUS_ACTIVE,
            'source_type' => UserEntitlementGrant::SOURCE_TEST_FIXTURE,
            'source_id' => 'other-fixture',
            'idempotency_key' => 'other-fixture-v1',
            'granted_at' => now(),
            'metadata' => ['fixture_owner' => 'other_fixture_v1'],
        ]);
        $reviewerForeignGrant = UserEntitlementGrant::query()->create([
            'user_id' => $reviewer->id,
            'atom_kind' => UserEntitlementGrant::ATOM_LESSON,
            'atom_id' => $catalog['lockedLesson']->id,
            'status' => UserEntitlementGrant::STATUS_ACTIVE,
            'source_type' => UserEntitlementGrant::SOURCE_TEST_FIXTURE,
            'source_id' => 'other-fixture-reviewer',
            'idempotency_key' => 'other-fixture-reviewer-v1',
            'granted_at' => now(),
            'metadata' => ['fixture_owner' => 'other_fixture_v1'],
        ]);

        $this->assertSame(0, Artisan::call('dev:profiles'), Artisan::output());

        $partial->refresh();
        $this->assertSame($initialPasswordHash, $partial->password);
        $this->assertSame($initialGrantIds, $this->managedGrants($partial)->pluck('id')->sort()->values()->all());
        $this->assertDatabaseHas('user_entitlement_grants', ['id' => $foreignGrant->id]);
        $this->assertDatabaseMissing('user_entitlement_grants', ['id' => $reviewerForeignGrant->id]);
        $this->assertSame(0, UserEntitlementGrant::query()->where('user_id', $reviewer->id)->count());

        config()->set('local_development.dev_profiles.partial_access.full_courses', []);
        $this->assertSame(0, Artisan::call('dev:profiles'), Artisan::output());

        $this->assertSame(1, $this->managedGrantCount($partial));
        $this->assertDatabaseHas('user_entitlement_grants', ['id' => $foreignGrant->id]);
        $this->assertDatabaseHas('user_entitlement_grants', [
            'user_id' => $partial->id,
            'atom_id' => $catalog['mixedGrantedLesson']->id,
            'status' => UserEntitlementGrant::STATUS_ACTIVE,
        ]);
        $this->assertDatabaseHas('users', [
            'id' => $unmanagedUser->id,
            'email' => 'unmanaged@example.test',
            'name' => 'Unmanaged User',
            'role' => 'user_no_purchase',
        ]);
        $this->assertSame(
            User::query()->where('email', 'reviewer@test.test')->sole()->id,
            User::query()->where('email', 'all@test.test')->sole()->manager_id,
        );
    }

    public function test_catalog_drift_fails_atomically_instead_of_creating_approximate_profiles(): void
    {
        $this->createCatalog();
        $this->allowLocalFixture();
        config()->set('local_development.dev_profiles.partial_access.full_courses', [
            ['subject' => 'anatomiya', 'course' => 'missing-course'],
        ]);

        $exitCode = Artisan::call('dev:profiles');

        $this->assertSame(1, $exitCode);
        $this->assertSame("status=rejected reason=fixture_catalog_mismatch\n", Artisan::output());
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('user_entitlement_grants', 0);
    }

    private function allowLocalFixture(): void
    {
        $this->app->instance('env', 'local');
        config()->set('local_development.dev_profiles.database', [
            'database' => 'cms',
            'hosts' => ['127.0.0.1', 'localhost'],
        ]);
        config()->set('local_development.dev_profiles.partial_access', [
            'full_courses' => [
                ['subject' => 'anatomiya', 'course' => 'owned-course'],
            ],
            'individual_lessons' => [
                ['subject' => 'anatomiya', 'course' => 'mixed-course', 'lesson' => 'mixed-owned'],
            ],
        ]);
    }

    /**
     * @return array{
     *     subject: Subject,
     *     owned: Course,
     *     mixed: Course,
     *     locked: Course,
     *     mixedGrantedLesson: Lesson,
     *     lockedLesson: Lesson
     * }
     */
    private function createCatalog(): array
    {
        $subject = Subject::query()->create([
            'slug' => 'anatomiya',
            'title' => 'Анатомия',
            'is_active' => true,
        ]);
        $owned = $this->createCourse($subject, 'owned-course');
        $mixed = $this->createCourse($subject, 'mixed-course');
        $locked = $this->createCourse($subject, 'locked-course');

        $this->createLesson($owned, 'owned-one');
        $this->createLesson($owned, 'owned-two');
        $this->createLesson($owned, 'owned-inactive', false);
        $mixedGrantedLesson = $this->createLesson($mixed, 'mixed-owned');
        $this->createLesson($mixed, 'mixed-locked');
        $lockedLesson = $this->createLesson($locked, 'locked-one');

        return compact('subject', 'owned', 'mixed', 'locked', 'mixedGrantedLesson', 'lockedLesson');
    }

    private function createCourse(Subject $subject, string $slug): Course
    {
        return Course::query()->create([
            'subject_id' => $subject->id,
            'slug' => $slug,
            'title' => $slug,
            'is_active' => true,
        ]);
    }

    private function createLesson(Course $course, string $slug, bool $active = true): Lesson
    {
        return Lesson::query()->create([
            'course_id' => $course->id,
            'slug' => $slug,
            'title' => $slug,
            'is_active' => $active,
        ]);
    }

    private function assertProfile(User $user, string $name, string $role, string $phone): void
    {
        $this->assertSame($name, $user->name);
        $this->assertSame($role, $user->role);
        $this->assertSame($phone, $user->phone);
        $this->assertNotNull($user->phone_verified_at);
        $this->assertNull($user->email_verified_at);
        $this->assertSame('set', $user->password_state);
        $this->assertSame('email', $user->last_auth_method);
        $this->assertTrue($user->legacy_auto_verified);
        $this->assertTrue(Hash::check('password', $user->password));
    }

    private function managedGrantCount(User $user): int
    {
        return $this->managedGrants($user)->count();
    }

    /**
     * @return Collection<int, UserEntitlementGrant>
     */
    private function managedGrants(User $user): Collection
    {
        return UserEntitlementGrant::query()
            ->where('user_id', $user->id)
            ->where('source_type', UserEntitlementGrant::SOURCE_TEST_FIXTURE)
            ->where('metadata->fixture_owner', 'local_dev_profiles_v1')
            ->get();
    }
}
