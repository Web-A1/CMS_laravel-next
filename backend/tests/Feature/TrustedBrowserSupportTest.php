<?php

namespace Tests\Feature;

use App\Models\TrustedBrowserAccount;
use App\Models\TrustedBrowserChallenge;
use App\Models\TrustedBrowserCredential;
use App\Models\TrustedBrowserSlot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TrustedBrowserSupportTest extends TestCase
{
    use RefreshDatabase;

    private const ORIGIN = 'https://trusted.test';

    private const CSRF_TOKEN = 'trusted-browser-support-csrf';

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

    public function test_only_admin_full_can_release_with_a_distinct_authenticated_operator_and_idempotency(): void
    {
        $target = $this->verifiedUser(['email' => 'support-target@example.test']);
        $limited = $this->verifiedUser(['email' => 'limited@example.test', 'role' => 'admin_limited']);
        $firstOperator = $this->verifiedUser(['email' => 'operator-one@example.test', 'role' => 'admin_full']);
        $secondOperator = $this->verifiedUser(['email' => 'operator-two@example.test', 'role' => 'admin_full']);
        $enrollment = $this->enroll($target);
        $payload = [
            'target_user_id' => $target->id,
            'slot' => 1,
            'case_id' => 'CASE-1001',
            'reason' => 'Owner identity confirmed by support.',
        ];

        $this->authenticate($limited, $this->newSessionId());
        $this->withHeader('Idempotency-Key', 'limited-release-attempt');
        $this->postJson('/api/v1/admin/trusted-browsers/release-challenges', $payload)
            ->assertForbidden();
        $this->assertSame(0, TrustedBrowserChallenge::query()->where('purpose', 'support_release')->count());

        $this->authenticate($firstOperator, $this->newSessionId());
        $this->withHeader('Idempotency-Key', 'support-release-1001');
        $started = $this->postJson('/api/v1/admin/trusted-browsers/release-challenges', $payload)
            ->assertAccepted();
        $firstOperatorSession = $this->persistSessionCookie();
        $challengeId = (string) $started->json('challenge_id');
        $code = (string) $started->json('debug_code');

        $this->postJson('/api/v1/admin/trusted-browsers/release-challenges', $payload)
            ->assertAccepted()
            ->assertJsonPath('challenge_id', $challengeId);
        $this->assertSame(1, TrustedBrowserChallenge::query()->where('purpose', 'support_release')->count());

        $this->postJson("/api/v1/admin/trusted-browsers/release-challenges/{$challengeId}/approve", [
            'approved_by_user_id' => $secondOperator->id,
        ])->assertForbidden()->assertJsonPath('error.code', 'TRUSTED_BROWSER_SECOND_OPERATOR_REQUIRED');

        $this->authenticate($secondOperator, $this->newSessionId());
        $this->postJson("/api/v1/admin/trusted-browsers/release-challenges/{$challengeId}/approve", [
            'approved_by_user_id' => $firstOperator->id,
        ])->assertOk()->assertJsonPath('status', 'approved');

        $this->authenticate($firstOperator, $firstOperatorSession);
        $this->withHeader('Idempotency-Key', 'support-release-1001');
        $released = $this->postJson("/api/v1/admin/trusted-browsers/release-challenges/{$challengeId}/verify", [
            'code' => $code,
        ])->assertOk()
            ->assertJsonPath('status', 'released')
            ->assertJsonPath('slot_status', 'released');

        $this->postJson("/api/v1/admin/trusted-browsers/release-challenges/{$challengeId}/verify", [
            'code' => $code,
        ])->assertOk()->assertExactJson($released->json());
        $this->postJson('/api/v1/admin/trusted-browsers/release-challenges', $payload)
            ->assertOk()
            ->assertExactJson($released->json());

        $challenge = TrustedBrowserChallenge::query()->findOrFail($challengeId);
        $this->assertSame($firstOperator->id, $challenge->initiated_by_user_id);
        $this->assertSame($secondOperator->id, $challenge->approved_by_user_id);
        $this->assertDatabaseHas('trusted_browser_slots', [
            'id' => $enrollment['slot_id'],
            'status' => 'released',
            'current_generation' => 1,
        ]);
        $this->assertSame(1, TrustedBrowserCredential::query()->where('slot_id', $enrollment['slot_id'])->count());

        $this->travel(61)->seconds();
        $replacement = $this->enroll($target);
        $this->assertSame(1, $replacement['slot']);
        $this->assertSame($enrollment['slot_id'], $replacement['slot_id']);
        $this->assertSame(2, $replacement['generation']);
        $this->assertSame(2, TrustedBrowserCredential::query()->where('slot_id', $enrollment['slot_id'])->count());
    }

    public function test_phone_proof_must_follow_the_bound_challenge_and_cannot_be_reused(): void
    {
        $target = $this->verifiedUser(['email' => 'phone-proof-target@example.test']);
        $operator = $this->verifiedUser(['email' => 'phone-proof-operator@example.test', 'role' => 'admin_full']);
        $this->enroll($target);

        $this->authenticate($operator, $this->newSessionId());
        $this->withHeader('Idempotency-Key', 'phone-proof-release-1');
        $started = $this->postJson('/api/v1/admin/trusted-browsers/release-challenges', [
            'target_user_id' => $target->id,
            'slot' => 1,
            'case_id' => 'CASE-PHONE-1',
            'reason' => 'Purpose-bound phone proof test.',
        ])->assertAccepted();
        $operatorSession = $this->persistSessionCookie();
        $challengeId = (string) $started->json('challenge_id');
        $challenge = TrustedBrowserChallenge::query()->findOrFail($challengeId);
        $oldProofId = $this->confirmedPhoneProof($target, $challenge->created_at->copy()->subSecond());

        $this->postJson("/api/v1/admin/trusted-browsers/release-challenges/{$challengeId}/verify", [
            'code' => $started->json('debug_code'),
        ])->assertConflict()
            ->assertJsonPath('error.code', 'TRUSTED_BROWSER_SUPPORT_SECOND_PROOF_REQUIRED');

        $validProofId = $this->confirmedPhoneProof($target, now());
        $this->postJson("/api/v1/admin/trusted-browsers/release-challenges/{$challengeId}/verify", [
            'code' => $started->json('debug_code'),
        ])->assertOk()->assertJsonPath('status', 'released');
        $this->assertDatabaseHas('trusted_browser_challenges', [
            'id' => $challengeId,
            'phone_verification_code_id' => $validProofId,
            'status' => 'completed',
        ]);
        $this->assertDatabaseMissing('trusted_browser_challenges', [
            'id' => $challengeId,
            'phone_verification_code_id' => $oldProofId,
        ]);

        $this->travel(61)->seconds();
        $this->enroll($target);
        $this->authenticate($operator, $operatorSession);
        $this->withHeader('Idempotency-Key', 'phone-proof-release-2');
        $second = $this->postJson('/api/v1/admin/trusted-browsers/release-challenges', [
            'target_user_id' => $target->id,
            'slot' => 1,
            'case_id' => 'CASE-PHONE-2',
            'reason' => 'The first phone proof must remain consumed.',
        ])->assertAccepted();
        $this->persistSessionCookie();

        $this->postJson('/api/v1/admin/trusted-browsers/release-challenges/'.$second->json('challenge_id').'/verify', [
            'code' => $second->json('debug_code'),
        ])->assertConflict()
            ->assertJsonPath('error.code', 'TRUSTED_BROWSER_SUPPORT_SECOND_PROOF_REQUIRED');
    }

    /** @return array{slot: int, slot_id: int, generation: int} */
    private function enroll(User $user): array
    {
        $this->authenticate($user, $this->newSessionId());
        $challenge = $this->postJson('/api/v1/auth/device-trust/challenges', ['purpose' => 'enroll'])
            ->assertAccepted();
        $this->persistSessionCookie();
        $response = $this->postJson('/api/v1/auth/device-trust/challenges/'.$challenge->json('challenge_id').'/verify', [
            'code' => $challenge->json('debug_code'),
        ])->assertOk();
        $account = TrustedBrowserAccount::query()->where('user_id', $user->id)->firstOrFail();
        $slot = TrustedBrowserSlot::query()
            ->where('account_id', $account->id)
            ->where('slot_number', $response->json('slot'))
            ->firstOrFail();

        return [
            'slot' => $slot->slot_number,
            'slot_id' => $slot->id,
            'generation' => (int) $slot->current_generation,
        ];
    }

    private function confirmedPhoneProof(User $user, mixed $confirmedAt): int
    {
        return (int) DB::table('phone_verification_codes')->insertGetId([
            'user_id' => $user->id,
            'phone' => $user->phone,
            'code_hash' => Hash::make('1234'),
            'attempts_left' => 5,
            'status' => 'confirmed',
            'expires_at' => now()->addMinutes(10),
            'consumed_at' => $confirmedAt,
            'confirmed_at' => $confirmedAt,
            'created_at' => $confirmedAt,
            'updated_at' => $confirmedAt,
        ]);
    }

    /** @param array<string, mixed> $attributes */
    private function verifiedUser(array $attributes = []): User
    {
        return User::factory()->create(array_merge([
            'email_verified_at' => now(),
            'phone' => sprintf('+7888%07d', $this->phoneSequence++),
            'phone_verified_at' => now(),
        ], $attributes));
    }

    private function authenticate(User $user, string $sessionId): void
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
        $this->withHeaders([
            'Origin' => self::ORIGIN,
            'X-CSRF-TOKEN' => self::CSRF_TOKEN,
        ]);
    }

    private function persistSessionCookie(): string
    {
        $session = $this->app['session']->driver();
        $sessionId = $session->getId();
        $session->save();
        $this->withCookie((string) config('session.cookie'), $sessionId);

        return $sessionId;
    }

    private function newSessionId(): string
    {
        return Str::random(40);
    }
}
