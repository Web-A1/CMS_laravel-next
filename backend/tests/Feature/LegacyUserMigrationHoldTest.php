<?php

namespace Tests\Feature;

use App\Jobs\SendPreRegistrationEmailCodeJob;
use App\Jobs\StartPreRegistrationPhoneVerificationJob;
use App\Models\User;
use App\Services\RedsmsApiClient;
use App\Services\SmtpBzApiClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class LegacyUserMigrationHoldTest extends TestCase
{
    use RefreshDatabase;

    private const HOLD_CODE = 'AUTH_ACCOUNT_MIGRATION_HELD';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.redsms.mode' => 'live',
            'services.redsms.base_url' => 'https://cp.redsms.ru',
            'services.redsms.login' => 'test-login',
            'services.redsms.api_key' => 'test-key',
            'services.smtpbz.enabled' => true,
        ]);
    }

    public function test_migration_hold_field_is_hidden_and_not_publicly_fillable(): void
    {
        $user = $this->heldUser();

        $this->assertTrue($user->isMigrationHeld());
        $this->assertTrue($user->hasDormantMigrationPassword());
        $this->assertTrue($user->blocksAuthenticationForMigration());
        $this->assertNotContains('migration_hold', (new User)->getFillable());
        $this->assertArrayNotHasKey('migration_hold', $user->toArray());
    }

    public function test_dormant_password_state_blocks_authentication_after_boolean_hold_is_cleared(): void
    {
        $user = User::factory()->create([
            'email' => 'dormant-only@example.test',
            'phone' => '+79990001011',
            'password' => 'old-password-123',
            'phone_verified_at' => now(),
        ]);
        $placeholder = '!migration-held:'.bin2hex(random_bytes(32));
        $resetToken = 'reset-token-'.Str::random(24);
        $plainTextToken = $user->createToken('dormant-token')->plainTextToken;

        DB::table('users')->where('id', $user->id)->update([
            'migration_hold' => false,
            'password_state' => User::PASSWORD_STATE_MIGRATION_HOLD,
            'password' => $placeholder,
            'updated_at' => now(),
        ]);
        DB::table('password_reset_tokens')->insert([
            'email' => $user->email,
            'token' => Hash::make($resetToken),
            'created_at' => now(),
        ]);

        $user->refresh();
        $this->assertFalse($user->isMigrationHeld());
        $this->assertTrue($user->hasDormantMigrationPassword());
        $this->assertTrue($user->blocksAuthenticationForMigration());

        $this->postJson('/api/v1/auth/login', [
            'login' => $user->email,
            'password' => 'old-password-123',
        ])->assertForbidden()->assertJsonPath('error.code', self::HOLD_CODE);

        $this->withToken($plainTextToken)
            ->getJson('/api/v1/auth/me')
            ->assertForbidden()
            ->assertJsonPath('error.code', self::HOLD_CODE);
        $this->assertDatabaseCount('personal_access_tokens', 0);

        $this->postJson('/api/v1/auth/password/reset', [
            'email' => $user->email,
            'token' => $resetToken,
            'password' => 'new-password-123',
        ])->assertForbidden()->assertJsonPath('error.code', self::HOLD_CODE);

        $user->refresh();
        $this->assertSame($placeholder, $user->password);
        $this->assertSame(User::PASSWORD_STATE_MIGRATION_HOLD, $user->password_state);
        $this->assertDatabaseHas('password_reset_tokens', [
            'email' => 'dormant-only@example.test',
        ]);
    }

    public function test_existing_accounts_keep_login_session_and_public_profile_behavior(): void
    {
        $user = User::factory()->create([
            'email' => 'active@example.test',
            'phone' => '+79990001001',
            'password' => 'active-password-123',
            'phone_verified_at' => now(),
        ]);

        $this->postJson('/api/v1/auth/login', [
            'login' => $user->email,
            'password' => 'active-password-123',
        ])->assertOk()->assertJsonPath('next_step', 'lk');

        $this->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('email', 'active@example.test')
            ->assertJsonMissingPath('migration_hold');
    }

    public function test_held_account_login_is_blocked_before_placeholder_password_hash_check(): void
    {
        $user = $this->heldUser([
            'email' => 'held-login@example.test',
            'phone' => '+79990001002',
        ]);

        $this->postJson('/api/v1/auth/login', [
            'login' => $user->email,
            'password' => 'any-password-123',
        ])->assertForbidden()->assertJsonPath('error.code', self::HOLD_CODE);

        $this->assertNull($user->fresh()->last_auth_method);
        $this->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_held_identity_blocks_preverification_register_and_forgot_without_outgoing_effects(): void
    {
        $this->denyExternalEffects();
        $user = $this->heldUser([
            'email' => 'held-effects@example.test',
            'phone' => '+79990001003',
        ]);
        $emailToken = 'email-token-'.Str::random(24);
        $phoneToken = (string) Str::uuid();

        DB::table('pre_registration_verifications')->insert([
            'verification_id' => (string) Str::uuid(),
            'channel' => 'email',
            'target' => $user->email,
            'code_hash' => Hash::make('123456'),
            'attempts_left' => 10,
            'status' => 'confirmed',
            'expires_at' => now()->addMinutes(5),
            'verification_token_hash' => Hash::make($emailToken),
            'verification_token_expires_at' => now()->addMinutes(5),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('pre_registration_verifications')->insert([
            'verification_id' => $phoneToken,
            'channel' => 'phone',
            'target' => $user->phone,
            'attempts_left' => 1,
            'status' => 'confirmed',
            'expires_at' => now()->addMinutes(5),
            'verification_token_expires_at' => now()->addMinutes(5),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->postJson('/api/v1/auth/preverify/email/start', [
            'email' => $user->email,
        ])->assertForbidden()->assertJsonPath('error.code', self::HOLD_CODE);
        $this->postJson('/api/v1/auth/preverify/phone/start', [
            'phone' => $user->phone,
        ])->assertForbidden()->assertJsonPath('error.code', self::HOLD_CODE);
        $this->postJson('/api/v1/auth/register', [
            'name' => 'Held User',
            'email' => $user->email,
            'phone' => $user->phone,
            'password' => 'new-password-123',
            'email_verification_token' => $emailToken,
            'phone_verification_token' => $phoneToken,
            'privacy_policy_accepted' => true,
            'offer_accepted' => true,
        ])->assertForbidden()->assertJsonPath('error.code', self::HOLD_CODE);
        $this->postJson('/api/v1/auth/password/forgot', [
            'email' => $user->email,
        ])->assertStatus(202)->assertJsonPath('status', 'accepted');

        $this->assertSame(1, User::query()->where('email', $user->email)->count());
        $this->assertDatabaseCount('password_reset_tokens', 0);
        $this->assertDatabaseMissing('pre_registration_verifications', [
            'target' => $user->email,
            'status' => 'pending',
        ]);
        $this->assertDatabaseMissing('pre_registration_verifications', [
            'target' => $user->phone,
            'status' => 'pending',
        ]);
        $this->assertExternalEffectsWereNotUsed();
    }

    public function test_pending_preverification_artifacts_for_held_identity_are_not_confirmed_or_consumed(): void
    {
        $user = $this->heldUser([
            'email' => 'held-preverify@example.test',
            'phone' => '+79990001004',
        ]);
        $statusToken = 'status-token-'.Str::random(32);
        $phoneVerificationId = (string) Str::uuid();

        DB::table('pre_registration_verifications')->insert([
            'verification_id' => (string) Str::uuid(),
            'channel' => 'email',
            'target' => $user->email,
            'code_hash' => Hash::make('123456'),
            'attempts_left' => 10,
            'status' => 'pending',
            'expires_at' => now()->addMinutes(5),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('pre_registration_verifications')->insert([
            'verification_id' => $phoneVerificationId,
            'channel' => 'phone',
            'target' => $user->phone,
            'attempts_left' => 1,
            'status' => 'pending',
            'provider_uuid' => 'provider-held-preverify',
            'status_token_hash' => Hash::make($statusToken),
            'expires_at' => now()->addMinutes(5),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->postJson('/api/v1/auth/preverify/email/verify', [
            'email' => $user->email,
            'code' => '123456',
        ])->assertForbidden()->assertJsonPath('error.code', self::HOLD_CODE);
        $this->getJson('/api/v1/auth/preverify/phone/status?'.http_build_query([
            'verification_id' => $phoneVerificationId,
            'status_token' => $statusToken,
        ]))->assertForbidden()->assertJsonPath('error.code', self::HOLD_CODE);
        $this->postJson('/api/v1/auth/preverify/phone/callback/redsms', [
            'uuid' => 'provider-held-preverify',
            'provider_event_id' => 'evt-held-preverify',
            'status' => 'wcall_delivered',
        ])->assertOk()->assertJsonPath('status', 'accepted');

        $this->assertDatabaseHas('pre_registration_verifications', [
            'target' => $user->email,
            'status' => 'pending',
            'attempts_left' => 10,
            'consumed_at' => null,
        ]);
        $this->assertDatabaseHas('pre_registration_verifications', [
            'target' => $user->phone,
            'status' => 'pending',
            'confirmed_at' => null,
            'verification_token_expires_at' => null,
        ]);
    }

    public function test_existing_reset_token_cannot_set_password_for_held_account(): void
    {
        $user = $this->heldUser([
            'email' => 'held-reset@example.test',
            'phone' => '+79990001010',
        ]);
        $placeholder = (string) $user->password;
        $resetToken = 'reset-token-'.Str::random(24);
        DB::table('password_reset_tokens')->insert([
            'email' => $user->email,
            'token' => Hash::make($resetToken),
            'created_at' => now(),
        ]);

        $this->postJson('/api/v1/auth/password/reset', [
            'email' => $user->email,
            'token' => $resetToken,
            'password' => 'new-password-123',
        ])->assertForbidden()->assertJsonPath('error.code', self::HOLD_CODE);

        $user->refresh();
        $this->assertSame($placeholder, $user->password);
        $this->assertSame(User::PASSWORD_STATE_MIGRATION_HOLD, $user->password_state);
        $this->assertDatabaseHas('password_reset_tokens', [
            'email' => 'held-reset@example.test',
        ]);
    }

    public function test_existing_session_is_rejected_after_account_is_held(): void
    {
        $sessionUser = User::factory()->create([
            'email' => 'held-session@example.test',
            'phone' => '+79990001005',
            'password' => 'session-password-123',
            'phone_verified_at' => now(),
        ]);
        $this->postJson('/api/v1/auth/login', [
            'login' => $sessionUser->email,
            'password' => 'session-password-123',
        ])->assertOk();
        $this->getJson('/api/v1/auth/me')->assertOk();
        $this->holdExistingUser($sessionUser);

        $this->getJson('/api/v1/auth/me')
            ->assertForbidden()
            ->assertJsonPath('error.code', self::HOLD_CODE);
        $this->getJson('/api/v1/auth/me')
            ->assertForbidden()
            ->assertJsonPath('error.code', self::HOLD_CODE);
    }

    public function test_existing_bearer_token_is_rejected_and_deleted_after_account_is_held(): void
    {
        $tokenUser = User::factory()->create([
            'email' => 'held-token@example.test',
            'phone' => '+79990001006',
            'phone_verified_at' => now(),
        ]);
        $plainTextToken = $tokenUser->createToken('migration-hold-test')->plainTextToken;
        $this->assertDatabaseCount('personal_access_tokens', 1);
        $this->holdExistingUser($tokenUser);

        $this->withToken($plainTextToken)
            ->getJson('/api/v1/auth/me')
            ->assertForbidden()
            ->assertJsonPath('error.code', self::HOLD_CODE);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_held_authenticated_verification_routes_do_not_send_or_mutate(): void
    {
        $this->denyExternalEffects();
        $emailUser = $this->heldUser([
            'email' => 'held-email-verify@example.test',
            'phone' => '+79990001007',
            'email_verified_at' => null,
        ]);
        $phoneUser = $this->heldUser([
            'email' => 'held-phone-verify@example.test',
            'phone' => '+79990001008',
            'phone_verified_at' => null,
        ]);
        $emailToken = $emailUser->createToken('email-verification-held')->plainTextToken;
        $phoneToken = $phoneUser->createToken('phone-verification-held')->plainTextToken;

        $this->withToken($emailToken)
            ->postJson('/api/v1/auth/email-verification/start')
            ->assertForbidden()
            ->assertJsonPath('error.code', self::HOLD_CODE);
        $this->assertDatabaseCount('auth_login_codes', 0);

        $this->withToken($phoneToken)
            ->postJson('/api/v1/auth/phone-verification/start', [
                'phone' => '+79990001008',
            ])->assertForbidden()
            ->assertJsonPath('error.code', self::HOLD_CODE);
        $this->assertDatabaseCount('phone_verification_codes', 0);
        $this->assertNull($phoneUser->fresh()->phone_verified_at);
        $this->assertExternalEffectsWereNotUsed();
    }

    public function test_existing_phone_verification_callback_cannot_activate_held_user(): void
    {
        $user = $this->heldUser([
            'email' => 'held-callback@example.test',
            'phone' => '+79990001009',
            'phone_verified_at' => null,
        ]);
        $verificationId = (string) Str::uuid();
        DB::table('phone_verification_codes')->insert([
            'verification_id' => $verificationId,
            'user_id' => $user->id,
            'phone' => '+79990001009',
            'code_hash' => Hash::make(Str::random(40)),
            'attempts_left' => 3,
            'status' => 'pending',
            'provider_uuid' => 'provider-held-user',
            'expires_at' => now()->addMinutes(10),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->postJson('/api/v1/auth/phone-verification/callback/redsms', [
            'uuid' => 'provider-held-user',
            'provider_event_id' => 'evt-held-user',
            'status' => 'wcall_delivered',
        ])->assertOk()->assertJsonPath('status', 'accepted');

        $this->assertNull($user->fresh()->phone_verified_at);
        $this->assertDatabaseHas('phone_verification_codes', [
            'verification_id' => $verificationId,
            'status' => 'pending',
            'confirmed_at' => null,
            'consumed_at' => null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function heldUser(array $attributes = []): User
    {
        $user = User::factory()->create([
            'email' => $attributes['email'] ?? 'held-'.Str::random(8).'@example.test',
            'phone' => $attributes['phone'] ?? '+7999'.random_int(1000000, 9999999),
            'email_verified_at' => $attributes['email_verified_at'] ?? null,
            'phone_verified_at' => $attributes['phone_verified_at'] ?? null,
        ]);

        return $this->holdExistingUser($user);
    }

    private function holdExistingUser(User $user): User
    {
        DB::table('users')->where('id', $user->id)->update([
            'migration_hold' => true,
            'password_state' => User::PASSWORD_STATE_MIGRATION_HOLD,
            'password' => '!migration-held:'.bin2hex(random_bytes(32)),
            'last_auth_method' => null,
            'updated_at' => now(),
        ]);

        return $user->refresh();
    }

    private function denyExternalEffects(): void
    {
        Queue::fake();
        Mail::spy();
        Http::fake();

        $smtp = Mockery::mock(SmtpBzApiClient::class);
        $smtp->shouldNotReceive('sendHtmlMessage');
        $this->app->instance(SmtpBzApiClient::class, $smtp);

        $redsms = Mockery::mock(RedsmsApiClient::class);
        $redsms->shouldNotReceive('startWaitCall');
        $this->app->instance(RedsmsApiClient::class, $redsms);
    }

    private function assertExternalEffectsWereNotUsed(): void
    {
        Queue::assertNotPushed(SendPreRegistrationEmailCodeJob::class);
        Queue::assertNotPushed(StartPreRegistrationPhoneVerificationJob::class);
        Mail::shouldNotHaveReceived('raw');
        Http::assertNothingSent();
    }
}
