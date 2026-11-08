<?php

namespace Tests\Feature;

use App\Jobs\SendPreRegistrationEmailCodeJob;
use App\Models\User;
use App\Services\LegacyUsers\LegacyUserActivationService;
use App\Services\RedsmsApiClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\LegacyUserBundleFixtures;
use Tests\TestCase;

class LegacyUserActivationTest extends TestCase
{
    use RefreshDatabase;

    private LegacyUserBundleFixtures $fixtures;

    private User $student;

    private string $placeholder;

    private const BASE = '/api/v1/auth/legacy-activation/';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Queue::fake();
        Mail::fake();
        Notification::fake();
        config(['services.redsms.mode' => 'mock']);
        $this->fixtures = new LegacyUserBundleFixtures;
        $this->fixtures->import($this->fixtures->bundle(LegacyUserBundleFixtures::records()));
        $this->student = User::query()->where('email', 'synthetic13@example.test')->sole();
        $this->placeholder = $this->student->password;
    }

    protected function tearDown(): void
    {
        $this->fixtures->cleanup();
        parent::tearDown();
    }

    public function test_email_password_phone_finish_order_and_no_early_auth_or_entitlements(): void
    {
        $history = $this->history();
        [$credential, $code] = $this->start();
        $this->postJson(self::BASE.'password', $credential + ['password' => 'new-password-123'])->assertConflict()->assertJsonPath('error.code', 'LEGACY_ACTIVATION_STEP_REQUIRED');
        $this->postJson(self::BASE.'phone/start', $credential + ['phone' => '+79990000123'])->assertConflict();
        $this->postJson(self::BASE.'complete', $credential)->assertConflict();
        $this->postJson('/api/v1/auth/login', ['login' => $this->student->email, 'password' => 'old-password'])->assertForbidden()->assertJsonPath('error.code', 'AUTH_ACCOUNT_MIGRATION_HELD');
        $this->withToken($credential['capability'])->getJson('/api/v1/auth/me')->assertUnauthorized();
        $this->withoutHeader('Authorization');
        $this->app['auth']->forgetGuards();
        $this->postJson(self::BASE.'email/verify', $credential + ['code' => $code])->assertOk()->assertJsonPath('step', 'password');
        $this->postJson(self::BASE.'phone/start', $credential + ['phone' => '+79990000123'])->assertConflict();
        $this->postJson(self::BASE.'password', $credential + ['password' => 'new-password-123'])->assertOk()->assertJsonPath('step', 'phone');
        $this->assertDormant();
        $this->assertTrue(Hash::check('new-password-123', DB::table('legacy_user_activations')->value('password_hash')));
        $this->postJson(self::BASE.'phone/start', $credential + ['phone' => '+79990000123'])->assertAccepted()->assertJsonPath('step', 'complete');
        $this->assertDormant();
        $this->postJson(self::BASE.'phone/start', $credential + ['phone' => '+79990000123'])->assertConflict();
        $this->postJson(self::BASE.'status', $credential)->assertOk()->assertJsonPath('step', 'complete');
        $this->postJson(self::BASE.'complete', $credential)->assertOk()->assertJsonPath('authenticated', true)->assertJsonMissingPath('user_id');
        $this->assertAuthenticatedAs($this->student);
        $this->student->refresh();
        $this->assertFalse($this->student->blocksAuthenticationForMigration());
        $this->assertSame('set', $this->student->password_state);
        $this->assertTrue(Hash::check('new-password-123', $this->student->password));
        $this->assertSame('+79990000123', $this->student->phone);
        $this->assertNotNull($this->student->email_verified_at);
        $this->assertNotNull($this->student->phone_verified_at);
        $this->assertNull(DB::table('legacy_user_activations')->value('password_hash'));
        $this->assertDatabaseCount('user_entitlement_grants', 0);
        $this->assertSame($history, $this->history());
        Queue::assertPushed(SendPreRegistrationEmailCodeJob::class, 1);
        Queue::assertCount(1);
        Notification::assertNothingSent();
        Http::assertNothingSent();
        Auth::guard('web')->logout();
        $this->flushSession();
        $this->app['auth']->forgetGuards();
        $completedAt = DB::table('legacy_user_activations')->value('completed_at');
        $this->postJson(self::BASE.'complete', $credential)->assertForbidden();
        $this->assertGuest();
        $this->assertSame($completedAt, DB::table('legacy_user_activations')->value('completed_at'));
        $this->postJson('/api/v1/auth/login', ['login' => $this->student->email, 'password' => 'new-password-123'])->assertOk();
    }

    public function test_email_attempts_are_committed_and_expired_or_replayed_codes_are_denied(): void
    {
        [$credential, $code] = $this->start();
        for ($i = 0; $i < 5; $i++) {
            $this->postJson(self::BASE.'email/verify', $credential + ['code' => '000000'])->assertUnprocessable();
        }
        $this->assertSame(0, DB::table('legacy_user_activations')->value('attempts_left'));
        $this->postJson(self::BASE.'email/verify', $credential + ['code' => $code])->assertUnprocessable();
        $this->assertDormant();
        $this->travel(61)->seconds();
        [$new, $newCode] = $this->start();
        $this->postJson(self::BASE.'status', $credential)->assertForbidden();
        $this->postJson(self::BASE.'email/verify', $new + ['code' => $newCode])->assertOk();
        $this->postJson(self::BASE.'email/verify', $new + ['code' => $newCode])->assertConflict();
        $this->travel(31)->minutes();
        $this->postJson(self::BASE.'status', $new)->assertUnauthorized()->assertJsonPath('error.code', 'LEGACY_ACTIVATION_EXPIRED');
    }

    public function test_email_code_ttl_resend_cooldown_and_start_window_limit(): void
    {
        [$credential, $code] = $this->start();
        $this->postJson(self::BASE.'email/start', ['email' => $this->student->email])->assertTooManyRequests();
        $this->travel(11)->minutes();
        $this->postJson(self::BASE.'email/verify', $credential + ['code' => $code])->assertUnprocessable();
        $this->start();
        $this->travel(61)->seconds();
        $this->start();
        $this->travel(61)->seconds();
        $this->start();
        $this->travel(61)->seconds();
        $this->postJson(self::BASE.'email/start', ['email' => $this->student->email])->assertTooManyRequests();
        $this->assertDormant();
    }

    public static function disallowedBanEvidence(): array
    {
        return [[1], ['1'], [null], ['false'], [-1]];
    }

    #[DataProvider('disallowedBanEvidence')]
    public function test_only_explicit_unbanned_source_can_start(mixed $value): void
    {
        $this->changePayload(['isBanned' => $value]);
        $this->postJson(self::BASE.'email/start', ['email' => $this->student->email])->assertForbidden();
        $this->assertDormant();
        $this->assertDatabaseCount('legacy_user_activations', 0);
        Queue::assertNothingPushed();
    }

    public function test_foreign_capability_source_email_fingerprint_and_staff_drift_are_denied(): void
    {
        [$credential, $code] = $this->start();
        $foreign = $credential;
        $foreign['capability'] = str_repeat('x', 64);
        $this->postJson(self::BASE.'email/verify', $foreign + ['code' => $code])->assertUnauthorized();
        $this->assertSame(5, DB::table('legacy_user_activations')->value('attempts_left'));
        DB::table('legacy_user_profiles')->update(['source_fingerprint' => str_repeat('b', 64)]);
        $this->postJson(self::BASE.'email/verify', $credential + ['code' => $code])->assertForbidden();
        $this->travel(61)->seconds();
        [$credential, $code] = $this->start();
        $this->changePayload(['email' => 'someone-else@example.test']);
        $this->postJson(self::BASE.'email/verify', $credential + ['code' => $code])->assertForbidden();
        $this->assertDormant();
        $this->changePayload(['email' => $this->student->email]);
        DB::table('users')->where('id', $this->student->id)->update(['role' => 'admin_full']);
        $this->postJson(self::BASE.'status', $credential)->assertForbidden();
    }

    public function test_conflicting_phone_and_missing_identity_never_advance(): void
    {
        $credential = $this->passwordReady();
        User::factory()->create(['email' => 'other@example.test', 'phone' => '+79990000123']);
        $this->postJson(self::BASE.'phone/start', $credential + ['phone' => '+79990000123'])->assertConflict()->assertJsonPath('error.code', 'AUTH_PHONE_EXISTS');
        $this->assertDatabaseCount('legacy_activation_phone_attempts', 0);
        DB::table('legacy_user_profiles')->update(['mapping_status' => 'historical_only']);
        $this->postJson(self::BASE.'status', $credential)->assertForbidden();
        $this->assertDormant();
    }

    public function test_callback_requires_current_bound_provider_and_terminal_replay_is_inert(): void
    {
        $credential = $this->passwordReady();
        $this->liveProvider('provider-one');
        $start = $this->postJson(self::BASE.'phone/start', $credential + ['phone' => '+79990000123'])->assertAccepted()->assertJsonPath('step', 'phone')->json();
        $callback = ['verification_id' => $start['verification_id'], 'provider_uuid' => 'provider-one', 'status' => 'delivered'];
        $this->postJson(self::BASE.'phone/callback/redsms', array_replace($callback, ['provider_uuid' => 'foreign']))->assertOk();
        $this->postJson(self::BASE.'phone/callback/redsms', $callback + ['uuid' => 'foreign'])->assertOk();
        $this->postJson(self::BASE.'phone/callback/redsms', $callback + ['phone' => '+79990000456'])->assertOk();
        $this->assertNull(DB::table('legacy_activation_phone_attempts')->value('confirmed_at'));
        $this->postJson(self::BASE.'phone/callback/redsms', $callback)->assertOk();
        $confirmed = DB::table('legacy_activation_phone_attempts')->value('confirmed_at');
        $this->travel(2)->seconds();
        $this->postJson(self::BASE.'phone/callback/redsms', $callback)->assertOk();
        $this->assertSame($confirmed, DB::table('legacy_activation_phone_attempts')->value('confirmed_at'));
        $this->postJson(self::BASE.'status', $credential)->assertOk()->assertJsonPath('step', 'complete');
        $this->assertDormant();
    }

    public function test_expired_superseded_and_banned_callbacks_cannot_confirm(): void
    {
        $credential = $this->passwordReady();
        $this->liveProvider('provider-one', 'provider-two');
        $first = $this->postJson(self::BASE.'phone/start', $credential + ['phone' => '+79990000123'])->assertAccepted()->json();
        $this->travel(11)->minutes();
        $this->postJson(self::BASE.'phone/callback/redsms', ['verification_id' => $first['verification_id'], 'provider_uuid' => 'provider-one', 'status' => 'delivered'])->assertOk();
        $this->assertNull(DB::table('legacy_activation_phone_attempts')->value('confirmed_at'));
        $second = $this->postJson(self::BASE.'phone/start', $credential + ['phone' => '+79990000456'])->assertAccepted()->json();
        $this->postJson(self::BASE.'phone/callback/redsms', ['verification_id' => $first['verification_id'], 'provider_uuid' => 'provider-one', 'status' => 'delivered'])->assertOk();
        $this->changePayload(['isBanned' => 1]);
        $this->postJson(self::BASE.'phone/callback/redsms', ['verification_id' => $second['verification_id'], 'provider_uuid' => 'provider-two', 'status' => 'delivered'])->assertOk();
        $this->assertSame(0, DB::table('legacy_activation_phone_attempts')->whereNotNull('confirmed_at')->count());
        $this->postJson(self::BASE.'complete', $credential)->assertForbidden();
        $this->assertDormant();
    }

    public function test_callback_before_provider_binding_is_ignored_and_network_runs_outside_locks(): void
    {
        $credential = $this->passwordReady();
        config(['services.redsms.mode' => 'live']);
        $fixtureTransactionLevel = DB::transactionLevel();
        $mock = Mockery::mock(RedsmsApiClient::class);
        $mock->shouldReceive('startWaitCall')->once()->andReturnUsing(function ($phone, $id) use ($fixtureTransactionLevel): array {
            $this->assertSame($fixtureTransactionLevel, DB::transactionLevel());
            app(LegacyUserActivationService::class)->callback(['verification_id' => $id, 'provider_uuid' => 'early-provider', 'status' => 'delivered']);
            $this->assertNull(DB::table('legacy_activation_phone_attempts')->value('confirmed_at'));

            return ['provider_uuid' => 'early-provider', 'call_number' => null, 'raw' => []];
        });
        $this->app->instance(RedsmsApiClient::class, $mock);
        $this->postJson(self::BASE.'phone/start', $credential + ['phone' => '+79990000123'])->assertAccepted()->assertJsonPath('step', 'phone');
        $this->assertDormant();
    }

    public function test_production_callback_source_guard_and_phone_conflict_at_finish(): void
    {
        $credential = $this->passwordReady();
        $start = $this->postJson(self::BASE.'phone/start', $credential + ['phone' => '+79990000123'])->assertAccepted()->json();
        config(['app.env' => 'production', 'services.redsms.webhook_allowed_ips' => []]);
        $this->postJson(self::BASE.'phone/callback/redsms', ['verification_id' => $start['verification_id'], 'provider_uuid' => 'unknown', 'status' => 'delivered'])->assertForbidden();
        config(['app.env' => 'testing']);
        User::factory()->create(['email' => 'race-winner@example.test', 'phone' => '+79990000123']);
        $this->postJson(self::BASE.'complete', $credential)->assertConflict();
        $this->postJson(self::BASE.'status', $credential)->assertOk()->assertJsonPath('step', 'phone');
        $this->assertDormant();
    }

    public function test_bound_pending_callback_can_supply_delayed_call_number_without_authentication(): void
    {
        $credential = $this->passwordReady();
        config(['services.redsms.mode' => 'api']);
        $client = Mockery::mock(RedsmsApiClient::class);
        $client->shouldReceive('startWaitCall')->once()->andReturn(['provider_uuid' => 'delayed-provider', 'call_number' => null, 'raw' => []]);
        $this->app->instance(RedsmsApiClient::class, $client);
        $start = $this->postJson(self::BASE.'phone/start', $credential + ['phone' => '+79990000123'])->assertAccepted()->assertJsonPath('call_number', null)->json();
        $callback = ['verification_id' => $start['verification_id'], 'provider_uuid' => 'delayed-provider', 'status' => 'pending', 'replacedFrom' => '+79990000999'];
        $this->postJson(self::BASE.'phone/callback/redsms', array_replace($callback, ['provider_uuid' => 'foreign']))->assertOk();
        $this->postJson(self::BASE.'status', $credential)->assertOk()->assertJsonPath('call_number', null)->assertJsonPath('phone', '+79990000123');
        $this->postJson(self::BASE.'phone/callback/redsms', $callback)->assertOk();
        $this->postJson(self::BASE.'status', $credential)->assertOk()->assertJsonPath('call_number', '+79990000999')->assertJsonPath('phone_status', 'pending')->assertJsonPath('step', 'phone');
        $this->assertDormant();
        $this->postJson(self::BASE.'phone/callback/redsms', array_replace($callback, ['replacedFrom' => 'javascript:bad']))->assertUnprocessable();
        $this->postJson(self::BASE.'status', $credential)->assertOk()->assertJsonPath('call_number', '+79990000999');
        $this->postJson(self::BASE.'phone/callback/redsms', array_replace($callback, ['status' => 'delivered']))->assertOk();
        $this->postJson(self::BASE.'status', $credential)->assertOk()->assertJsonPath('step', 'complete');
        $this->assertDormant();
    }

    private function start(): array
    {
        $response = $this->postJson(self::BASE.'email/start', ['email' => $this->student->email])->assertAccepted()->assertJsonPath('step', 'email');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertGuest();

        return [array_intersect_key($response->json(), array_flip(['activation_id', 'capability'])), $response->json('debug_code')];
    }

    private function passwordReady(): array
    {
        [$credential, $code] = $this->start();
        $this->postJson(self::BASE.'email/verify', $credential + ['code' => $code])->assertOk();
        $this->postJson(self::BASE.'password', $credential + ['password' => 'new-password-123'])->assertOk();

        return $credential;
    }

    private function assertDormant(): void
    {
        $this->student->refresh();
        $this->assertTrue($this->student->blocksAuthenticationForMigration());
        $this->assertSame($this->placeholder, $this->student->password);
        $this->assertNull($this->student->email_verified_at);
        $this->assertNull($this->student->phone_verified_at);
    }

    private function changePayload(array $values): void
    {
        $profile = DB::table('legacy_user_profiles')->first();
        DB::table('legacy_user_profiles')->where('id', $profile->id)->update(['payload' => json_encode(array_replace(json_decode($profile->payload, true), $values), JSON_THROW_ON_ERROR)]);
    }

    private function liveProvider(string $uuid, ?string $next = null): void
    {
        config(['services.redsms.mode' => 'live']);
        $mock = Mockery::mock(RedsmsApiClient::class);
        $results = array_map(fn ($value): array => ['provider_uuid' => $value, 'call_number' => '+78005553535', 'raw' => []], array_filter([$uuid, $next]));
        $mock->shouldReceive('startWaitCall')->times(count($results))->andReturn(...$results);
        $this->app->instance(RedsmsApiClient::class, $mock);
    }

    private function history(): array
    {
        $tables = ['legacy_user_profiles', 'legacy_user_orders', 'legacy_user_order_items', 'legacy_user_accesses', 'legacy_user_records', 'legacy_user_record_versions'];

        return array_map(fn ($table): string => json_encode(DB::table($table)->orderBy('id')->get()->all(), JSON_THROW_ON_ERROR), $tables);
    }
}
