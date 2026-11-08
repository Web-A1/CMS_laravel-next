<?php

namespace Tests\Feature;

use App\Contracts\Kinescope\KinescopePlaybackToken;
use App\Data\Kinescope\LegacyKinescopePlaybackClaims;
use App\Models\Course;
use App\Models\KinescopeLegacyVideoAllowlistBatch;
use App\Models\KinescopeLegacyVideoAllowlistEntry;
use App\Models\Lesson;
use App\Models\LessonMaterial;
use App\Models\LessonMaterialKinescopeVideo;
use App\Models\Subject;
use App\Models\User;
use App\Models\UserEntitlementGrant;
use App\Models\VideoWatermarkSession;
use App\Services\Kinescope\LegacyJwtKinescopePlaybackToken;
use App\Services\Kinescope\LegacyKinescopeVideoAllowlist;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\TestCase;

class KinescopeAuthorizationCallbackTest extends TestCase
{
    use RefreshDatabase;

    private string $legacyPrivateKey;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));
        $key = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        $legacyPrivateKey = '';
        if ($key === false || ! openssl_pkey_export($key, $legacyPrivateKey)) {
            throw new RuntimeException('Unable to create the legacy test key.');
        }
        $this->legacyPrivateKey = $legacyPrivateKey;

        $keyDetails = openssl_pkey_get_details($key);
        if (! is_array($keyDetails) || ! is_string($keyDetails['key'] ?? null)) {
            throw new RuntimeException('Unable to read the legacy test public key.');
        }

        config()->set('services.kinescope', [
            'enabled' => true,
            'embed_base_url' => 'https://kinescope.io/embed',
            'playback_secret' => str_repeat('k', 64),
            'playback_issuer' => 'cms-laravel-next',
            'playback_key_id' => 'new-2026-01',
            'playback_audience' => 'kinescope-player',
            'playback_ttl_seconds' => 300,
            'legacy_playback_issuer' => 'christmedschool-legacy',
            'legacy_playback_key_id' => 'legacy-2026-01',
            'legacy_playback_audience' => 'kinescope-player',
            'legacy_playback_public_key_base64' => base64_encode($keyDetails['key']),
            'callback_username' => 'kinescope-callback',
            'callback_password' => 'callback-password',
        ]);

        $this->withHeader('Referer', 'http://localhost:3000/');
        $this->withSession(['auth_marker' => 'default']);
    }

    public function test_valid_callback_allows_current_entitlement(): void
    {
        [$user, $subject, $course, $lesson, $material] = $this->createEntitledVideo();
        $token = $this->issueToken($user, $subject, $course, $lesson, $material);

        $this->postJson($this->callbackUrl(), $this->callbackPayload('video-callback', $token), $this->basicHeaders())
            ->assertOk()
            ->assertNoContent(200);
    }

    public function test_paid_callback_rejects_ended_or_revoked_watermark_session(): void
    {
        [$user, $subject, $course, $lesson, $material] = $this->createEntitledVideo();
        $token = $this->issueToken($user, $subject, $course, $lesson, $material);
        $watermark = VideoWatermarkSession::query()->sole();

        $this->postJson($this->callbackUrl(), $this->callbackPayload('video-callback', $token), $this->basicHeaders())
            ->assertOk();

        $watermark->forceFill([
            'ended_at' => now(),
            'end_reason' => VideoWatermarkSession::END_REASON_REVOKED,
            'purge_after' => now()->addYears(3),
        ])->save();

        $this->postJson($this->callbackUrl(), $this->callbackPayload('video-callback', $token), $this->basicHeaders())
            ->assertForbidden();
    }

    public function test_paid_callback_rejects_nonexistent_or_other_users_watermark_session(): void
    {
        [$user, $subject, $course, $lesson, $material] = $this->createEntitledVideo();
        $token = $this->issueToken($user, $subject, $course, $lesson, $material);
        $watermark = VideoWatermarkSession::query()->sole();
        $otherUser = User::factory()->create(['role' => 'user_no_purchase']);

        $watermark->forceFill(['user_id' => $otherUser->id])->save();

        $this->postJson($this->callbackUrl(), $this->callbackPayload('video-callback', $token), $this->basicHeaders())
            ->assertForbidden();

        $nonexistentWatermarkToken = app(KinescopePlaybackToken::class)->issue(
            $user->id,
            $material->id,
            'video-callback',
            'a4a73d2e-ae64-4bfe-80f2-d90e1c916cb0',
        )->token;

        $this->postJson($this->callbackUrl(), $this->callbackPayload('video-callback', $nonexistentWatermarkToken), $this->basicHeaders())
            ->assertForbidden();
    }

    public function test_free_preview_callback_allows_token_without_entitlement_or_watermark_and_rejects_watermark(): void
    {
        [$user, , , $lesson, $material, $grant] = $this->createEntitledVideo();
        $lesson->forceFill(['is_free_preview' => true])->save();
        $grant->delete();

        $tokenService = app(KinescopePlaybackToken::class);
        $freeToken = $tokenService->issue($user->id, $material->id, 'video-callback')->token;
        $paidShapeToken = $tokenService->issue(
            $user->id,
            $material->id,
            'video-callback',
            '0fc8b70c-202d-4a08-8520-79e6e60812f7',
        )->token;
        $nullWatermarkToken = $this->issueCurrentTokenWithClaims($user, $material, ['wmid' => null]);

        $this->postJson($this->callbackUrl(), $this->callbackPayload('video-callback', $freeToken), $this->basicHeaders())
            ->assertOk();
        $this->postJson($this->callbackUrl(), $this->callbackPayload('video-callback', $paidShapeToken), $this->basicHeaders())
            ->assertForbidden();
        $this->postJson($this->callbackUrl(), $this->callbackPayload('video-callback', $nullWatermarkToken), $this->basicHeaders())
            ->assertForbidden();
    }

    public function test_reused_provider_video_id_does_not_authorize_a_different_material_grant(): void
    {
        [$user, $subject, $course, $lesson, $material, $grant] = $this->createEntitledVideo();
        $token = $this->issueToken($user, $subject, $course, $lesson, $material);

        $otherSubject = Subject::query()->create(['slug' => 'physiology', 'title' => 'Физиология']);
        $otherCourse = Course::query()->create([
            'subject_id' => $otherSubject->id,
            'slug' => 'blood',
            'title' => 'Кровь',
            'price_cents' => 100000,
        ]);
        $otherLesson = Lesson::query()->create([
            'course_id' => $otherCourse->id,
            'slug' => 'cells',
            'title' => 'Клетки крови',
        ]);
        $otherMaterial = LessonMaterial::query()->create([
            'lesson_id' => $otherLesson->id,
            'kind' => LessonMaterial::KIND_VIDEO,
            'title' => 'То же Kinescope-видео в другом уроке',
            'is_published' => true,
        ]);
        LessonMaterialKinescopeVideo::query()->create([
            'lesson_material_id' => $otherMaterial->id,
            'embed_locator' => 'another-player-callback',
            'provider_video_id' => 'video-callback',
        ]);
        UserEntitlementGrant::query()->create([
            'user_id' => $user->id,
            'atom_kind' => UserEntitlementGrant::ATOM_LESSON,
            'atom_id' => $otherLesson->id,
            'status' => UserEntitlementGrant::STATUS_ACTIVE,
            'source_type' => UserEntitlementGrant::SOURCE_TEST_FIXTURE,
            'source_id' => "callback-lesson-{$otherLesson->id}",
            'idempotency_key' => "callback-{$user->id}-{$otherLesson->id}",
            'granted_at' => now(),
        ]);
        $grant->update([
            'status' => UserEntitlementGrant::STATUS_REVOKED,
            'revoked_at' => now(),
        ]);

        $this->postJson($this->callbackUrl(), $this->callbackPayload('video-callback', $token), $this->basicHeaders())
            ->assertForbidden();
    }

    public function test_expired_tampered_or_wrong_video_token_is_denied(): void
    {
        $this->travelTo(now()->startOfSecond());
        [$user, $subject, $course, $lesson, $material] = $this->createEntitledVideo();
        $token = $this->issueToken($user, $subject, $course, $lesson, $material);

        $this->travel(6)->minutes();
        $this->postJson($this->callbackUrl(), $this->callbackPayload('video-callback', $token), $this->basicHeaders())
            ->assertForbidden();

        $this->travelBack();
        $token = $this->issueToken($user, $subject, $course, $lesson, $material);

        $this->postJson($this->callbackUrl(), $this->callbackPayload('video-callback', $token.'tampered'), $this->basicHeaders())
            ->assertForbidden();
        $this->postJson($this->callbackUrl(), $this->callbackPayload('another-video', $token), $this->basicHeaders())
            ->assertForbidden();
    }

    public function test_optional_provider_context_does_not_change_authorization(): void
    {
        [$user, $subject, $course, $lesson, $material] = $this->createEntitledVideo();
        $token = $this->issueToken($user, $subject, $course, $lesson, $material);

        $this->postJson(
            $this->callbackUrl(),
            $this->callbackPayload('video-callback', $token) + [
                'ip' => null,
                'user_agent' => ['unexpected-provider-shape'],
            ],
            $this->basicHeaders(),
        )->assertOk()->assertNoContent(200);
    }

    public function test_revoked_entitlement_is_denied_even_when_token_is_still_valid(): void
    {
        [$user, $subject, $course, $lesson, $material, $grant] = $this->createEntitledVideo();
        $token = $this->issueToken($user, $subject, $course, $lesson, $material);
        $grant->update([
            'status' => UserEntitlementGrant::STATUS_REVOKED,
            'revoked_at' => now(),
        ]);

        $this->postJson($this->callbackUrl(), $this->callbackPayload('video-callback', $token), $this->basicHeaders())
            ->assertForbidden();
    }

    public function test_unpublished_material_is_denied_after_token_issuance(): void
    {
        [$user, $subject, $course, $lesson, $material] = $this->createEntitledVideo();
        $token = $this->issueToken($user, $subject, $course, $lesson, $material);
        $material->update(['is_published' => false]);

        $this->postJson($this->callbackUrl(), $this->callbackPayload('video-callback', $token), $this->basicHeaders())
            ->assertForbidden();
    }

    public function test_missing_or_wrong_basic_auth_is_denied_without_details(): void
    {
        $payload = $this->callbackPayload('video-callback', 'not-a-token');

        $this->postJson($this->callbackUrl(), $payload)->assertForbidden();
        $this->postJson(
            $this->callbackUrl(),
            $payload,
            ['Authorization' => 'Basic '.base64_encode('wrong:credentials')],
        )->assertForbidden();
    }

    public function test_malformed_callback_returns_bad_request_and_missing_config_returns_503(): void
    {
        $this->postJson($this->callbackUrl(), ['id' => 'video-callback'], $this->basicHeaders())
            ->assertBadRequest();

        config()->set('services.kinescope.callback_password', '');

        $this->postJson(
            $this->callbackUrl(),
            $this->callbackPayload('video-callback', 'not-a-token'),
            $this->basicHeaders(),
        )->assertServiceUnavailable();
    }

    public function test_valid_legacy_callback_allows_a_short_lived_signed_access_assertion(): void
    {
        $this->allowLegacyVideo('legacy-player', 'legacy-video');
        $token = $this->issueLegacyToken('legacy-player');

        $claims = app(LegacyJwtKinescopePlaybackToken::class)->verify($token);
        $this->assertSame(LegacyKinescopePlaybackClaims::INTENT_PROTECTED_PLAYBACK, $claims->accessIntent);

        $this->postJson(
            $this->callbackUrl(),
            $this->callbackPayload('legacy-video', $token),
            $this->basicHeaders(),
        )->assertOk()->assertNoContent(200);
    }

    public function test_valid_legacy_public_preview_callback_requires_the_exact_active_allowlist_pair(): void
    {
        $this->allowLegacyVideo('legacy-player', 'legacy-video');
        $token = $this->issueLegacyPublicPreviewToken('legacy-player');

        $claims = app(LegacyJwtKinescopePlaybackToken::class)->verify($token);
        $this->assertSame(LegacyKinescopePlaybackClaims::INTENT_PUBLIC_PREVIEW, $claims->accessIntent);

        $this->postJson(
            $this->callbackUrl(),
            $this->callbackPayload('legacy-video', $token),
            $this->basicHeaders(),
        )->assertOk()->assertNoContent(200);

        $this->postJson(
            $this->callbackUrl(),
            $this->callbackPayload('another-video', $token),
            $this->basicHeaders(),
        )->assertForbidden();

        $this->postJson(
            $this->callbackUrl(),
            $this->callbackPayload('legacy-video', $this->issueLegacyPublicPreviewToken('another-player')),
            $this->basicHeaders(),
        )->assertForbidden();
    }

    public function test_legacy_public_preview_callback_rejects_mixed_or_invalid_token_forms(): void
    {
        $this->allowLegacyVideo('legacy-player', 'legacy-video');

        $invalidTokens = [
            $this->issueLegacyToken('legacy-player', claims: [
                'sub' => '42',
                'scope' => 'kinescope:public-preview',
            ]),
            $this->issueLegacyToken('legacy-player', claims: [
                'sub' => 'public-preview',
                'scope' => 'kinescope:playback',
            ]),
            $this->issueLegacyToken('legacy-player', claims: [
                'sub' => 'unknown-intent',
                'scope' => 'kinescope:unknown',
            ]),
            $this->issueLegacyPublicPreviewToken('legacy-player', now()->timestamp - 301),
            $this->issueLegacyPublicPreviewToken('legacy-player', claims: ['extra' => 'unexpected']),
            $this->issueLegacyPublicPreviewToken('legacy-player', claimsToRemove: ['scope']),
        ];

        $tamperedToken = $this->issueLegacyPublicPreviewToken('legacy-player');
        $invalidTokens[] = $tamperedToken.'tampered';

        foreach ($invalidTokens as $invalidToken) {
            $this->postJson(
                $this->callbackUrl(),
                $this->callbackPayload('legacy-video', $invalidToken),
                $this->basicHeaders(),
            )->assertForbidden();
        }
    }

    public function test_legacy_callback_rejects_invalid_or_misrouted_tokens(): void
    {
        $this->allowLegacyVideo('legacy-player', 'legacy-video');
        $validToken = $this->issueLegacyToken('legacy-player');
        $allowlist = app(LegacyKinescopeVideoAllowlist::class);
        $this->assertTrue($allowlist->allows('legacy-player', 'legacy-video'));
        $this->assertFalse($allowlist->allows('legacy-player', 'another-video'));
        $this->assertFalse($allowlist->allows('another-player', 'legacy-video'));

        $this->postJson(
            $this->callbackUrl(),
            $this->callbackPayload('another-video', $validToken),
            $this->basicHeaders(),
        )->assertForbidden();

        $this->postJson(
            $this->callbackUrl(),
            $this->callbackPayload('legacy-video', $this->issueLegacyToken('another-player')),
            $this->basicHeaders(),
        )->assertForbidden();

        $this->postJson(
            $this->callbackUrl(),
            $this->callbackPayload('legacy-video', $validToken.'tampered'),
            $this->basicHeaders(),
        )->assertForbidden();

        $this->postJson(
            $this->callbackUrl(),
            $this->callbackPayload('legacy-video', $this->issueLegacyToken('legacy-player', now()->timestamp - 301)),
            $this->basicHeaders(),
        )->assertForbidden();

        $this->postJson(
            $this->callbackUrl(),
            $this->callbackPayload('legacy-video', $this->issueLegacyToken('legacy-player', claims: ['iss' => 'unknown-issuer'])),
            $this->basicHeaders(),
        )->assertForbidden();

        $this->postJson(
            $this->callbackUrl(),
            $this->callbackPayload('legacy-video', $this->issueLegacyToken('legacy-player', header: ['alg' => 'HS256'])),
            $this->basicHeaders(),
        )->assertForbidden();

        foreach ([
            $this->issueLegacyToken('legacy-player', claims: ['scope' => 'other']),
            $this->issueLegacyToken('legacy-player', claims: ['sub' => '0']),
            $this->issueLegacyToken('legacy-player', claims: ['extra' => 'unexpected']),
            $this->issueLegacyToken('legacy-player', now()->timestamp + 31),
            $this->issueLegacyToken('legacy-player', header: ['kid' => 'unknown-key']),
        ] as $invalidToken) {
            $this->postJson(
                $this->callbackUrl(),
                $this->callbackPayload('legacy-video', $invalidToken),
                $this->basicHeaders(),
            )->assertForbidden();
        }
    }

    public function test_legacy_callback_fails_closed_when_its_public_key_is_missing(): void
    {
        $token = $this->issueLegacyToken('legacy-player');
        config()->set('services.kinescope.legacy_playback_public_key_base64', '');

        $this->postJson(
            $this->callbackUrl(),
            $this->callbackPayload('legacy-video', $token),
            $this->basicHeaders(),
        )->assertServiceUnavailable();
    }

    public function test_legacy_callback_rejects_a_new_only_video_outside_the_active_allowlist(): void
    {
        $this->createEntitledVideo();
        $this->allowLegacyVideo('another-legacy-player', 'another-legacy-video');

        $this->postJson(
            $this->callbackUrl(),
            $this->callbackPayload('video-callback', $this->issueLegacyToken('player-callback')),
            $this->basicHeaders(),
        )->assertForbidden();
    }

    public function test_legacy_callback_fails_closed_without_exactly_one_active_allowlist_batch(): void
    {
        $token = $this->issueLegacyToken('legacy-player');

        $this->postJson(
            $this->callbackUrl(),
            $this->callbackPayload('legacy-video', $token),
            $this->basicHeaders(),
        )->assertServiceUnavailable();

        $this->allowLegacyVideo('legacy-player', 'legacy-video');
        $this->allowLegacyVideo('legacy-player', 'legacy-video', 'second-batch');

        $this->postJson(
            $this->callbackUrl(),
            $this->callbackPayload('legacy-video', $token),
            $this->basicHeaders(),
        )->assertServiceUnavailable();
    }

    public function test_legacy_callback_fails_closed_when_allowlist_storage_is_unavailable(): void
    {
        $this->allowLegacyVideo('legacy-player', 'legacy-video');
        $token = $this->issueLegacyToken('legacy-player');
        Schema::drop('kinescope_legacy_video_allowlist_entries');

        $this->postJson(
            $this->callbackUrl(),
            $this->callbackPayload('legacy-video', $token),
            $this->basicHeaders(),
        )->assertServiceUnavailable();
    }

    public function test_legacy_callback_reports_unavailable_for_an_incomplete_active_allowlist_batch(): void
    {
        $batch = KinescopeLegacyVideoAllowlistBatch::query()->create([
            'source_count' => 1,
            'source_sha256' => hash('sha256', 'legacy-player'),
            'is_active' => true,
            'activated_at' => now(),
        ]);
        KinescopeLegacyVideoAllowlistEntry::query()->create([
            'batch_id' => $batch->id,
            'embed_locator' => 'legacy-player',
            'provider_video_id' => null,
        ]);

        $this->postJson(
            $this->callbackUrl(),
            $this->callbackPayload('legacy-video', $this->issueLegacyToken('legacy-player')),
            $this->basicHeaders(),
        )->assertServiceUnavailable();
    }

    public function test_valid_callback_is_not_blocked_by_the_generic_120_per_minute_limit(): void
    {
        [$user, $subject, $course, $lesson, $material] = $this->createEntitledVideo();
        $token = $this->issueToken($user, $subject, $course, $lesson, $material);

        for ($request = 0; $request < 121; $request++) {
            $this->postJson(
                $this->callbackUrl(),
                $this->callbackPayload('video-callback', $token),
                $this->basicHeaders(),
            )->assertOk();
        }
    }

    /**
     * @return array{User, Subject, Course, Lesson, LessonMaterial, UserEntitlementGrant}
     */
    private function createEntitledVideo(): array
    {
        $user = User::factory()->create(['role' => 'user_no_purchase']);
        $subject = Subject::query()->create(['slug' => 'anatomy', 'title' => 'Анатомия']);
        $course = Course::query()->create([
            'subject_id' => $subject->id,
            'slug' => 'osteology',
            'title' => 'Остеология',
            'price_cents' => 100000,
        ]);
        $lesson = Lesson::query()->create([
            'course_id' => $course->id,
            'slug' => 'bones',
            'title' => 'Кости',
        ]);
        $material = LessonMaterial::query()->create([
            'lesson_id' => $lesson->id,
            'kind' => LessonMaterial::KIND_VIDEO,
            'title' => 'Видеолекция',
            'is_published' => true,
        ]);
        LessonMaterialKinescopeVideo::query()->create([
            'lesson_material_id' => $material->id,
            'embed_locator' => 'player-callback',
            'provider_video_id' => 'video-callback',
        ]);
        $grant = UserEntitlementGrant::query()->create([
            'user_id' => $user->id,
            'atom_kind' => UserEntitlementGrant::ATOM_LESSON,
            'atom_id' => $lesson->id,
            'status' => UserEntitlementGrant::STATUS_ACTIVE,
            'source_type' => UserEntitlementGrant::SOURCE_TEST_FIXTURE,
            'source_id' => "callback-lesson-{$lesson->id}",
            'idempotency_key' => "callback-{$user->id}-{$lesson->id}",
            'granted_at' => now(),
        ]);

        return [$user, $subject, $course, $lesson, $material, $grant];
    }

    private function allowLegacyVideo(
        string $embedLocator,
        string $providerVideoId,
        ?string $source = null,
    ): void {
        $source ??= $embedLocator;
        $batch = KinescopeLegacyVideoAllowlistBatch::query()->create([
            'source_count' => 1,
            'source_sha256' => hash('sha256', $source),
            'is_active' => true,
            'activated_at' => now(),
        ]);
        KinescopeLegacyVideoAllowlistEntry::query()->create([
            'batch_id' => $batch->id,
            'embed_locator' => $embedLocator,
            'provider_video_id' => $providerVideoId,
        ]);
    }

    private function issueToken(
        User $user,
        Subject $subject,
        Course $course,
        Lesson $lesson,
        LessonMaterial $material,
    ): string {
        Sanctum::actingAs($user);

        $response = $this->postJson(
            "/api/v1/learning/subjects/{$subject->slug}/courses/{$course->slug}/lessons/{$lesson->slug}/video-playback",
            ['materialId' => $material->id],
        )->assertOk();

        parse_str((string) parse_url((string) $response->json('data.embedUrl'), PHP_URL_QUERY), $query);

        return (string) $query['drmauthtoken'];
    }

    /**
     * @param  array<string, mixed>  $claims
     */
    private function issueCurrentTokenWithClaims(User $user, LessonMaterial $material, array $claims): string
    {
        $token = app(KinescopePlaybackToken::class)->issue(
            $user->id,
            $material->id,
            'video-callback',
        )->token;
        [$encodedHeader, $encodedPayload] = explode('.', $token);
        $payload = json_decode($this->base64UrlDecode($encodedPayload), true, flags: JSON_THROW_ON_ERROR);
        $payload = array_replace($payload, $claims);
        $encodedPayload = $this->base64UrlEncode(json_encode($payload, JSON_THROW_ON_ERROR));
        $signature = hash_hmac('sha256', $encodedHeader.'.'.$encodedPayload, str_repeat('k', 64), true);

        return $encodedHeader.'.'.$encodedPayload.'.'.$this->base64UrlEncode($signature);
    }

    /**
     * @return array{id: string, type: string, token: string}
     */
    private function callbackPayload(string $videoId, string $token): array
    {
        return [
            'id' => $videoId,
            'type' => 'vod',
            'token' => $token,
        ];
    }

    /**
     * @return array{Authorization: string}
     */
    private function basicHeaders(): array
    {
        return ['Authorization' => 'Basic '.base64_encode('kinescope-callback:callback-password')];
    }

    private function callbackUrl(): string
    {
        return '/api/v1/integrations/kinescope/authorize';
    }

    /**
     * @param  array<string, int|string>  $claims
     * @param  array<string, string>  $header
     * @param  list<string>  $claimsToRemove
     */
    private function issueLegacyToken(
        string $embedLocator,
        ?int $issuedAt = null,
        array $claims = [],
        array $header = [],
        array $claimsToRemove = [],
    ): string {
        $issuedAt ??= now()->timestamp;
        $header = array_replace([
            'alg' => 'RS256',
            'typ' => 'JWT',
            'kid' => 'legacy-2026-01',
        ], $header);
        $claims = array_replace([
            'iss' => 'christmedschool-legacy',
            'aud' => 'kinescope-player',
            'jti' => (string) str()->uuid(),
            'sub' => '42',
            'iat' => $issuedAt,
            'nbf' => $issuedAt,
            'exp' => $issuedAt + 300,
            'loc' => $embedLocator,
            'scope' => 'kinescope:playback',
        ], $claims);
        $claims = array_diff_key($claims, array_fill_keys($claimsToRemove, true));
        $encodedHeader = $this->base64UrlEncode(json_encode($header, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        $encodedClaims = $this->base64UrlEncode(json_encode($claims, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        $signingInput = $encodedHeader.'.'.$encodedClaims;
        $signature = '';

        if (! openssl_sign($signingInput, $signature, $this->legacyPrivateKey, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('Unable to sign the legacy test token.');
        }

        return $signingInput.'.'.$this->base64UrlEncode($signature);
    }

    /**
     * @param  array<string, int|string>  $claims
     * @param  list<string>  $claimsToRemove
     */
    private function issueLegacyPublicPreviewToken(
        string $embedLocator,
        ?int $issuedAt = null,
        array $claims = [],
        array $claimsToRemove = [],
    ): string {
        return $this->issueLegacyToken(
            $embedLocator,
            $issuedAt,
            array_replace([
                'sub' => 'public-preview',
                'scope' => 'kinescope:public-preview',
            ], $claims),
            claimsToRemove: $claimsToRemove,
        );
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $value): string
    {
        return base64_decode(strtr($value, '-_', '+/'), true) ?: '';
    }
}
