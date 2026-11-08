<?php

namespace Tests\Unit;

use App\Exceptions\InvalidKinescopePlaybackTokenException;
use App\Services\Kinescope\JwtKinescopePlaybackToken;
use Tests\TestCase;

class JwtKinescopePlaybackTokenTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->configureSigner();
    }

    private function configureSigner(): void
    {
        config()->set('services.kinescope', [
            'enabled' => true,
            'playback_secret' => str_repeat('s', 64),
            'playback_issuer' => 'cms-laravel-next',
            'playback_key_id' => 'new-2026-01',
            'playback_audience' => 'kinescope-player',
            'playback_ttl_seconds' => 300,
        ]);
    }

    public function test_free_preview_token_round_trip_preserves_exact_free_claims(): void
    {
        $this->travelTo(now()->startOfSecond());
        $service = app(JwtKinescopePlaybackToken::class);

        $grant = $service->issue(42, 84, 'video-123');
        $claims = $service->verify($grant->token);

        $this->assertSame(42, $claims->userId);
        $this->assertSame(84, $claims->materialId);
        $this->assertSame('video-123', $claims->videoId);
        $this->assertSame(now()->addMinutes(5)->timestamp, $claims->expiresAt->getTimestamp());
        $this->assertNull($claims->watermarkSessionId);

        [, $payload] = explode('.', $grant->token);
        $decodedPayload = json_decode($this->base64UrlDecode($payload), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(['iss', 'aud', 'jti', 'sub', 'iat', 'nbf', 'exp', 'mid', 'vid'], array_keys($decodedPayload));
    }

    public function test_paid_token_round_trip_preserves_watermark_session_claim(): void
    {
        $service = app(JwtKinescopePlaybackToken::class);
        $watermarkSessionId = '0fc8b70c-202d-4a08-8520-79e6e60812f7';

        $grant = $service->issue(42, 84, 'video-123', $watermarkSessionId);
        $claims = $service->verify($grant->token);

        $this->assertSame($watermarkSessionId, $claims->watermarkSessionId);

        [, $payload] = explode('.', $grant->token);
        $decodedPayload = json_decode($this->base64UrlDecode($payload), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(['iss', 'aud', 'jti', 'sub', 'iat', 'nbf', 'exp', 'mid', 'vid', 'wmid'], array_keys($decodedPayload));
    }

    public function test_issued_token_has_an_exact_algorithm_and_key_identifier_header(): void
    {
        $token = app(JwtKinescopePlaybackToken::class)->issue(42, 84, 'video-123')->token;
        [$encodedHeader] = explode('.', $token);
        $header = json_decode($this->base64UrlDecode($encodedHeader), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame([
            'alg' => 'HS256',
            'typ' => 'JWT',
            'kid' => 'new-2026-01',
        ], $header);
    }

    public function test_wrong_issuer_audience_or_secret_is_rejected(): void
    {
        $service = app(JwtKinescopePlaybackToken::class);
        $token = $service->issue(42, 84, 'video-123')->token;

        foreach ([
            ['services.kinescope.playback_issuer' => 'another-issuer'],
            ['services.kinescope.playback_audience' => 'another-audience'],
            ['services.kinescope.playback_secret' => str_repeat('x', 64)],
            ['services.kinescope.playback_key_id' => 'another-key'],
        ] as $override) {
            foreach ($override as $key => $value) {
                config()->set($key, $value);
            }

            try {
                $service->verify($token);
                $this->fail('Token with mismatched constraints was accepted.');
            } catch (InvalidKinescopePlaybackTokenException) {
                $this->addToAssertionCount(1);
            }

            $this->configureSigner();
        }
    }

    public function test_malformed_none_algorithm_and_unexpected_claims_are_rejected(): void
    {
        $service = app(JwtKinescopePlaybackToken::class);
        $validToken = $service->issue(42, 84, 'video-123')->token;
        [, $payload] = explode('.', $validToken);
        $decodedPayload = json_decode($this->base64UrlDecode($payload), true, flags: JSON_THROW_ON_ERROR);
        $validHeader = [
            'alg' => 'HS256',
            'typ' => 'JWT',
            'kid' => 'new-2026-01',
        ];
        $missingVideoIdClaim = $decodedPayload;
        unset($missingVideoIdClaim['vid']);

        $candidates = [
            '',
            'missing.parts',
            '***.invalid.payload',
            str_repeat('a', 4097),
            $this->signToken([...$validHeader, 'alg' => 'none'], $decodedPayload),
            $this->signToken($validHeader, [...$decodedPayload, 'admin' => true]),
            $this->signToken($validHeader, $missingVideoIdClaim),
            $this->signToken($validHeader, [...$decodedPayload, 'wmid' => null]),
        ];

        foreach ($candidates as $candidate) {
            try {
                $service->verify($candidate);
                $this->fail('Malformed or expanded token was accepted.');
            } catch (InvalidKinescopePlaybackTokenException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $header
     * @param  array<string, mixed>  $payload
     */
    private function signToken(array $header, array $payload): string
    {
        $encodedHeader = $this->base64UrlEncode(json_encode($header, JSON_THROW_ON_ERROR));
        $encodedPayload = $this->base64UrlEncode(json_encode($payload, JSON_THROW_ON_ERROR));
        $signature = hash_hmac('sha256', $encodedHeader.'.'.$encodedPayload, str_repeat('s', 64), true);

        return $encodedHeader.'.'.$encodedPayload.'.'.$this->base64UrlEncode($signature);
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
