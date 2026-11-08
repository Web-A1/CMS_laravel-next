<?php

namespace Tests\Feature;

use App\Models\User;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PhoneVerificationFreshnessTest extends TestCase
{
    use RefreshDatabase;

    private ?Closure $providerResponder = null;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.redsms.mode' => 'live',
            'services.redsms.base_url' => 'https://cp.redsms.ru',
            'services.redsms.login' => 'test-fixture',
            'services.redsms.api_key' => 'test-only-key',
        ]);
        Http::preventStrayRequests();
        $responses = Http::sequence()
            ->push(['uuid' => 'phone-first', 'replacedFrom' => '+78001002030'])
            ->push(['uuid' => 'phone-second', 'replacedFrom' => '+78001002030']);
        Http::fake(['https://cp.redsms.ru/api/message' => function ($request) use ($responses) {
            return $this->providerResponder ? ($this->providerResponder)($request) : $responses($request);
        }]);
    }

    public function test_superseded_callback_does_not_replace_newer_verified_phone(): void
    {
        $user = $this->authenticateUser();
        $first = $this->start('+79990000002');
        $this->start('+79990000003');
        $this->sendCallback(['uuid' => 'phone-second', 'status' => 'wcall_delivered']);
        $confirmedAt = $user->fresh()->phone_verified_at->toISOString();

        $this->travel(1)->minutes();
        $this->sendCallback(['uuid' => 'phone-first', 'status' => 'wcall_delivered']);

        $this->assertSame('+79990000003', $user->fresh()->phone);
        $this->assertSame($confirmedAt, $user->fresh()->phone_verified_at->toISOString());
        $this->assertDatabaseHas('phone_verification_codes', [
            'verification_id' => $first, 'status' => 'pending', 'confirmed_at' => null, 'consumed_at' => null,
        ]);
    }

    public function test_same_phone_restart_supersedes_old_flow_even_if_new_flow_fails(): void
    {
        $user = $this->authenticateUser();
        $first = $this->start($user->phone);
        $this->travel(31)->seconds();
        $second = $this->start($user->phone);
        $this->sendCallback(['uuid' => 'phone-first', 'status' => 'wcall_delivered']);
        $this->assertNull($user->fresh()->phone_verified_at);
        $this->sendCallback(['uuid' => 'phone-second', 'status' => 'wcall_timeout']);
        $this->sendCallback(['uuid' => 'phone-first', 'status' => 'wcall_delivered']);

        $this->assertNull($user->fresh()->phone_verified_at);
        $this->assertDatabaseHas('phone_verification_codes', ['verification_id' => $first, 'status' => 'pending', 'confirmed_at' => null]);
        $this->assertDatabaseHas('phone_verification_codes', ['verification_id' => $second, 'status' => 'failed']);
    }

    public function test_expired_callback_cannot_create_fresh_confirmation(): void
    {
        $user = $this->authenticateUser();
        $verificationId = $this->start($user->phone);
        $this->travel(10)->minutes();
        $this->sendCallback(['uuid' => 'phone-first', 'status' => 'wcall_delivered']);

        $this->assertNull($user->fresh()->phone_verified_at);
        $this->assertDatabaseHas('phone_verification_codes', [
            'verification_id' => $verificationId, 'status' => 'pending', 'confirmed_at' => null, 'consumed_at' => null,
        ]);
    }

    public function test_current_flow_confirms_after_pending_with_same_provider_uuid_and_replays_do_not_refresh_it(): void
    {
        $user = $this->authenticateUser();
        $verificationId = $this->start($user->phone);
        $this->sendCallback(['uuid' => 'phone-first', 'status' => 'pending']);
        $this->assertNull($user->fresh()->phone_verified_at);
        $this->assertDatabaseHas('phone_verification_codes', [
            'verification_id' => $verificationId, 'status' => 'pending', 'consumed_at' => null, 'provider_event_id' => null,
        ]);

        $this->sendCallback(['uuid' => 'phone-first', 'status' => 'wcall_delivered']);
        $confirmed = DB::table('phone_verification_codes')->where('verification_id', $verificationId)->first();
        $confirmedAt = $user->fresh()->phone_verified_at->toISOString();
        $this->assertSame('confirmed', $confirmed->status);
        $this->assertNotNull($confirmed->consumed_at);
        $this->travel(1)->minutes();
        $this->sendCallback(['uuid' => 'phone-first', 'status' => 'wcall_delivered']);
        $this->sendCallback(['uuid' => 'phone-first', 'provider_event_id' => 'different-terminal-event', 'status' => 'wcall_delivered']);
        $this->sendCallback(['uuid' => 'phone-first', 'provider_event_id' => 'late-failure', 'status' => 'wcall_timeout']);

        $this->assertSame($confirmedAt, $user->fresh()->phone_verified_at->toISOString());
        $this->assertEquals($confirmed, DB::table('phone_verification_codes')->where('verification_id', $verificationId)->first());
    }

    public function test_callback_with_mismatched_provider_identity_is_ignored(): void
    {
        $user = $this->authenticateUser();
        $verificationId = $this->start($user->phone);
        $this->sendCallback(['verification_id' => $verificationId, 'uuid' => 'unrelated-provider-flow', 'status' => 'wcall_delivered']);
        $this->sendCallback(['verification_id' => $verificationId, 'uuid' => '0', 'status' => 'wcall_delivered']);
        $this->sendCallback(['verification_id' => (string) Str::uuid(), 'uuid' => 'phone-first', 'status' => 'wcall_delivered']);

        $this->assertNull($user->fresh()->phone_verified_at);
        $this->assertDatabaseHas('phone_verification_codes', ['verification_id' => $verificationId, 'status' => 'pending', 'provider_uuid' => 'phone-first']);
    }

    public function test_callback_for_phone_that_no_longer_matches_user_is_ignored(): void
    {
        $user = $this->authenticateUser();
        $this->start($user->phone);
        $user->update(['phone' => '+79990000004']);
        $this->sendCallback(['uuid' => 'phone-first', 'status' => 'wcall_delivered']);

        $this->assertSame('+79990000004', $user->fresh()->phone);
        $this->assertNull($user->fresh()->phone_verified_at);
    }

    public function test_direct_account_phone_change_invalidates_a_different_requested_target(): void
    {
        $user = $this->authenticateUser();
        $verificationId = $this->start('+79990000002');
        $before = DB::table('phone_verification_codes')->where('verification_id', $verificationId)->first();
        DB::table('users')->where('id', $user->id)->update(['phone' => '+79990000004']);

        $this->sendCallback(['uuid' => 'phone-first', 'status' => 'wcall_delivered']);

        $this->assertSame('+79990000004', $user->fresh()->phone);
        $this->assertNull($user->fresh()->phone_verified_at);
        $this->assertEquals($before, DB::table('phone_verification_codes')->where('verification_id', $verificationId)->first());
    }

    public function test_new_target_is_committed_only_after_confirmation_and_snapshot_stays_private(): void
    {
        $user = $this->authenticateUser();
        $response = $this->postJson('/api/v1/auth/phone-verification/start', ['phone' => '+79990000002'])
            ->assertAccepted()->assertJsonMissingPath('user_phone_at_start');
        $verificationId = $response->json('verification_id');
        $this->assertSame('+79990000001', $user->fresh()->phone);
        $this->assertNull($user->fresh()->phone_verified_at);
        $user->update(['name' => 'Updated test name']);

        $this->sendCallback(['uuid' => 'phone-first', 'status' => 'wcall_delivered']);

        $this->assertSame('+79990000002', $user->fresh()->phone);
        $this->assertNotNull($user->fresh()->phone_verified_at);
        $this->assertDatabaseHas('phone_verification_codes', [
            'verification_id' => $verificationId, 'user_phone_at_start' => '+79990000001', 'status' => 'confirmed',
        ]);
    }

    public function test_first_phone_can_be_confirmed_from_an_empty_account_phone(): void
    {
        $user = User::factory()->create(['phone' => null, 'phone_verified_at' => null]);
        Sanctum::actingAs($user);
        $verificationId = $this->start('+79990000002');
        $this->assertNull($user->fresh()->phone);

        $this->sendCallback(['uuid' => 'phone-first', 'status' => 'wcall_delivered']);

        $this->assertSame('+79990000002', $user->fresh()->phone);
        $this->assertNotNull($user->fresh()->phone_verified_at);
        $this->assertDatabaseHas('phone_verification_codes', [
            'verification_id' => $verificationId, 'user_phone_at_start' => '', 'status' => 'confirmed',
        ]);
    }

    public function test_legacy_flow_without_account_phone_snapshot_is_ignored(): void
    {
        $user = $this->authenticateUser();
        $verificationId = $this->start($user->phone);
        DB::table('phone_verification_codes')->where('verification_id', $verificationId)->update(['user_phone_at_start' => null]);
        $before = DB::table('phone_verification_codes')->where('verification_id', $verificationId)->first();

        $this->sendCallback(['uuid' => 'phone-first', 'status' => 'wcall_delivered']);

        $this->assertSame('+79990000001', $user->fresh()->phone);
        $this->assertNull($user->fresh()->phone_verified_at);
        $this->assertEquals($before, DB::table('phone_verification_codes')->where('verification_id', $verificationId)->first());
    }

    public function test_consumed_pending_callback_is_ignored(): void
    {
        $user = $this->authenticateUser();
        $verificationId = $this->start($user->phone);
        DB::table('phone_verification_codes')->where('verification_id', $verificationId)->update(['consumed_at' => now()]);
        $this->sendCallback(['uuid' => 'phone-first', 'status' => 'wcall_delivered']);

        $this->assertNull($user->fresh()->phone_verified_at);
        $this->assertDatabaseHas('phone_verification_codes', ['verification_id' => $verificationId, 'status' => 'pending', 'confirmed_at' => null]);
    }

    public function test_provider_start_completion_does_not_overwrite_callback_confirmation(): void
    {
        $user = $this->authenticateUser();
        $transactionLevel = DB::transactionLevel();
        $this->providerResponder = function ($request) use ($transactionLevel) {
            $this->assertSame($transactionLevel, DB::transactionLevel(), 'Provider call must run after releasing flow locks.');
            $this->sendCallback([
                'verification_id' => $request['ext_id'], 'uuid' => 'callback-first',
                'status' => 'wcall_delivered', 'replacedFrom' => '+78001002031',
            ]);

            return Http::response(['uuid' => 'callback-first', 'replacedFrom' => '+78001002030']);
        };

        $verificationId = $this->start($user->phone);
        $this->assertNotNull($user->fresh()->phone_verified_at);
        $this->assertDatabaseHas('phone_verification_codes', [
            'verification_id' => $verificationId, 'status' => 'confirmed', 'call_number' => '+78001002031',
        ]);
    }

    public function test_start_rechecks_current_verified_identity_instead_of_stale_authenticated_user(): void
    {
        $user = $this->authenticateUser();
        User::query()->whereKey($user->id)->update(['phone_verified_at' => now()]);

        $this->postJson('/api/v1/auth/phone-verification/start', ['phone' => $user->phone])
            ->assertConflict()->assertJsonPath('error.code', 'VERIFY_ALREADY_CONFIRMED');
        $this->assertNotNull($user->fresh()->phone_verified_at);
        $this->assertDatabaseMissing('phone_verification_codes', ['user_id' => $user->id]);
        Http::assertNothingSent();
    }

    public function test_provider_start_failure_does_not_overwrite_callback_confirmation(): void
    {
        $user = $this->authenticateUser();
        $this->providerResponder = function ($request) {
            $this->sendCallback(['verification_id' => $request['ext_id'], 'uuid' => 'callback-first', 'status' => 'wcall_delivered']);

            return Http::response([], 503);
        };

        $this->postJson('/api/v1/auth/phone-verification/start', ['phone' => $user->phone])
            ->assertStatus(503)->assertJsonPath('error.code', 'VERIFY_PROVIDER_FAILED');
        $this->assertNotNull($user->fresh()->phone_verified_at);
        $this->assertDatabaseHas('phone_verification_codes', ['user_id' => $user->id, 'status' => 'confirmed', 'provider_uuid' => 'callback-first']);
    }

    private function authenticateUser(): User
    {
        $user = User::factory()->create(['phone' => '+79990000001', 'phone_verified_at' => null]);
        Sanctum::actingAs($user);

        return $user;
    }

    private function start(string $phone): string
    {
        return $this->postJson('/api/v1/auth/phone-verification/start', ['phone' => $phone])
            ->assertAccepted()->json('verification_id');
    }

    private function sendCallback(array $payload): void
    {
        $this->postJson('/api/v1/auth/phone-verification/callback/redsms', $payload)
            ->assertOk()->assertJsonPath('status', 'accepted');
    }
}
