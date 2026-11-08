<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\AdminOperationsService;
use App\Support\Rbac;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class AdminImpersonationApiTest extends TestCase
{
    use RefreshDatabase;

    private function authenticateBrowser(User $user): void
    {
        $this->actingAs($user, 'web')
            ->withHeader('Origin', 'http://localhost:3000')
            ->withHeader('Referer', 'http://localhost:3000/admin/users');
    }

    public function test_polina_can_view_any_student_read_only_return_and_is_revoked_when_rights_change(): void
    {
        $actor = User::factory()->create(['role' => 'polina', 'email' => null, 'username' => 'polina']);
        $student = User::factory()->create(['manager_id' => User::factory()->create(['role' => 'manager'])->id]);
        $staff = User::factory()->create(['role' => 'teacher']);
        $created = app(AdminOperationsService::class)->promos($actor, 'create', [
            'code' => 'IMPERSONATION-DELETE', 'status' => 'active', 'discount_kind' => 'fixed_minor', 'discount_minor' => 100,
            'expires_at' => now()->addDay()->toISOString(), 'activation_limit' => 1, 'per_user_limit' => 1,
        ], key: 'impersonation-create');
        $this->authenticateBrowser($actor);
        $this->withHeaders($this->csrfHeaders())
            ->postJson("/api/v1/admin/users/{$staff->id}/impersonation", ['return_path' => '/admin/users'])->assertForbidden();
        $this->withHeaders($this->csrfHeaders())
            ->postJson("/api/v1/admin/users/{$student->id}/impersonation", ['return_path' => '/admin/users'])->assertCreated();
        $this->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('id', $student->id);
        $this->withHeaders($this->csrfHeaders())->withHeader('Idempotency-Key', 'impersonation-delete')
            ->deleteJson('/api/v1/admin/promos/'.$created['data']['id'])->assertForbidden();
        $this->withHeaders($this->csrfHeaders())->postJson('/api/v1/payments/orders', [])->assertForbidden();
        $this->withHeaders($this->csrfHeaders())
            ->postJson("/api/v1/admin/users/{$student->id}/impersonation", ['return_path' => '/admin/users'])->assertConflict();
        $this->withHeaders($this->csrfHeaders())->postJson('/api/v1/impersonation/stop')->assertOk()->assertJsonPath('return_path', '/admin/users');
        $this->assertDatabaseHas('admin_impersonation_events', ['actor_id' => $actor->id, 'subject_id' => $student->id, 'event' => 'stopped']);
        $this->withHeaders($this->csrfHeaders())
            ->postJson("/api/v1/admin/users/{$student->id}/impersonation", ['return_path' => '/admin/users'])->assertCreated();
        $actor->update(['role' => 'user_no_purchase']);
        $this->getJson('/api/v1/impersonation')->assertOk()->assertJsonPath('status', 'expired');
        $this->assertDatabaseHas('admin_impersonation_events', ['actor_id' => $actor->id, 'event' => 'expired', 'reason' => 'revoked']);
    }

    private function csrfHeaders(): array
    {
        $token = csrf_token();

        return [
            'Origin' => 'http://localhost:3000',
            'Referer' => 'http://localhost:3000/admin/users',
            'X-CSRF-TOKEN' => $token,
            'X-XSRF-TOKEN' => $token,
        ];
    }

    public function test_only_admin_full_can_view_a_student_and_staff_targets_are_forbidden(): void
    {
        $actor = User::factory()->create(['role' => 'admin_full']);
        $student = User::factory()->create(['role' => 'user_no_purchase']);
        $staff = User::factory()->create(['role' => 'manager']);
        $this->authenticateBrowser($actor);

        $response = $this->withHeaders($this->csrfHeaders())
            ->postJson("/api/v1/admin/users/{$student->id}/impersonation", ['return_path' => '/admin/users']);

        $response->assertCreated()
            ->assertJsonPath('status', 'active')
            ->assertJsonPath('read_only', true)
            ->assertJsonPath('actor.id', $actor->id)
            ->assertJsonPath('subject.id', $student->id);
        $this->assertAuthenticatedAs($actor, 'web');

        $this->withHeaders($this->csrfHeaders())
            ->postJson('/api/v1/impersonation/stop')
            ->assertOk();
        $this->withHeaders($this->csrfHeaders())
            ->postJson("/api/v1/admin/users/{$staff->id}/impersonation", ['return_path' => '/admin/users'])
            ->assertForbidden()
            ->assertJsonPath('error.code', 'IMPERSONATION_FORBIDDEN');
        $this->assertDatabaseHas('admin_impersonation_events', [
            'actor_id' => $actor->id,
            'subject_id' => $staff->id,
            'event' => 'denied',
        ]);

        $limited = User::factory()->create(['role' => 'admin_limited']);
        $this->assertFalse(Rbac::hasPermission($limited, 'users.impersonate'));
        $this->assertFalse(Rbac::canImpersonate($limited, $student));
    }

    public function test_start_and_stop_keep_actor_separate_from_subject(): void
    {
        $actor = User::factory()->create(['role' => 'admin_full']);
        $student = User::factory()->create(['name' => 'Просматриваемый ученик']);
        $this->authenticateBrowser($actor);

        $this->withHeaders($this->csrfHeaders())
            ->postJson("/api/v1/admin/users/{$student->id}/impersonation", ['return_path' => '/admin/users?q=student'])
            ->assertCreated();

        $this->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('id', $student->id)
            ->assertJsonPath('name', 'Просматриваемый ученик')
            ->assertJsonPath('impersonation.status', 'active')
            ->assertJsonPath('impersonation.actor.id', $actor->id)
            ->assertJsonPath('impersonation.subject.id', $student->id);
        $this->assertAuthenticatedAs($actor, 'web');
        $this->assertSame($actor->id, Auth::guard('web')->id());

        $this->withHeaders($this->csrfHeaders())
            ->postJson('/api/v1/impersonation/stop')
            ->assertOk()
            ->assertJsonPath('status', 'stopped')
            ->assertJsonPath('return_path', '/admin/users?q=student');
        $this->assertAuthenticatedAs($actor, 'web');
        $this->assertSame($actor->id, Auth::guard('web')->id());

        $this->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('id', $actor->id)
            ->assertJsonPath('impersonation.status', 'inactive')
            ->assertJsonPath('impersonation.subject', null);
        $this->assertDatabaseHas('admin_impersonation_events', ['event' => 'started', 'actor_id' => $actor->id, 'subject_id' => $student->id]);
        $this->assertDatabaseHas('admin_impersonation_events', ['event' => 'stopped', 'actor_id' => $actor->id, 'subject_id' => $student->id]);
    }

    public function test_expired_or_damaged_context_is_cleared_and_subject_reads_are_rejected(): void
    {
        $actor = User::factory()->create(['role' => 'admin_full']);
        $student = User::factory()->create();
        $this->authenticateBrowser($actor);

        $this->withHeaders($this->csrfHeaders())
            ->postJson("/api/v1/admin/users/{$student->id}/impersonation", ['return_path' => '/admin/users'])
            ->assertCreated();

        Carbon::setTestNow(now()->addMinutes(31));

        $this->getJson('/api/v1/impersonation')
            ->assertOk()
            ->assertJsonPath('status', 'expired')
            ->assertJsonPath('return_path', '/admin/users');
        $this->getJson('/api/v1/auth/me')
            ->assertStatus(410)
            ->assertJsonPath('error.code', 'IMPERSONATION_CONTEXT_EXPIRED')
            ->assertJsonPath('return_path', '/admin/users');
        $this->getJson('/api/v1/impersonation')
            ->assertOk()
            ->assertJsonPath('status', 'expired')
            ->assertJsonPath('return_path', '/admin/users');
        $this->withHeaders($this->csrfHeaders())
            ->postJson('/api/v1/auth/phone-verification/start', [])
            ->assertStatus(410)
            ->assertJsonPath('error.code', 'IMPERSONATION_CONTEXT_EXPIRED');
        $this->withHeaders($this->csrfHeaders())
            ->postJson('/api/v1/impersonation/stop')
            ->assertOk()
            ->assertJsonPath('status', 'stopped');
        $this->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('id', $actor->id)
            ->assertJsonPath('impersonation.status', 'inactive');
        $this->assertDatabaseHas('admin_impersonation_events', ['event' => 'expired', 'reason' => 'ttl_expired']);
        $this->assertNull(session('admin_impersonation'));
        $this->assertNull(session('admin_impersonation_expired'));

        Carbon::setTestNow();
        $this->actingAs($actor, 'web')
            ->withSession(['admin_impersonation' => ['version' => 999, 'actor_id' => $actor->id]])
            ->getJson('/api/v1/impersonation')
            ->assertOk()
            ->assertJsonPath('status', 'expired');
        $this->assertDatabaseHas('admin_impersonation_events', ['event' => 'expired', 'reason' => 'damaged']);
    }

    public function test_all_mutations_are_blocked_while_viewing_and_stop_remains_available(): void
    {
        $actor = User::factory()->create(['role' => 'admin_full']);
        $student = User::factory()->create();
        $this->authenticateBrowser($actor);

        $this->withHeaders($this->csrfHeaders())
            ->postJson("/api/v1/admin/users/{$student->id}/impersonation", ['return_path' => '/admin/users'])
            ->assertCreated();

        $this->withHeaders($this->csrfHeaders())
            ->postJson('/api/v1/auth/phone-verification/start', [])
            ->assertForbidden()
            ->assertJsonPath('error.code', 'IMPERSONATION_READ_ONLY');

        $this->withHeaders($this->csrfHeaders())
            ->postJson('/api/v1/access/grants', [
                'user_id' => $student->id,
                'atoms' => [['kind' => 'lesson', 'id' => 1]],
            ])
            ->assertForbidden()
            ->assertJsonPath('error.code', 'IMPERSONATION_READ_ONLY');

        $this->withHeaders($this->csrfHeaders())
            ->postJson('/api/v1/impersonation/stop')
            ->assertOk()
            ->assertJsonPath('status', 'stopped');
    }

    public function test_logout_closes_active_context_and_keeps_audit_trail(): void
    {
        $actor = User::factory()->create(['role' => 'admin_full']);
        $student = User::factory()->create();
        $this->authenticateBrowser($actor);

        $this->withHeaders($this->csrfHeaders())
            ->postJson("/api/v1/admin/users/{$student->id}/impersonation", ['return_path' => '/admin/users'])
            ->assertCreated();

        $this->withHeaders($this->csrfHeaders())
            ->postJson('/api/v1/auth/logout')
            ->assertOk()
            ->assertJsonPath('status', 'ok');

        $this->assertGuest('web');
        $this->assertDatabaseHas('admin_impersonation_events', [
            'actor_id' => $actor->id,
            'subject_id' => $student->id,
            'event' => 'stopped',
        ]);
    }

    public function test_start_and_stop_require_csrf_for_stateful_browser_requests(): void
    {
        $actor = User::factory()->create(['role' => 'admin_full']);
        $student = User::factory()->create();
        $this->app->instance('env', 'local');
        $this->authenticateBrowser($actor);

        $this->postJson("/api/v1/admin/users/{$student->id}/impersonation", ['return_path' => '/admin/users'])
            ->assertStatus(419);
        $this->assertDatabaseCount('admin_impersonation_events', 0);

        $this->withHeaders($this->csrfHeaders())
            ->postJson("/api/v1/admin/users/{$student->id}/impersonation", ['return_path' => '/admin/users'])
            ->assertCreated();
        $this->postJson('/api/v1/impersonation/stop')->assertStatus(419);
        $this->assertDatabaseCount('admin_impersonation_events', 1);
    }
}
