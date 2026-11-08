<?php

namespace Tests\Feature;

use App\Models\AdminOperationEvent;
use App\Models\AdminTask;
use App\Models\PromoCode;
use App\Models\User;
use App\Services\AdminOperationsService;
use App\Support\Rbac;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class PolinaStaffApiTest extends TestCase
{
    use RefreshDatabase;

    private function polina(array $attributes = []): User
    {
        return User::factory()->create([...[
            'role' => 'polina', 'username' => 'polina', 'email' => null,
            'password' => 'Fixture-password-123', 'email_verified_at' => null,
        ], ...$attributes]);
    }

    public function test_username_login_is_normalized_staff_only_and_nullable_email_is_safe(): void
    {
        Mail::fake();
        $polina = $this->polina();
        $this->withSession(['marker' => 'preserved']);
        $before = session()->getId();
        $this->postJson('/api/v1/auth/login', ['login' => ' POLINA ', 'password' => 'Fixture-password-123'])
            ->assertOk()->assertJsonPath('next_step', 'admin')->assertJsonPath('user.email', null);
        $this->assertAuthenticatedAs($polina, 'web');
        $this->assertNotSame($before, session()->getId());
        $this->assertSame('username', $polina->fresh()->last_auth_method);
        $this->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('must_verify_email', false);
        $this->patchJson('/api/v1/me/profile', ['name' => 'Forged staff profile'])->assertForbidden();
        $this->postJson('/api/v1/auth/email-verification/start')->assertUnprocessable();
        $this->postJson('/api/v1/auth/email-verification/verify', ['code' => '123456'])->assertUnprocessable();
        $this->assertDatabaseCount('auth_login_codes', 0);
        Mail::assertNothingSent();

        $polina->update(['role' => 'user_no_purchase']);
        $this->postJson('/api/v1/auth/login', ['login' => 'polina', 'password' => 'Fixture-password-123'])
            ->assertUnauthorized()->assertJsonPath('error.code', 'AUTH_INVALID_CREDENTIALS');
        $this->postJson('/api/v1/auth/register', ['username' => 'new-student', 'role' => 'polina', 'password' => 'Fixture-password-123'])
            ->assertUnprocessable()->assertJsonValidationErrors('email');
    }

    public function test_username_invalid_password_unknown_hold_and_throttle_preserve_auth_guards(): void
    {
        $polina = $this->polina();
        $this->postJson('/api/v1/auth/login', ['login' => 'polina', 'password' => 'Wrong-password-123'])->assertUnauthorized();
        $this->postJson('/api/v1/auth/login', ['login' => 'unknown', 'password' => 'Fixture-password-123'])->assertUnauthorized();
        $polina->forceFill(['migration_hold' => true])->save();
        $this->postJson('/api/v1/auth/login', ['login' => 'polina', 'password' => 'Fixture-password-123'])
            ->assertForbidden()->assertJsonPath('error.code', 'AUTH_ACCOUNT_MIGRATION_HELD');
        for ($attempt = 0; $attempt < 7; $attempt++) {
            $this->postJson('/api/v1/auth/login', ['login' => 'unknown', 'password' => 'Fixture-password-123'])->assertUnauthorized();
        }
        $this->postJson('/api/v1/auth/login', ['login' => 'unknown', 'password' => 'Fixture-password-123'])->assertTooManyRequests();
    }

    public function test_student_scope_is_global_but_staff_and_auxiliary_permissions_are_denied(): void
    {
        $polina = $this->polina();
        $manager = User::factory()->create(['role' => 'manager']);
        $students = [
            User::factory()->create(['role' => 'user_no_purchase', 'manager_id' => $manager->id]),
            User::factory()->create(['role' => 'user_with_purchase', 'manager_id' => null]),
        ];
        Sanctum::actingAs($polina);
        $this->getJson('/api/v1/admin/users')->assertOk()->assertJsonCount(2, 'data');
        foreach ($students as $student) {
            $this->getJson('/api/v1/admin/users/'.$student->id)->assertOk()->assertJsonPath('id', $student->id);
            foreach (['orders', 'entitlements', 'events'] as $tab) {
                $this->getJson('/api/v1/admin/users/'.$student->id.'/'.$tab)->assertForbidden();
            }
        }
        $this->getJson('/api/v1/admin/users?scope=staff')->assertForbidden();
        $this->getJson('/api/v1/admin/users/'.$manager->id)->assertForbidden();
        $this->patchJson('/api/v1/admin/users/'.$students[0]->id.'/profile', ['name' => 'Forged'])->assertForbidden();
        $this->deleteJson('/api/v1/admin/users/'.$students[0]->id.'/test-account')->assertForbidden();
        $this->getJson('/api/v1/admin/reports/financial')->assertOk();
        foreach (['users.update', 'users.delete', 'users.assign_manager', 'payments.create_order', 'payments.create_attempt', 'payments.read_order', 'access.read', 'access.grant', 'tasks.write', 'promos.write', 'articles.delete', 'admin.self.view', 'admin.self.update', 'audit.read'] as $permission) {
            $this->assertFalse(Rbac::hasPermission($polina, $permission), $permission);
        }
        $this->assertTrue(Rbac::canImpersonate($polina, $students[0]));
        $this->assertFalse(Rbac::canImpersonate($polina, $manager));
    }

    public function test_polina_can_create_promo_but_cannot_update_it_or_reuse_key_after_revoke(): void
    {
        $polina = $this->polina();
        Sanctum::actingAs($polina);
        $payload = ['code' => 'POLINA-TEST', 'status' => 'active', 'discount_kind' => 'percent_bps', 'discount_bps' => 1000,
            'expires_at' => now()->addDay()->toISOString(), 'activation_limit' => 3, 'per_user_limit' => 1];
        $this->getJson('/api/v1/admin/promos')->assertOk();
        $this->withHeader('Idempotency-Key', 'polina-promo')->postJson('/api/v1/admin/promos', $payload)->assertCreated();
        $promo = PromoCode::query()->sole();
        $this->patchJson('/api/v1/admin/promos/'.$promo->id, ['status' => 'revoked'])->assertForbidden();
        $this->withHeader('Idempotency-Key', 'polina-promo')->postJson('/api/v1/admin/promos', $payload)->assertConflict();
        $polina->update(['role' => 'user_no_purchase']);
        $this->withHeader('Idempotency-Key', 'polina-promo')->postJson('/api/v1/admin/promos', $payload)->assertForbidden();
        $this->assertDatabaseCount('promo_codes', 1);
        $this->assertDatabaseCount('admin_operation_events', 1);
    }

    public function test_tasks_are_assignee_scoped_and_only_completion_is_allowed(): void
    {
        $polina = $this->polina();
        $admin = User::factory()->create(['role' => 'admin_full']);
        $service = app(AdminOperationsService::class);
        $own = $service->tasks($admin, 'create', ['title' => 'Own', 'description' => 'Keep body', 'assignee_id' => $polina->id], key: 'task-own')['data'];
        $other = $service->tasks($admin, 'create', ['title' => 'Other', 'assignee_id' => $admin->id], key: 'task-other')['data'];
        $this->assertSame([$own->id], $service->tasks($polina, 'list', configureQuery: fn ($query) => $query->where('id', $own->id)->orWhere('id', $other->id))['data']->pluck('id')->all());
        Sanctum::actingAs($polina);
        $this->getJson('/api/v1/admin/tasks')->assertOk()->assertJsonCount(1, 'data.data')->assertJsonPath('data.data.0.id', $own->id);
        $this->postJson('/api/v1/admin/tasks', ['title' => 'Forged'])->assertForbidden();
        $this->patchJson('/api/v1/admin/tasks/'.$own->id, ['title' => 'Forged'])->assertForbidden();
        $this->deleteJson('/api/v1/admin/tasks/'.$own->id)->assertForbidden();
        $this->withHeader('Idempotency-Key', 'own-complete')->postJson('/api/v1/admin/tasks/'.$other->id.'/complete')->assertForbidden();
        $this->postJson('/api/v1/admin/tasks/'.$own->id.'/complete', ['status' => 'done'])->assertUnprocessable();
        $this->postJson('/api/v1/admin/tasks/'.$own->id.'/complete')->assertOk()->assertJsonPath('data.status', 'done');
        $this->assertSame('Keep body', $own->fresh()->description);
        $this->postJson('/api/v1/admin/tasks/'.$own->id.'/complete')->assertConflict();
        $this->assertDatabaseHas('admin_operation_events', ['operation' => 'complete', 'actor_id' => $polina->id, 'resource_id' => $own->id]);
        $own->update(['assignee_id' => $admin->id]);
        $this->postJson('/api/v1/admin/tasks/'.$own->id.'/complete')->assertForbidden();
        $polina->update(['role' => 'user_no_purchase']);
        try {
            $service->completeAssignedTask($polina, $own->id, key: 'own-complete');
            $this->fail('Stale actor cannot complete tasks.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
    }

    public function test_closed_unassigned_reassigned_tasks_and_stale_admin_snapshot_cannot_complete_or_overwrite(): void
    {
        $polina = $this->polina();
        $admin = User::factory()->create(['role' => 'admin_full']);
        $service = app(AdminOperationsService::class);
        Sanctum::actingAs($polina);
        foreach (['done', 'cancelled'] as $status) {
            $task = AdminTask::create(['title' => $status, 'status' => $status, 'assignee_id' => $polina->id, 'created_by' => $admin->id]);
            $this->withHeader('Idempotency-Key', 'closed-'.$status)->postJson('/api/v1/admin/tasks/'.$task->id.'/complete')->assertConflict();
        }
        $task = AdminTask::create(['title' => 'Unassigned', 'status' => 'open', 'created_by' => $admin->id]);
        $this->postJson('/api/v1/admin/tasks/'.$task->id.'/complete')->assertForbidden();
        $snapshot = $task->fresh()->only(['title', 'description', 'assignee_id', 'status']);
        $task->update(['assignee_id' => $admin->id]);
        try {
            $service->tasks($admin, 'update', ['title' => 'Overwrite', 'assignee_id' => $polina->id], $task->id, 'stale-admin', expectedSnapshot: $snapshot);
            $this->fail('Assignment conflict must be detected.');
        } catch (HttpException $exception) {
            $this->assertSame(409, $exception->getStatusCode());
        }
        $this->assertSame('Unassigned', $task->fresh()->title);
        $this->assertSame($admin->id, $task->fresh()->assignee_id);
        $this->assertSame(0, AdminOperationEvent::count());
    }
}
