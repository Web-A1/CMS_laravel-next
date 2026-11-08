<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AuthRbacApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.redsms.mode' => 'mock']);
    }

    public function test_me_requires_authentication(): void
    {
        $this->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_register_requires_only_email_password_and_email_verification(): void
    {
        $email = 'ivan@example.com';
        $emailVerificationToken = 'email-token-for-test';

        DB::table('pre_registration_verifications')->insert([
            'verification_id' => (string) Str::uuid(),
            'channel' => 'email',
            'target' => $email,
            'code_hash' => Hash::make('123456'),
            'attempts_left' => 10,
            'status' => 'confirmed',
            'expires_at' => now()->addMinutes(5),
            'verification_token_hash' => Hash::make($emailVerificationToken),
            'verification_token_expires_at' => now()->addMinutes(5),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $response = $this->postJson('/api/v1/auth/register', [
            'email' => 'IVAN@example.com',
            'password' => 'super-secret-123',
            'email_verification_token' => $emailVerificationToken,
            'privacy_policy_accepted' => true,
            'offer_accepted' => true,
            'marketing_accepted' => false,
        ]);

        $response->assertCreated()
            ->assertJsonPath('status', 'created')
            ->assertJsonPath('next_step', 'lk');

        $this->assertDatabaseHas('users', [
            'email' => 'ivan@example.com',
            'phone' => null,
        ]);
        $this->assertDatabaseHas('registration_consents', [
            'privacy_policy_version' => 'placeholder-2026-05',
            'offer_version' => 'placeholder-2026-05',
            'marketing_opt_in' => false,
        ]);
    }

    public function test_login_supports_email_or_phone_with_password(): void
    {
        User::factory()->create([
            'email' => 'login@example.com',
            'phone' => '+79991230000',
            'password' => 'super-secret-123',
            'phone_verified_at' => now(),
        ]);

        $this->postJson('/api/v1/auth/login', [
            'login' => 'login@example.com',
            'password' => 'super-secret-123',
        ])->assertOk()->assertJsonPath('next_step', 'lk');

        $this->postJson('/api/v1/auth/login', [
            'login' => '+7 (999) 123-00-00',
            'password' => 'super-secret-123',
        ])->assertOk()->assertJsonPath('next_step', 'lk');
    }

    public function test_login_allows_account_without_verified_phone(): void
    {
        User::factory()->create([
            'email' => 'legacy@example.com',
            'phone' => '+79995554433',
            'password' => 'super-secret-123',
            'phone_verified_at' => null,
        ]);

        $this->postJson('/api/v1/auth/login', [
            'login' => 'legacy@example.com',
            'password' => 'super-secret-123',
        ])->assertOk()->assertJsonPath('next_step', 'lk');
    }

    public function test_staff_login_uses_existing_phone_exemption_without_claiming_verified_phone(): void
    {
        foreach (['admin_full', 'admin_limited', 'manager', 'teacher'] as $role) {
            $user = User::factory()->create([
                'email' => $role.'@staff.example.test',
                'phone' => null,
                'phone_verified_at' => null,
                'role' => $role,
                'password' => 'staff-test-secret',
            ]);

            $this->postJson('/api/v1/auth/login', [
                'login' => $user->email,
                'password' => 'staff-test-secret',
            ])->assertOk()->assertJsonPath('next_step', 'admin');
            $this->getJson('/api/v1/auth/me')->assertOk()
                ->assertJsonPath('phone_verified', false)
                ->assertJsonPath('must_verify_phone', false);
            $this->assertNull($user->fresh()->phone_verified_at);
            $this->postJson('/api/v1/auth/logout')->assertOk();
        }
    }

    public function test_login_has_rate_limit(): void
    {
        User::factory()->create([
            'email' => 'limited-login@example.com',
            'phone' => '+79995550000',
            'password' => 'super-secret-123',
            'phone_verified_at' => now(),
        ]);

        for ($attempt = 0; $attempt < 10; $attempt++) {
            $this->postJson('/api/v1/auth/login', [
                'login' => 'limited-login@example.com',
                'password' => 'wrong-password',
            ])->assertUnauthorized();
        }

        $this->postJson('/api/v1/auth/login', [
            'login' => 'limited-login@example.com',
            'password' => 'wrong-password',
        ])
            ->assertStatus(429)
            ->assertJsonPath('error.code', 'VERIFY_RATE_LIMIT');
    }

    public function test_start_phone_verification_has_rate_limit(): void
    {
        config([
            'services.redsms.mode' => 'live',
            'services.redsms.base_url' => 'https://cp.redsms.ru',
            'services.redsms.login' => 'test-fixture',
            'services.redsms.api_key' => 'test-only-key',
        ]);
        Http::preventStrayRequests();
        Http::fake(['https://cp.redsms.ru/api/message' => Http::response(['uuid' => 'rate-limit-flow'])]);
        $user = User::factory()->create(['phone_verified_at' => null]);
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/auth/phone-verification/start', [
            'phone' => '+79990001122',
        ])->assertStatus(202);

        $this->postJson('/api/v1/auth/phone-verification/start', [
            'phone' => '+79990001122',
        ])->assertStatus(429)
            ->assertJsonPath('error.code', 'VERIFY_RATE_LIMIT');
    }

    public function test_mock_mode_auto_confirms_phone_verification(): void
    {
        config(['services.redsms.mode' => 'mock']);
        $user = User::factory()->create([
            'phone' => '+79991112233',
            'phone_verified_at' => null,
        ]);
        Sanctum::actingAs($user);

        $start = $this->postJson('/api/v1/auth/phone-verification/start', [
            'phone' => '+79991112233',
        ])->assertStatus(202);

        $verificationId = $start->json('verification_id');
        $this->assertIsString($verificationId);

        $this->getJson('/api/v1/auth/phone-verification/status?verification_id='.$verificationId)
            ->assertOk()
            ->assertJsonPath('status', 'confirmed');
    }

    public function test_phone_verification_callback_confirms_phone(): void
    {
        $user = User::factory()->create([
            'phone' => '+79990001122',
            'phone_verified_at' => null,
        ]);
        Sanctum::actingAs($user);

        $start = $this->postJson('/api/v1/auth/phone-verification/start', [
            'phone' => '+79990001122',
        ])->assertStatus(202);

        $verificationId = $start->json('verification_id');
        $this->assertIsString($verificationId);

        $this->postJson('/api/v1/auth/phone-verification/callback/redsms', [
            'verification_id' => $verificationId,
            'provider_event_id' => 'evt_1',
            'status' => 'confirmed',
        ])->assertOk()->assertJsonPath('status', 'accepted');

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
        ]);
        $this->assertNotNull($user->fresh()->phone_verified_at);
    }

    public function test_phone_verification_callback_handles_unique_phone_conflict(): void
    {
        $phone = '+79990004455';
        $owner = User::factory()->create(['phone' => $phone, 'phone_verified_at' => now()]);
        $challenger = User::factory()->create(['phone' => null, 'phone_verified_at' => null]);
        $verificationId = (string) Str::uuid();

        DB::table('phone_verification_codes')->insert([
            'user_id' => $challenger->id,
            'phone' => $phone,
            'user_phone_at_start' => '',
            'code_hash' => Hash::make('000000'),
            'attempts_left' => 5,
            'status' => 'pending',
            'expires_at' => now()->addMinutes(10),
            'verification_id' => $verificationId,
            'provider_uuid' => 'provider-conflict',
            'call_number' => '+78001002030',
            'provider_payload' => null,
            'provider_event_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->postJson('/api/v1/auth/phone-verification/callback/redsms', [
            'verification_id' => $verificationId,
            'provider_event_id' => 'evt-conflict',
            'status' => 'confirmed',
        ])->assertOk()->assertJsonPath('status', 'accepted');

        $this->assertDatabaseHas('phone_verification_codes', [
            'verification_id' => $verificationId,
            'status' => 'failed',
        ]);
        $this->assertNull($challenger->fresh()->phone);
        $this->assertNotNull($owner->fresh()->phone_verified_at);
    }

    public function test_live_mode_starts_phone_verification_via_redsms_and_accepts_uuid_callback(): void
    {
        config([
            'services.redsms.mode' => 'live',
            'services.redsms.base_url' => 'https://cp.redsms.ru',
            'services.redsms.login' => 'test-login',
            'services.redsms.api_key' => 'test-key',
        ]);

        Http::fake([
            'https://cp.redsms.ru/api/message' => Http::response([
                'uuid' => 'prov-uuid-1',
                'replacedFrom' => '+78001002030',
            ], 200),
        ]);

        $user = User::factory()->create([
            'phone' => '+79995556677',
            'phone_verified_at' => null,
        ]);
        Sanctum::actingAs($user);

        $start = $this->postJson('/api/v1/auth/phone-verification/start', [
            'phone' => '+79995556677',
        ])->assertStatus(202);

        $verificationId = $start->json('verification_id');
        $this->assertSame('+78001002030', $start->json('call_number'));

        $this->postJson('/api/v1/auth/phone-verification/callback/redsms', [
            'uuid' => 'prov-uuid-1',
            'status' => 'wcall_delivered',
        ])->assertOk()->assertJsonPath('status', 'accepted');

        $this->assertDatabaseHas('phone_verification_codes', [
            'verification_id' => $verificationId,
            'provider_uuid' => 'prov-uuid-1',
            'status' => 'confirmed',
        ]);
        $this->assertNotNull($user->fresh()->phone_verified_at);
    }

    public function test_redsms_callback_accepts_configured_source_ip_outside_testing_env(): void
    {
        config([
            'app.env' => 'staging',
            'services.redsms.webhook_allowed_ips' => ['37.9.15.244'],
        ]);

        $user = User::factory()->create([
            'phone' => '+79990002233',
            'phone_verified_at' => null,
        ]);

        DB::table('phone_verification_codes')->insert([
            'user_id' => $user->id,
            'phone' => '+79990002233',
            'user_phone_at_start' => (string) $user->phone,
            'code_hash' => Hash::make('000000'),
            'attempts_left' => 5,
            'status' => 'pending',
            'expires_at' => now()->addMinutes(10),
            'verification_id' => (string) Str::uuid(),
            'provider_uuid' => 'provider-allowed-ip',
            'call_number' => '+78001002030',
            'provider_payload' => null,
            'provider_event_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->withServerVariables(['REMOTE_ADDR' => '37.9.15.244'])
            ->postJson('/api/v1/auth/phone-verification/callback/redsms', [
                'uuid' => 'provider-allowed-ip',
                'status' => 'wcall_delivered',
            ])->assertOk()->assertJsonPath('status', 'accepted');

        $this->assertNotNull($user->fresh()->phone_verified_at);
    }

    public function test_redsms_callback_rejects_unconfigured_source_ip_outside_testing_env(): void
    {
        config([
            'app.env' => 'staging',
            'services.redsms.webhook_allowed_ips' => ['37.9.15.244'],
        ]);

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
            ->postJson('/api/v1/auth/phone-verification/callback/redsms', [
                'uuid' => 'provider-rejected-ip',
                'status' => 'wcall_delivered',
            ])->assertForbidden()->assertJsonPath('error.code', 'AUTH_FORBIDDEN');
    }

    public function test_forgot_and_reset_password_flow_works(): void
    {
        $user = User::factory()->create([
            'email' => 'reset@example.com',
            'password' => 'old-password-123',
        ]);

        $forgot = $this->postJson('/api/v1/auth/password/forgot', [
            'email' => 'reset@example.com',
        ])->assertStatus(202)->assertJsonPath('status', 'accepted');

        $token = $forgot->json('debug_reset_token');
        $this->assertIsString($token);

        $this->postJson('/api/v1/auth/password/reset', [
            'email' => 'reset@example.com',
            'token' => $token,
            'password' => 'new-password-123',
        ])->assertOk()->assertJsonPath('status', 'password_updated');

        $this->postJson('/api/v1/auth/login', [
            'login' => 'reset@example.com',
            'password' => 'new-password-123',
        ])->assertOk();
    }

    public function test_authenticated_user_can_verify_email_by_code(): void
    {
        $user = User::factory()->create([
            'email' => 'verify-email@example.com',
            'email_verified_at' => null,
        ]);
        Sanctum::actingAs($user);

        $start = $this->postJson('/api/v1/auth/email-verification/start')
            ->assertStatus(202)
            ->assertJsonPath('status', 'pending');

        $code = $start->json('debug_code');
        $this->assertIsString($code);

        $this->postJson('/api/v1/auth/email-verification/verify', [
            'code' => $code,
        ])->assertOk()->assertJsonPath('status', 'confirmed');

        $this->assertNotNull($user->fresh()->email_verified_at);
    }

    public function test_preverify_phone_status_requires_status_token(): void
    {
        $start = $this->postJson('/api/v1/auth/preverify/phone/start', [
            'phone' => '+79990001122',
        ])->assertStatus(202);

        $verificationId = $start->json('verification_id');
        $statusToken = $start->json('status_token');
        $this->assertIsString($verificationId);
        $this->assertIsString($statusToken);

        $this->getJson('/api/v1/auth/preverify/phone/status?verification_id='.$verificationId)
            ->assertStatus(422);
    }

    public function test_preverify_phone_status_rejects_invalid_status_token(): void
    {
        $start = $this->postJson('/api/v1/auth/preverify/phone/start', [
            'phone' => '+79990001123',
        ])->assertStatus(202);

        $verificationId = $start->json('verification_id');
        $this->assertIsString($verificationId);

        $this->getJson('/api/v1/auth/preverify/phone/status?verification_id='.$verificationId.'&status_token='.str_repeat('x', 40))
            ->assertNotFound()
            ->assertJsonPath('error.code', 'VERIFY_NOT_FOUND');
    }

    public function test_preverify_email_verify_rejects_expired_code(): void
    {
        DB::table('pre_registration_verifications')->insert([
            'verification_id' => (string) Str::uuid(),
            'channel' => 'email',
            'target' => 'expired@example.com',
            'code_hash' => Hash::make('123456'),
            'attempts_left' => 10,
            'status' => 'pending',
            'expires_at' => now()->subMinute(),
            'created_at' => now()->subMinutes(2),
            'updated_at' => now()->subMinutes(2),
        ]);

        $this->postJson('/api/v1/auth/preverify/email/verify', [
            'email' => 'expired@example.com',
            'code' => '123456',
        ])->assertStatus(422)->assertJsonPath('error.code', 'VERIFY_CODE_INVALID');
    }

    public function test_preverify_email_code_cannot_be_reused(): void
    {
        DB::table('pre_registration_verifications')->insert([
            'verification_id' => (string) Str::uuid(),
            'channel' => 'email',
            'target' => 'reuse@example.com',
            'code_hash' => Hash::make('123456'),
            'attempts_left' => 10,
            'status' => 'pending',
            'expires_at' => now()->addMinutes(5),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->postJson('/api/v1/auth/preverify/email/verify', [
            'email' => 'reuse@example.com',
            'code' => '123456',
        ])->assertOk()->assertJsonPath('status', 'confirmed');

        $this->postJson('/api/v1/auth/preverify/email/verify', [
            'email' => 'reuse@example.com',
            'code' => '123456',
        ])->assertStatus(422)->assertJsonPath('error.code', 'VERIFY_CODE_INVALID');
    }

    public function test_manager_gets_403_when_scope_is_not_own(): void
    {
        $manager = User::factory()->create(['role' => 'manager']);
        $target = User::factory()->create(['manager_id' => null]);

        Sanctum::actingAs($manager);

        $this->getJson("/api/v1/admin/users/{$target->id}")
            ->assertForbidden();
    }

    public function test_manager_can_read_user_in_own_scope(): void
    {
        $manager = User::factory()->create(['role' => 'manager']);
        $target = User::factory()->create(['manager_id' => $manager->id]);

        Sanctum::actingAs($manager);

        $this->getJson("/api/v1/admin/users/{$target->id}")
            ->assertOk()
            ->assertJsonPath('id', $target->id);
    }
}
