<?php

namespace Tests\Feature;

use App\Models\TrustedBrowserAccount;
use App\Models\TrustedBrowserChallenge;
use App\Models\TrustedBrowserCredential;
use App\Models\TrustedBrowserSessionLink;
use App\Models\TrustedBrowserSlot;
use App\Models\User;
use App\Services\SmtpBzApiClient;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Symfony\Component\HttpFoundation\Cookie as SymfonyCookie;
use Tests\TestCase;

class TrustedBrowserFeatureTest extends TestCase
{
    use RefreshDatabase;

    private const ORIGIN = 'https://trusted.test';

    private const CSRF_TOKEN = 'trusted-browser-csrf-token';

    private int $phoneSequence = 1;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.env' => 'testing',
            'sanctum.stateful' => ['trusted.test'],
            'services.smtpbz.enabled' => false,
            'trusted_browser.allowed_origins' => [self::ORIGIN],
            'trusted_browser.mode' => 'off',
        ]);
        Mail::fake();
    }

    public function test_schema_rejects_invalid_slot_status_and_duplicate_account(): void
    {
        $user = $this->verifiedUser();
        $account = TrustedBrowserAccount::query()->create([
            'user_id' => $user->id,
            'cookie_key' => bin2hex(random_bytes(16)),
        ]);

        foreach ([
            ['slot_number' => 0, 'status' => 'active'],
            ['slot_number' => 1, 'status' => 'invalid'],
        ] as $attributes) {
            try {
                DB::table('trusted_browser_slots')->insert(array_merge([
                    'account_id' => $account->id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ], $attributes));
                $this->fail('Invalid trusted browser slot was accepted.');
            } catch (QueryException) {
                $this->assertTrue(true);
            }
        }

        $this->expectException(QueryException::class);
        TrustedBrowserAccount::query()->create([
            'user_id' => $user->id,
            'cookie_key' => bin2hex(random_bytes(16)),
        ]);
    }

    public function test_three_credentials_are_occupied_and_fourth_browser_requires_support_without_mail(): void
    {
        $user = $this->verifiedUser();

        for ($slot = 1; $slot <= 3; $slot++) {
            $enrollment = $this->enroll($user, $this->newSessionId());
            $this->assertSame($slot, $enrollment['slot']);
            $this->travel(61)->seconds();
        }

        $this->assertSame(3, TrustedBrowserCredential::query()->count());
        $this->assertSame(3, TrustedBrowserSlot::query()->whereIn('status', TrustedBrowserSlot::OCCUPIED_STATUSES)->count());
        $challengeCount = TrustedBrowserChallenge::query()->count();
        $smtp = Mockery::mock(SmtpBzApiClient::class);
        $smtp->shouldNotReceive('sendHtmlMessage');
        $this->app->instance(SmtpBzApiClient::class, $smtp);
        Mail::spy();

        $this->authenticate($user, $this->newSessionId());
        $this->postJson('/api/v1/auth/device-trust/challenges', ['purpose' => 'enroll'])
            ->assertConflict()
            ->assertJsonPath('error.code', 'TRUSTED_BROWSER_SUPPORT_REQUIRED');

        $this->assertSame($challengeCount, TrustedBrowserChallenge::query()->count());
        Mail::shouldNotHaveReceived('raw');
    }

    public function test_account_scoped_cookies_support_two_accounts_and_strictly_reject_bad_or_foreign_values(): void
    {
        $firstUser = $this->verifiedUser(['email' => 'first@example.test']);
        $secondUser = $this->verifiedUser(['email' => 'second@example.test']);
        $first = $this->enroll($firstUser, $this->newSessionId());
        $second = $this->enroll($secondUser, $this->newSessionId());

        $this->assertNotSame($first['cookie_name'], $second['cookie_name']);
        $this->assertMatchesRegularExpression('/^cms-tb-v1-[a-f0-9]{32}$/', $first['cookie_name']);
        $this->assertMatchesRegularExpression('/^td_v1\.[A-Za-z0-9_-]{22}\.[A-Za-z0-9_-]{43}$/', $first['cookie_value']);
        $this->assertFalse($first['cookie']->isSecure());
        $this->assertTrue($first['cookie']->isHttpOnly());
        $this->assertSame('lax', $first['cookie']->getSameSite());
        $this->assertSame('/', $first['cookie']->getPath());
        $this->assertNull($first['cookie']->getDomain());

        $this->authenticate($secondUser, $second['session_id']);
        $this->withUnencryptedCookies([
            $first['cookie_name'] => $first['cookie_value'],
            $second['cookie_name'] => $second['cookie_value'],
        ]);
        $this->getJson('/api/v1/auth/device-trust/status')
            ->assertOk()
            ->assertJsonPath('state', 'trusted');

        $this->authenticate($firstUser, $this->newSessionId());
        $this->withUnencryptedCookie($first['cookie_name'], 'td_v1.short.invalid');
        $this->getJson('/api/v1/auth/device-trust/status')
            ->assertOk()
            ->assertJsonPath('state', 'unknown_can_confirm');

        [$version, $selector] = explode('.', $first['cookie_value'], 3);
        $this->withUnencryptedCookie($first['cookie_name'], $version.'.'.$selector.'.'.str_repeat('A', 43));
        $this->getJson('/api/v1/auth/device-trust/status')
            ->assertOk()
            ->assertJsonPath('state', 'unknown_can_confirm');

        $this->authenticate($secondUser, $this->newSessionId());
        $this->withUnencryptedCookie($second['cookie_name'], $first['cookie_value']);
        $this->getJson('/api/v1/auth/device-trust/status')
            ->assertOk()
            ->assertJsonPath('state', 'unknown_can_confirm');
    }

    public function test_otp_enforces_cooldown_expiry_attempt_limit_and_single_use(): void
    {
        $expiredUser = $this->verifiedUser(['email' => 'expired@example.test']);
        $this->authenticate($expiredUser, $this->newSessionId());
        $expired = $this->postJson('/api/v1/auth/device-trust/challenges', ['purpose' => 'enroll'])
            ->assertAccepted();
        $this->persistSessionCookie();
        $this->postJson('/api/v1/auth/device-trust/challenges', ['purpose' => 'enroll'])
            ->assertStatus(429)
            ->assertJsonPath('error.code', 'TRUSTED_BROWSER_RATE_LIMIT');
        $this->travel(11)->minutes();
        $this->postJson('/api/v1/auth/device-trust/challenges/'.$expired->json('challenge_id').'/verify', [
            'code' => $expired->json('debug_code'),
        ])->assertUnprocessable()->assertJsonPath('error.code', 'TRUSTED_BROWSER_CHALLENGE_INVALID');
        $this->assertDatabaseHas('trusted_browser_challenges', [
            'id' => $expired->json('challenge_id'),
            'status' => 'expired',
        ]);
        $this->travelBack();

        $attemptUser = $this->verifiedUser(['email' => 'attempts@example.test']);
        $this->authenticate($attemptUser, $this->newSessionId());
        $attempt = $this->postJson('/api/v1/auth/device-trust/challenges', ['purpose' => 'enroll'])
            ->assertAccepted();
        $this->persistSessionCookie();
        for ($number = 0; $number < 5; $number++) {
            $this->postJson('/api/v1/auth/device-trust/challenges/'.$attempt->json('challenge_id').'/verify', [
                'code' => '000000',
            ])->assertUnprocessable();
        }
        $this->assertDatabaseHas('trusted_browser_challenges', [
            'id' => $attempt->json('challenge_id'),
            'attempts_left' => 0,
            'status' => 'failed',
        ]);
        $this->postJson('/api/v1/auth/device-trust/challenges/'.$attempt->json('challenge_id').'/verify', [
            'code' => $attempt->json('debug_code'),
        ])->assertUnprocessable();

        $reuseUser = $this->verifiedUser(['email' => 'reuse@example.test']);
        $this->authenticate($reuseUser, $this->newSessionId());
        $reuse = $this->postJson('/api/v1/auth/device-trust/challenges', ['purpose' => 'enroll'])
            ->assertAccepted();
        $this->persistSessionCookie();
        $url = '/api/v1/auth/device-trust/challenges/'.$reuse->json('challenge_id').'/verify';
        $this->postJson($url, ['code' => $reuse->json('debug_code')])->assertOk();
        $this->postJson($url, ['code' => $reuse->json('debug_code')])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'TRUSTED_BROWSER_CHALLENGE_INVALID');
    }

    public function test_resend_supersedes_the_previous_pending_enroll_challenge(): void
    {
        $user = $this->verifiedUser(['email' => 'resend@example.test']);
        $this->authenticate($user, $this->newSessionId());
        $first = $this->postJson('/api/v1/auth/device-trust/challenges', ['purpose' => 'enroll'])
            ->assertAccepted();
        $this->persistSessionCookie();

        $this->travel(61)->seconds();
        $second = $this->postJson('/api/v1/auth/device-trust/challenges', ['purpose' => 'enroll'])
            ->assertAccepted();

        $this->assertDatabaseHas('trusted_browser_challenges', [
            'id' => $first->json('challenge_id'),
            'status' => 'failed',
        ]);
        $this->postJson('/api/v1/auth/device-trust/challenges/'.$first->json('challenge_id').'/verify', [
            'code' => $first->json('debug_code'),
        ])->assertUnprocessable()->assertJsonPath('error.code', 'TRUSTED_BROWSER_CHALLENGE_INVALID');
        $this->postJson('/api/v1/auth/device-trust/challenges/'.$second->json('challenge_id').'/verify', [
            'code' => $second->json('debug_code'),
        ])->assertOk()->assertJsonPath('state', 'trusted');

        $this->assertSame(1, TrustedBrowserSlot::query()->where('status', 'active')->count());
        $this->assertSame(1, TrustedBrowserCredential::query()->count());
        $this->travelBack();
    }

    public function test_successful_enroll_invalidates_parallel_pending_challenges_for_the_session(): void
    {
        $user = $this->verifiedUser(['email' => 'parallel-enroll@example.test']);
        $this->authenticate($user, $this->newSessionId());
        $primary = $this->postJson('/api/v1/auth/device-trust/challenges', ['purpose' => 'enroll'])
            ->assertAccepted();
        $this->persistSessionCookie();

        $primaryChallenge = TrustedBrowserChallenge::query()->findOrFail($primary->json('challenge_id'));
        $parallelChallenge = $primaryChallenge->replicate();
        $parallelChallenge->id = (string) Str::uuid();
        $parallelChallenge->save();

        $this->postJson('/api/v1/auth/device-trust/challenges/'.$primary->json('challenge_id').'/verify', [
            'code' => $primary->json('debug_code'),
        ])->assertOk()->assertJsonPath('state', 'trusted');

        $this->assertDatabaseHas('trusted_browser_challenges', [
            'id' => $parallelChallenge->id,
            'status' => 'failed',
        ]);
        $this->postJson('/api/v1/auth/device-trust/challenges/'.$parallelChallenge->id.'/verify', [
            'code' => $primary->json('debug_code'),
        ])->assertUnprocessable()->assertJsonPath('error.code', 'TRUSTED_BROWSER_CHALLENGE_INVALID');
        $this->assertSame(1, TrustedBrowserSlot::query()->where('status', 'active')->count());
        $this->assertSame(1, TrustedBrowserCredential::query()->count());
    }

    public function test_login_regeneration_rebinds_existing_cookie_without_allocating_or_challenging(): void
    {
        $user = $this->verifiedUser([
            'email' => 'login-rebind@example.test',
            'password' => 'rebind-password-123',
        ]);
        $enrollment = $this->enroll($user, $this->newSessionId());
        $credentialCount = TrustedBrowserCredential::query()->count();
        $challengeCount = TrustedBrowserChallenge::query()->count();
        $preLoginSession = $this->newSessionId();

        $this->authenticate($user, $preLoginSession);
        $this->withUnencryptedCookie($enrollment['cookie_name'], $enrollment['cookie_value']);
        $this->postJson('/api/v1/auth/login', [
            'login' => $user->email,
            'password' => 'rebind-password-123',
        ])->assertOk();

        $currentSessionId = $this->app['session']->driver()->getId();
        $this->assertNotSame($preLoginSession, $currentSessionId);
        $this->assertDatabaseHas('trusted_browser_session_links', [
            'session_id' => $currentSessionId,
            'credential_id' => $enrollment['credential_id'],
            'revoked_at' => null,
        ]);
        $this->assertSame($credentialCount, TrustedBrowserCredential::query()->count());
        $this->assertSame($challengeCount, TrustedBrowserChallenge::query()->count());
    }

    public function test_revoke_keeps_slot_occupied_invalidates_credential_links_and_database_sessions(): void
    {
        $user = $this->verifiedUser(['email' => 'revoke@example.test']);
        $enrollment = $this->enroll($user, $this->newSessionId());
        $fakeSessionId = 'linked-session-'.Str::random(30);
        DB::table('sessions')->insert([
            ['id' => $enrollment['session_id'], 'user_id' => $user->id, 'ip_address' => null, 'user_agent' => null, 'payload' => '', 'last_activity' => now()->timestamp],
            ['id' => $fakeSessionId, 'user_id' => $user->id, 'ip_address' => null, 'user_agent' => null, 'payload' => '', 'last_activity' => now()->timestamp],
        ]);
        TrustedBrowserSessionLink::query()->create([
            'account_id' => $enrollment['account_id'],
            'slot_id' => $enrollment['slot_id'],
            'credential_id' => $enrollment['credential_id'],
            'session_id' => $fakeSessionId,
            'bound_at' => now(),
            'last_seen_at' => now(),
        ]);

        $this->authenticate($user, $enrollment['session_id']);
        $this->withUnencryptedCookie($enrollment['cookie_name'], $enrollment['cookie_value']);
        $response = $this->postJson('/api/v1/auth/trusted-browsers/1/revoke')
            ->assertOk()
            ->assertJsonPath('slot_status', 'revoked_locked');
        $response->assertCookieExpired($enrollment['cookie_name']);

        $this->assertDatabaseHas('trusted_browser_slots', [
            'id' => $enrollment['slot_id'],
            'status' => 'revoked_locked',
        ]);
        $this->assertDatabaseMissing('sessions', ['id' => $enrollment['session_id']]);
        $this->assertDatabaseMissing('sessions', ['id' => $fakeSessionId]);
        $this->assertSame(0, TrustedBrowserSessionLink::query()
            ->where('slot_id', $enrollment['slot_id'])
            ->whereNull('revoked_at')
            ->count());
        $this->assertNotNull(TrustedBrowserCredential::query()->findOrFail($enrollment['credential_id'])->invalidated_at);
        $this->assertSame(1, TrustedBrowserSlot::query()->whereIn('status', TrustedBrowserSlot::OCCUPIED_STATUSES)->count());

        $this->authenticate($user, $this->newSessionId());
        $this->withUnencryptedCookie($enrollment['cookie_name'], $enrollment['cookie_value']);
        $this->getJson('/api/v1/auth/device-trust/status')
            ->assertOk()
            ->assertJsonPath('state', 'revoked_current');
    }

    public function test_unknown_browser_revoke_uses_slot_number_contract_and_revokes_requested_slot(): void
    {
        $user = $this->verifiedUser([
            'email' => 'unknown-revoke@example.test',
            'password' => 'unknown-revoke-password',
        ]);
        $firstEnrollment = $this->enroll($user, $this->newSessionId());
        $this->travel(61)->seconds();
        $secondEnrollment = $this->enroll($user, $this->newSessionId());
        $linkedSessionId = 'linked-session-'.Str::random(30);
        DB::table('sessions')->insert([
            ['id' => $secondEnrollment['session_id'], 'user_id' => $user->id, 'ip_address' => null, 'user_agent' => null, 'payload' => '', 'last_activity' => now()->timestamp],
            ['id' => $linkedSessionId, 'user_id' => $user->id, 'ip_address' => null, 'user_agent' => null, 'payload' => '', 'last_activity' => now()->timestamp],
        ]);
        TrustedBrowserSessionLink::query()->create([
            'account_id' => $secondEnrollment['account_id'],
            'slot_id' => $secondEnrollment['slot_id'],
            'credential_id' => $secondEnrollment['credential_id'],
            'session_id' => $linkedSessionId,
            'bound_at' => now(),
            'last_seen_at' => now(),
        ]);

        $this->authenticate($user, $this->newSessionId());
        $challengeCount = TrustedBrowserChallenge::query()->count();
        $this->postJson('/api/v1/auth/device-trust/challenges', [
            'purpose' => 'revoke',
            'slot' => 2,
            'password' => 'unknown-revoke-password',
        ])->assertStatus(422)->assertJsonValidationErrors(['slot_number']);
        $this->assertSame($challengeCount, TrustedBrowserChallenge::query()->count());

        $challenge = $this->postJson('/api/v1/auth/device-trust/challenges', [
            'purpose' => 'revoke',
            'slot_number' => 2,
            'password' => 'unknown-revoke-password',
        ])->assertAccepted();
        $this->persistSessionCookie();

        $this->postJson('/api/v1/auth/device-trust/challenges/'.$challenge->json('challenge_id').'/verify', [
            'code' => $challenge->json('debug_code'),
        ])->assertOk()
            ->assertJsonPath('purpose', 'revoke')
            ->assertJsonPath('slot', 2)
            ->assertJsonPath('slot_status', 'revoked_locked');

        $this->assertDatabaseHas('trusted_browser_slots', [
            'id' => $firstEnrollment['slot_id'],
            'status' => 'active',
        ]);
        $this->assertDatabaseHas('trusted_browser_slots', [
            'id' => $secondEnrollment['slot_id'],
            'status' => 'revoked_locked',
        ]);
        $this->assertDatabaseMissing('sessions', ['id' => $secondEnrollment['session_id']]);
        $this->assertDatabaseMissing('sessions', ['id' => $linkedSessionId]);
        $this->assertSame(0, TrustedBrowserSessionLink::query()
            ->where('slot_id', $secondEnrollment['slot_id'])
            ->whereNull('revoked_at')
            ->count());
        $this->assertNotNull(TrustedBrowserCredential::query()
            ->whereKey($secondEnrollment['credential_id'])
            ->whereNotNull('invalidated_at')
            ->first());
        $this->assertSame(2, TrustedBrowserSlot::query()->whereIn('status', TrustedBrowserSlot::OCCUPIED_STATUSES)->count());
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function verifiedUser(array $attributes = []): User
    {
        return User::factory()->create(array_merge([
            'email_verified_at' => now(),
            'phone' => sprintf('+7999%07d', $this->phoneSequence++),
            'phone_verified_at' => now(),
        ], $attributes));
    }

    private function authenticate(User $user, string $sessionId, bool $withCsrf = true): void
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($user);
        $this->actingAs($user, 'web');
        $session = $this->app['session']->driver();
        $session->setId($sessionId);
        $session->start();
        $session->put('_token', self::CSRF_TOKEN);
        $session->save();
        $this->withCredentials();
        $this->withCookie((string) config('session.cookie'), $sessionId);
        $headers = ['Origin' => self::ORIGIN];
        if ($withCsrf) {
            $headers['X-CSRF-TOKEN'] = self::CSRF_TOKEN;
        }
        $this->withHeaders($headers);
    }

    private function newSessionId(): string
    {
        return Str::random(40);
    }

    private function persistSessionCookie(): string
    {
        $session = $this->app['session']->driver();
        $sessionId = $session->getId();
        $session->save();
        $this->withCookie((string) config('session.cookie'), $sessionId);

        return $sessionId;
    }

    /**
     * @return array{
     *     slot: int,
     *     account_id: int,
     *     slot_id: int,
     *     credential_id: int,
     *     session_id: string,
     *     cookie_name: string,
     *     cookie_value: string,
     *     cookie: SymfonyCookie
     * }
     */
    private function enroll(User $user, string $sessionId): array
    {
        $this->authenticate($user, $sessionId);
        $challenge = $this->postJson('/api/v1/auth/device-trust/challenges', [
            'purpose' => 'enroll',
            'browser_label' => 'Test Browser',
            'os_label' => 'Test OS',
            'device_label' => 'Test Device',
        ]);
        $challenge->assertAccepted();
        $sessionId = $this->persistSessionCookie();
        $response = $this->postJson('/api/v1/auth/device-trust/challenges/'.$challenge->json('challenge_id').'/verify', [
            'code' => $challenge->json('debug_code'),
        ]);
        $response->assertOk()->assertJsonPath('state', 'trusted');
        $account = TrustedBrowserAccount::query()->where('user_id', $user->id)->firstOrFail();
        $slot = TrustedBrowserSlot::query()
            ->where('account_id', $account->id)
            ->where('slot_number', $response->json('slot'))
            ->firstOrFail();
        $credential = TrustedBrowserCredential::query()
            ->where('slot_id', $slot->id)
            ->where('generation', $slot->current_generation)
            ->firstOrFail();
        $cookieName = 'cms-tb-v1-'.$account->cookie_key;
        $cookie = $response->getCookie($cookieName, false);
        $this->assertInstanceOf(SymfonyCookie::class, $cookie);

        return [
            'slot' => $slot->slot_number,
            'account_id' => $account->id,
            'slot_id' => $slot->id,
            'credential_id' => $credential->id,
            'session_id' => $sessionId,
            'cookie_name' => $cookieName,
            'cookie_value' => $cookie->getValue(),
            'cookie' => $cookie,
        ];
    }
}
