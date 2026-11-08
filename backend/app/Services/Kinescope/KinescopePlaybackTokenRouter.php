<?php

namespace App\Services\Kinescope;

use App\Contracts\Kinescope\KinescopePlaybackToken;
use App\Data\Kinescope\KinescopePlaybackClaims;
use App\Data\Kinescope\LegacyKinescopePlaybackClaims;
use App\Exceptions\InvalidKinescopePlaybackTokenException;
use JsonException;

final readonly class KinescopePlaybackTokenRouter
{
    public function __construct(
        private KinescopePlaybackToken $currentToken,
        private LegacyJwtKinescopePlaybackToken $legacyToken,
    ) {}

    public function verify(string $token): KinescopePlaybackClaims|LegacyKinescopePlaybackClaims
    {
        [$algorithm, $keyId, $issuer] = $this->routingTuple($token);

        if (
            $algorithm === 'HS256'
            && hash_equals((string) config('services.kinescope.playback_key_id', 'new-2026-01'), $keyId)
            && hash_equals((string) config('services.kinescope.playback_issuer', 'cms-laravel-next'), $issuer)
        ) {
            return $this->currentToken->verify($token);
        }

        if (
            $algorithm === 'RS256'
            && hash_equals((string) config('services.kinescope.legacy_playback_key_id', 'legacy-2026-01'), $keyId)
            && hash_equals((string) config('services.kinescope.legacy_playback_issuer', 'christmedschool-legacy'), $issuer)
        ) {
            return $this->legacyToken->verify($token);
        }

        throw new InvalidKinescopePlaybackTokenException('Unknown Kinescope playback token issuer.');
    }

    /**
     * @return array{string, string, string}
     */
    private function routingTuple(string $token): array
    {
        if ($token === '' || strlen($token) > 4096) {
            throw new InvalidKinescopePlaybackTokenException('Invalid Kinescope playback token.');
        }

        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            throw new InvalidKinescopePlaybackTokenException('Invalid Kinescope playback token.');
        }

        try {
            $header = json_decode($this->base64UrlDecode($parts[0]), true, flags: JSON_THROW_ON_ERROR);
            $payload = json_decode($this->base64UrlDecode($parts[1]), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidKinescopePlaybackTokenException('Invalid Kinescope playback token.', previous: $exception);
        }

        if (
            ! is_array($header)
            || array_keys($header) !== ['alg', 'typ', 'kid']
            || ($header['typ'] ?? null) !== 'JWT'
            || ! is_string($header['alg'] ?? null)
            || ! is_string($header['kid'] ?? null)
            || ! is_array($payload)
            || ! is_string($payload['iss'] ?? null)
        ) {
            throw new InvalidKinescopePlaybackTokenException('Invalid Kinescope playback token.');
        }

        return [$header['alg'], $header['kid'], $payload['iss']];
    }

    private function base64UrlDecode(string $value): string
    {
        if ($value === '' || preg_match('/^[A-Za-z0-9_-]+$/', $value) !== 1) {
            throw new InvalidKinescopePlaybackTokenException('Invalid Kinescope playback token.');
        }

        $remainder = strlen($value) % 4;
        if ($remainder === 1) {
            throw new InvalidKinescopePlaybackTokenException('Invalid Kinescope playback token.');
        }

        $decoded = base64_decode(strtr($value, '-_', '+/').str_repeat('=', (4 - $remainder) % 4), true);
        if (! is_string($decoded)) {
            throw new InvalidKinescopePlaybackTokenException('Invalid Kinescope playback token.');
        }

        return $decoded;
    }
}
