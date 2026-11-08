<?php

namespace Tests\Feature;

use App\Models\AdminOperationEvent;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminTestUserDeletionApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_delete_fresh_test_user_and_register_same_identity_again(): void
    {
        $actor = User::factory()->create(['role' => 'admin_full']);
        Sanctum::actingAs($actor);
        $email = 'repeatable@example.test';
        $phone = '+79991112233';
        $user = User::factory()->create([
            'email' => $email,
            'phone' => $phone,
            'role' => 'user_no_purchase',
        ]);

        DB::table('registration_consents')->insert([
            'user_id' => $user->id,
            'privacy_policy_version' => 'test',
            'offer_version' => 'test',
            'privacy_policy_accepted_at' => now(),
            'offer_accepted_at' => now(),
            'marketing_opt_in' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('sessions')->insert([
            'id' => 'session-'.$user->id,
            'user_id' => $user->id,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'test',
            'payload' => 'test',
            'last_activity' => now()->timestamp,
        ]);
        DB::table('password_reset_tokens')->insert(['email' => $email, 'token' => 'hashed', 'created_at' => now()]);
        DB::table('pre_registration_verifications')->insert([
            'verification_id' => (string) Str::uuid(),
            'channel' => 'email',
            'target' => $email,
            'status' => 'consumed',
            'expires_at' => now()->addHour(),
            'consumed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $trustedBrowserAccountId = DB::table('trusted_browser_accounts')->insertGetId([
            'user_id' => $user->id,
            'cookie_key' => str_repeat('a', 32),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('trusted_browser_audit_events')->insert([
            'account_id' => $trustedBrowserAccountId,
            'user_id' => $user->id,
            'event_type' => 'enrolled',
            'created_at' => now(),
        ]);

        $key = (string) Str::uuid();
        $this->deleteJson("/api/v1/admin/users/{$user->id}/test-account", [
            'idempotency_key' => $key,
        ])->assertOk()->assertJsonPath('deleted', true)->assertJsonPath('user_id', $user->id);

        $this->assertDatabaseMissing('users', ['id' => $user->id]);
        $this->assertDatabaseMissing('registration_consents', ['user_id' => $user->id]);
        $this->assertDatabaseMissing('sessions', ['user_id' => $user->id]);
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $email]);
        $this->assertDatabaseMissing('pre_registration_verifications', ['target' => $email]);
        $this->assertDatabaseMissing('trusted_browser_accounts', ['user_id' => $user->id]);
        $this->assertDatabaseMissing('trusted_browser_audit_events', ['user_id' => $user->id]);
        $this->assertDatabaseHas('admin_operation_events', [
            'operation' => 'delete_test_account', 'resource' => 'User', 'resource_id' => $user->id,
            'idempotency_key' => $key,
        ]);

        $replacement = User::factory()->create(['email' => $email, 'phone' => $phone, 'role' => 'user_no_purchase']);
        $this->assertSame($email, $replacement->email);
    }

    public function test_only_full_admin_can_delete_without_email_confirmation(): void
    {
        $user = User::factory()->create(['email' => 'safe@example.test', 'phone' => null, 'role' => 'user_no_purchase']);
        Sanctum::actingAs(User::factory()->create(['role' => 'admin_limited']));
        $this->deleteJson("/api/v1/admin/users/{$user->id}/test-account", [
            'idempotency_key' => (string) Str::uuid(),
        ])->assertForbidden();

        Sanctum::actingAs(User::factory()->create(['role' => 'admin_full']));
        $this->deleteJson("/api/v1/admin/users/{$user->id}/test-account", [
            'idempotency_key' => (string) Str::uuid(),
        ])->assertOk()->assertJsonPath('deleted', true);
        $this->assertDatabaseMissing('users', ['id' => $user->id]);
    }

    public function test_user_with_orders_is_never_purged_by_test_reset(): void
    {
        $actor = User::factory()->create(['role' => 'admin_full']);
        Sanctum::actingAs($actor);
        $user = User::factory()->create(['email' => 'has-order@example.test', 'role' => 'user_no_purchase']);
        Order::query()->create(['user_id' => $user->id, 'amount_total' => 100, 'amount_final' => 100]);

        $this->deleteJson("/api/v1/admin/users/{$user->id}/test-account", [
            'idempotency_key' => (string) Str::uuid(),
        ])->assertConflict();
        $this->assertDatabaseHas('users', ['id' => $user->id]);
        $this->assertDatabaseHas('orders', ['user_id' => $user->id]);
    }

    public function test_existing_operation_history_for_target_blocks_test_reset(): void
    {
        $actor = User::factory()->create(['role' => 'admin_full']);
        Sanctum::actingAs($actor);
        $user = User::factory()->create(['email' => 'operation-history@example.test', 'role' => 'user_no_purchase']);
        AdminOperationEvent::query()->create([
            'actor_id' => $actor->id,
            'operation' => 'update',
            'resource' => 'User',
            'resource_id' => $user->id,
            'idempotency_key' => (string) Str::uuid(),
            'payload' => ['name' => 'changed'],
        ]);

        $this->deleteJson("/api/v1/admin/users/{$user->id}/test-account", [
            'idempotency_key' => (string) Str::uuid(),
        ])->assertConflict();
        $this->assertDatabaseHas('users', ['id' => $user->id]);
    }

    public function test_endpoint_is_disabled_outside_non_production_environments(): void
    {
        $actor = User::factory()->create(['role' => 'admin_full']);
        $user = User::factory()->create(['email' => 'production-safe@example.test', 'role' => 'user_no_purchase']);
        Sanctum::actingAs($actor);
        $this->app->instance('env', 'production');

        $this->deleteJson("/api/v1/admin/users/{$user->id}/test-account", [
            'idempotency_key' => (string) Str::uuid(),
        ])->assertForbidden();
        $this->assertDatabaseHas('users', ['id' => $user->id]);
    }
}
