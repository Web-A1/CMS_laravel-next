<?php

namespace Tests\Feature;

use App\Models\AdminUserEvent;
use App\Models\Order;
use App\Models\User;
use App\Models\UserEntitlementGrant;
use App\Models\UserProfile;
use App\Services\AdminUserManagement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminUserManagementApiTest extends TestCase
{
    use RefreshDatabase;

    private function actor(string $role = 'admin_full'): User
    {
        $actor = User::factory()->create(['role' => $role]);
        Sanctum::actingAs($actor);

        return $actor;
    }

    public function test_order_tariff_projection_keeps_existing_staff_permission_and_assigned_scope(): void
    {
        $manager = $this->actor('manager');
        $own = User::factory()->create(['manager_id' => $manager->id]);
        $other = User::factory()->create();
        $tariff = ['version' => 1, 'code' => 'extended', 'label' => 'Расширенный', 'supportDescription' => 'Сохранённый объём личной работы преподавателя.'];
        $order = Order::query()->create(['user_id' => $own->id, 'currency' => 'RUB', 'amount_total' => 859000, 'amount_final' => 859000]);
        $order->items()->create(['sellable_kind' => 'course', 'sellable_id' => 1, 'purchased_entity_type' => 'course', 'purchased_entity_id' => 1, 'display_title' => 'Course', 'currency' => 'RUB', 'quantity' => 1, 'unit_amount' => 859000, 'subtotal_amount' => 859000, 'final_amount' => 859000, 'catalog_snapshot' => ['tariff' => $tariff, 'secret' => 'never-project'], 'price_snapshot' => [], 'benefit_snapshot' => [], 'grant_snapshot' => [], 'snapshot_hash' => str_repeat('a', 64)]);
        unset($tariff['version']);
        $response = $this->getJson('/api/v1/admin/users/'.$own->id.'/orders')->assertOk()->assertJsonPath('data.0.items.0.tariff', $tariff);
        $this->assertStringNotContainsString('never-project', $response->getContent());
        $this->getJson('/api/v1/admin/users/'.$other->id.'/orders')->assertForbidden();
        $this->actor('teacher');
        $this->getJson('/api/v1/admin/users/'.$own->id.'/orders')->assertForbidden();
    }

    public function test_permissions_scope_and_search_never_leak_other_users(): void
    {
        $this->getJson('/api/v1/admin/users')->assertUnauthorized();
        $manager = $this->actor('manager');
        $own = User::factory()->create(['name' => 'Свой ученик', 'manager_id' => $manager->id, 'phone' => '+79995553322']);
        $other = User::factory()->create(['name' => 'Чужой ученик', 'email' => 'other@example.test']);
        foreach ([$own->id, 'Свой', $own->email, '+7 (999) 555-33-22'] as $q) {
            $response = $this->getJson('/api/v1/admin/users?'.http_build_query(['q' => $q]))->assertOk();
            $this->assertSame(1, $response->json('meta.total'), (string) $q);
            $response->assertJsonPath('data.0.id', $own->id);
        }
        $this->getJson('/api/v1/admin/users?'.http_build_query(['q' => 'ученик']))->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson('/api/v1/admin/users?q=other')->assertOk()->assertJsonPath('meta.total', 0);
        $this->getJson('/api/v1/admin/users?q=%25')->assertOk()->assertJsonPath('meta.total', 0);
        $this->getJson('/api/v1/admin/users?q=_')->assertOk()->assertJsonPath('meta.total', 0);
        foreach (['', '/orders', '/entitlements', '/courses', '/events'] as $path) {
            $this->getJson("/api/v1/admin/users/{$other->id}{$path}")->assertForbidden();
        }
        $this->patchJson("/api/v1/admin/users/{$other->id}/profile", ['revision' => str_repeat('a', 64), 'name' => 'Wrong'])->assertForbidden();
        $this->getJson('/api/v1/admin/user-managers')->assertForbidden();
        $this->actor('teacher');
        $this->getJson('/api/v1/admin/users')->assertForbidden();
        $this->actor('user_with_purchase');
        $this->getJson('/api/v1/admin/users')->assertForbidden();
        $this->actor('admin_limited');
        $this->getJson("/api/v1/admin/users/{$other->id}")->assertOk();
    }

    public function test_user_list_defaults_to_client_accounts_and_staff_scope_is_admin_only(): void
    {
        $actor = $this->actor();
        $client = User::factory()->create(['name' => 'Обычный пользователь', 'role' => 'user_no_purchase']);
        $staff = User::factory()->create(['name' => 'Сотрудник', 'role' => 'admin_limited']);
        $manager = User::factory()->create(['name' => 'Менеджер', 'role' => 'manager']);
        $technical = User::factory()->create(['name' => 'Техническая запись', 'role' => 'guest']);

        $default = $this->getJson('/api/v1/admin/users')->assertOk();
        $this->assertSame(1, $default->json('meta.total'));
        $this->assertSame([$client->id], array_column($default->json('data'), 'id'));

        $staffPage = $this->getJson('/api/v1/admin/users?scope=staff')->assertOk();
        $this->assertSame(3, $staffPage->json('meta.total'));
        $this->assertEqualsCanonicalizing(
            [$actor->id, $staff->id, $manager->id],
            array_column($staffPage->json('data'), 'id'),
        );
        $this->assertNotContains($client->id, array_column($staffPage->json('data'), 'id'));
        $this->assertNotContains($technical->id, array_column($staffPage->json('data'), 'id'));

        $this->getJson('/api/v1/admin/users?scope=invalid')->assertUnprocessable();

        $this->actor('manager');
        $this->getJson('/api/v1/admin/users?scope=staff')->assertForbidden();
    }

    public function test_pagination_and_paid_totals_are_separate_from_all_orders_and_grants(): void
    {
        $this->actor();
        $user = User::factory()->create();
        foreach (['paid', 'pending', 'failed', 'refunded'] as $status) {
            Order::query()->create(['user_id' => $user->id, 'currency' => 'RUB', 'amount_total' => 12345,
                'amount_final' => 12345, 'amount_paid' => $status === 'paid' ? 12345 : 0, 'payment_status' => $status]);
        }
        Order::query()->create(['user_id' => $user->id, 'currency' => 'USD', 'amount_total' => 300, 'amount_paid' => 300, 'payment_status' => 'paid']);
        $this->getJson("/api/v1/admin/users?q={$user->id}")->assertOk()
            ->assertJsonCount(2, 'data.0.paid_orders')
            ->assertJsonFragment(['currency' => 'RUB', 'count' => 1, 'amount' => 12345]);
        $this->getJson("/api/v1/admin/users/{$user->id}/orders?per_page=2&page=2")->assertOk()
            ->assertJsonPath('meta.total', 5)->assertJsonCount(2, 'data');
        $this->getJson('/api/v1/admin/users?per_page=101')->assertUnprocessable();
        $this->getJson('/api/v1/admin/users?page=0')->assertUnprocessable();
        $this->getJson("/api/v1/admin/users/{$user->id}")->assertOk()
            ->assertJsonPath('consent', null)->assertJsonPath('profile.school', null)
            ->assertJsonMissingPath('password')->assertJsonMissingPath('remember_token');
    }

    public function test_profile_allowlist_revision_and_audit_are_atomic(): void
    {
        $actor = $this->actor();
        $user = User::factory()->create(['name' => 'Before']);
        $url = "/api/v1/admin/users/{$user->id}/profile";
        $revision = $this->getJson("/api/v1/admin/users/{$user->id}")->assertOk()->json('revision');
        $nextRevision = $this->patchJson($url, ['revision' => $revision, 'name' => 'After', 'school' => 'Медвуз'])
            ->assertOk()->json('revision');
        $this->assertNotSame($revision, $nextRevision);
        $this->patchJson($url, ['revision' => $nextRevision, 'name' => 'After'])
            ->assertOk()->assertJsonPath('revision', $nextRevision);
        $this->assertDatabaseHas('users', ['id' => $user->id, 'name' => 'After']);
        $this->assertDatabaseHas('user_profiles', ['user_id' => $user->id, 'school' => 'Медвуз']);
        $this->assertDatabaseHas('admin_user_events', ['user_id' => $user->id, 'actor_id' => $actor->id, 'type' => 'profile']);
        $this->patchJson($url, ['revision' => $revision, 'name' => 'Lost'])->assertConflict();
        foreach (['role', 'email', 'phone', 'password', 'manager_id', 'phone_verified_at', 'amount_paid'] as $field) {
            $this->patchJson($url, ['revision' => $nextRevision, 'name' => 'Wrong', $field => 'forged'])->assertUnprocessable();
        }
        $this->patchJson($url, ['revision' => $nextRevision, 'birth_date' => '2099-01-01'])->assertUnprocessable();
        $this->assertSame('After', $user->fresh()->name);
        $this->assertSame(1, AdminUserEvent::query()->count());

        DB::statement("CREATE TRIGGER reject_admin_event BEFORE INSERT ON admin_user_events BEGIN SELECT RAISE(ABORT, 'test audit failure'); END");
        $this->patchJson($url, ['revision' => $nextRevision, 'name' => 'Rollback'])->assertServerError();
        $this->assertSame('After', $user->fresh()->name);
        $this->assertSame(1, UserProfile::query()->find($user->id)->revision);
    }

    public function test_profile_parameters_preserve_manual_fields_and_normalized_noop_has_no_event(): void
    {
        $actor = $this->actor();
        $user = User::factory()->create(['name' => 'Stored name']);
        $profile = new UserProfile(['school' => 'Stored school', 'birth_date' => '2000-01-02', 'gender' => 'female', 'enrollment_year' => 2020]);
        $profile->user_id = $user->id;
        $profile->revision = 3;
        $profile->save();
        $revision = AdminUserManagement::revisionFor($user, 3);
        $service = app(AdminUserManagement::class);
        $this->assertSame($revision, $service->mutate($actor, $user->id, 'profileParameters',
            ['birth_date' => '2000-01-02', 'gender' => 'female', 'revision' => $revision])['revision']);
        $this->assertDatabaseCount('admin_user_events', 0);
        $next = $service->mutate($actor, $user->id, 'profileParameters', ['gender' => 'other', 'revision' => $revision])['revision'];
        $this->assertNotSame($revision, $next);
        $this->assertSame('Stored name', $user->fresh()->name);
        $this->assertSame('Stored school', $profile->fresh()->school);
        $this->assertSame(4, $profile->fresh()->revision);
        try {
            $service->mutate($actor, $user->id, 'profileParameters', ['name' => 'Draft', 'revision' => $next]);
            $this->fail('Manual input must be refused by the parameter boundary.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('request', $exception->errors());
        }
        $this->patchJson('/api/v1/admin/users/'.$user->id.'/profile', ['enrollment_year' => '2020', 'revision' => $next])
            ->assertOk()->assertJsonPath('revision', $next);
        $this->assertDatabaseCount('admin_user_events', 1);
    }

    public function test_self_profile_name_change_invalidates_an_open_admin_card(): void
    {
        $actor = $this->actor();
        $base = "/api/v1/admin/users/{$actor->id}";
        $revision = $this->getJson($base)->assertOk()->json('revision');
        $this->patchJson('/api/v1/admin/me', ['name' => 'Fresh self-profile name'])->assertOk();
        $this->patchJson("$base/profile", ['revision' => $revision, 'name' => 'Stale card name'])
            ->assertConflict();
        $this->assertSame('Fresh self-profile name', $actor->fresh()->name);
        $this->assertDatabaseCount('admin_user_events', 0);
        $nextRevision = $this->getJson($base)->assertOk()->json('revision');
        $this->assertNotSame($revision, $nextRevision);
        $this->patchJson("$base/profile", ['revision' => $nextRevision, 'school' => 'Медвуз'])->assertOk();
        $this->assertSame('Fresh self-profile name', $actor->fresh()->name);
    }

    public function test_manager_assignment_tags_and_comment_retries(): void
    {
        $actor = $this->actor('admin_limited');
        $user = User::factory()->create();
        $manager = User::factory()->create(['role' => 'manager']);
        $base = "/api/v1/admin/users/{$user->id}";
        $revision = $this->getJson($base)->assertOk()->json('revision');
        $this->patchJson("$base/manager", ['revision' => $revision, 'manager_id' => $actor->id])->assertUnprocessable();
        $revision = $this->patchJson("$base/manager", ['revision' => $revision, 'manager_id' => $manager->id])->assertOk()->json('revision');
        $revision = $this->putJson("$base/tags", ['revision' => $revision, 'tags' => ['VIP', 'Поддержка']])->assertOk()->json('revision');
        $this->getJson($base)->assertOk()->assertJsonPath('manager.id', $manager->id)->assertJsonCount(2, 'tags');
        $data = ['comment' => 'Проверили доступ.', 'idempotency_key' => (string) Str::uuid()];
        $first = $this->postJson("$base/comments", $data)->assertOk()->json('event_id');
        $this->postJson("$base/comments", $data)->assertOk()->assertJsonPath('event_id', $first);
        $this->postJson("$base/comments", [...$data, 'comment' => 'Другое тело'])->assertConflict();
        $this->assertSame(1, AdminUserEvent::query()->where('type', 'comment')->count());
        Sanctum::actingAs($manager);
        $this->patchJson("$base/manager", ['revision' => $revision, 'manager_id' => null])->assertForbidden();
        $this->getJson("$base/events")->assertOk()->assertJsonPath('meta.total', 3);
        Sanctum::actingAs($actor);
        $revision = $this->patchJson("$base/manager", ['revision' => $revision, 'manager_id' => null])->assertOk()->json('revision');
        Sanctum::actingAs($manager);
        $this->putJson("$base/tags", ['revision' => $revision, 'tags' => []])->assertForbidden();
    }

    public function test_grant_history_does_not_claim_revoked_or_expired_access_is_active(): void
    {
        $this->actor();
        $user = User::factory()->create();
        foreach (['active', 'revoked', 'expired', 'pending'] as $index => $status) {
            UserEntitlementGrant::query()->create([
                'user_id' => $user->id, 'atom_kind' => 'lesson', 'atom_id' => $index + 1,
                'status' => $status, 'source_type' => 'admin_grant', 'source_id' => (string) Str::uuid(),
                'idempotency_key' => (string) Str::uuid(), 'granted_at' => now(),
            ]);
        }
        UserEntitlementGrant::query()->create([
            'user_id' => $user->id, 'atom_kind' => 'lesson', 'atom_id' => 99, 'status' => 'active',
            'source_type' => 'admin_grant', 'source_id' => (string) Str::uuid(),
            'idempotency_key' => (string) Str::uuid(), 'granted_at' => now()->subDays(2), 'expires_at' => now()->subDay(),
        ]);
        $response = $this->getJson("/api/v1/admin/users/{$user->id}/entitlements")->assertOk()->assertJsonPath('meta.total', 5);
        $this->assertCount(1, array_filter($response->json('data'), fn ($grant) => $grant['effective_active']));
        $this->getJson("/api/v1/admin/users?q={$user->id}")->assertOk()->assertJsonPath('data.0.paid_orders', []);
    }

    public function test_browser_mutation_requires_csrf_even_for_an_authenticated_admin(): void
    {
        $actor = $this->actor();
        $user = User::factory()->create();
        config()->set('sanctum.stateful', ['localhost:3000']);
        $this->app->instance('env', 'local');
        $this->actingAs($actor, 'web')->withHeader('Origin', 'http://localhost:3000')
            ->patchJson("/api/v1/admin/users/{$user->id}/profile", ['revision' => str_repeat('a', 64), 'name' => 'Forged'])
            ->assertStatus(419);
        $this->assertDatabaseCount('admin_user_events', 0);
    }
}
