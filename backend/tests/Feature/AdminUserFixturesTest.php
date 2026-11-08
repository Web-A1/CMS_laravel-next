<?php

namespace Tests\Feature;

use App\Models\AdminUserEvent;
use App\Models\Course;
use App\Models\Lesson;
use App\Models\Order;
use App\Models\Subject;
use App\Models\User;
use App\Models\UserEntitlementGrant;
use App\Services\AccessResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminUserFixturesTest extends TestCase
{
    use RefreshDatabase;

    private function catalog(): Course
    {
        $subject = Subject::query()->create(['slug' => 'test', 'title' => 'Тест', 'is_active' => true]);
        $course = Course::query()->create(['subject_id' => $subject->id, 'slug' => 'test', 'title' => 'Курс', 'is_active' => true]);
        for ($i = 1; $i <= 3; $i++) {
            Lesson::query()->create(['course_id' => $course->id, 'slug' => "lesson-{$i}", 'title' => "Урок {$i}", 'is_active' => true]);
        }

        return $course;
    }

    public function test_command_is_local_only_and_preserves_owned_edits_without_duplicate_orders(): void
    {
        $this->artisan('dev:profiles', ['--admin-users' => true])->assertFailed();
        $this->assertDatabaseCount('users', 0);
        $course = $this->catalog();
        $this->app->instance('env', 'local');
        $existing = User::factory()->create(['email' => 'all@test.test', 'role' => 'user_with_purchase']);
        $this->artisan('dev:profiles', ['--admin-users' => true])->assertSuccessful();
        $this->assertDatabaseCount('users', 6);
        $this->assertDatabaseCount('orders', 4);
        $this->assertDatabaseCount('user_entitlement_grants', 3);
        $owned = User::query()->where('email', 'users-own@test.test')->sole();
        $owned->forceFill(['name' => 'Изменено через UI'])->save();
        $this->artisan('dev:profiles', ['--admin-users' => true])->assertSuccessful();
        $this->assertSame('Изменено через UI', $owned->fresh()->name);
        $this->assertSame('user_with_purchase', $existing->fresh()->role);
        $this->assertDatabaseCount('orders', 4);
        $this->assertDatabaseCount('admin_user_events', 6);
        $this->assertDatabaseCount('outbox_events', 0);
        $resolver = app(AccessResolver::class);
        $single = $resolver->check($owned, 'course', $course->id);
        $batch = $resolver->coursesForUser($owned->id, Course::query()->get());
        $this->assertSame('partial', $single['access']);
        $this->assertSame($single['access'], $batch[$course->id]['access']);
        $this->assertSame(1, $batch[$course->id]['owned_atoms_count']);
        Sanctum::actingAs(User::query()->where('email', 'users-admin@test.test')->sole());
        $this->getJson("/api/v1/admin/users/{$owned->id}/courses")->assertOk()->assertJsonPath('data.0.access', 'partial');
        $this->getJson("/api/v1/admin/users/{$owned->id}/orders")->assertOk()->assertJsonCount(1, 'data.0.items');
        $this->assertSame(1, UserEntitlementGrant::query()->effectiveActive()->count());
    }

    public function test_existing_unowned_email_is_rejected_and_transaction_leaves_it_untouched(): void
    {
        $this->catalog();
        $this->app->instance('env', 'local');
        $existing = User::factory()->create(['email' => 'users-manager@test.test', 'name' => 'Не трогать', 'role' => 'user_no_purchase']);
        $this->artisan('dev:profiles', ['--admin-users' => true])->assertFailed();
        $this->assertDatabaseCount('users', 1);
        $this->assertSame('Не трогать', $existing->fresh()->name);
        $this->assertSame('user_no_purchase', $existing->fresh()->role);
        $this->assertSame(0, AdminUserEvent::query()->count());
        $this->assertSame(0, Order::query()->count());
    }
}
