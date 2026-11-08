<?php

namespace App\Services\Kinescope;

use App\Data\Kinescope\LegacyKinescopePlaybackClaims;
use App\Exceptions\InvalidKinescopePlaybackTokenException;
use App\Exceptions\KinescopeNotConfiguredException;
use Carbon\CarbonImmutable;
use JsonException;
use OpenSSLAsymmetricKey;
use Throwable;

final class LegacyJwtKinescopePlaybackToken
{
    private const MAX_TOKEN_LENGTH = 4096;

    private const REQUIRED_CLAIMS = [
        'iss',
        'aud',
        'jti',
        'sub',
        'iat',
        'nbf',
        'exp',
        'loc',
        'scope',
    ];

    public function verify(string $token): LegacyKinescopePlaybackClaims
    {
        try {
            [$publicKey, $issuer, $audience, $keyId] = $this->configuration();

            if ($token === '' || strlen($token) > self::MAX_TOKEN_LENGTH) {
                throw $this->invalidToken();
            }

            $parts = explode('.', $token);
            if (count($parts) !== 3) {
                throw $this->invalidToken();
            }

            [$encodedHeader, $encodedPayload, $encodedSignature] = $parts;
            $header = $this->decodeJson($encodedHeader);
            if ($header !== [
                'alg' => 'RS256',
                'typ' => 'JWT',
                'kid' => $keyId,
            ]) {
                throw $this->invalidToken();
            }

            $signature = $this->base64UrlDecode($encodedSignature);
            if (openssl_verify(
                $encodedHeader.'.'.$encodedPayload,
                $signature,
                $publicKey,
                OPENSSL_ALGO_SHA256,
            ) !== 1) {
                throw $this->invalidToken();
            }

            $claims = $this->decodeJson($encodedPayload);
            if (array_keys($claims) !== self::REQUIRED_CLAIMS) {
                throw $this->invalidToken();
            }

            $issuedAt = $claims['iat'];
            $notBefore = $claims['nbf'];
            $expiresAt = $claims['exp'];
            $accessIntent = $this->accessIntent($claims['sub'], $claims['scope']);
            $now = CarbonImmutable::now('UTC')->timestamp;

            if (
                ! is_string($claims['iss'])
                || ! hash_equals($issuer, $claims['iss'])
                || ! is_string($claims['aud'])
                || ! hash_equals($audience, $claims['aud'])
                || ! is_string($claims['jti'])
                || preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $claims['jti']) !== 1
                || $accessIntent === null
                || ! is_int($issuedAt)
                || ! is_int($notBefore)
                || ! is_int($expiresAt)
                || $issuedAt > $now + 30
                || $notBefore > $now + 30
                || $notBefore !== $issuedAt
                || $expiresAt <= $now
                || $expiresAt <= $issuedAt
                || $expiresAt - $issuedAt < 60
                || $expiresAt - $issuedAt > 300
                || ! is_string($claims['loc'])
                || preg_match('/^[A-Za-z0-9_-]{1,255}$/', $claims['loc']) !== 1
            ) {
                throw $this->invalidToken();
            }

            return new LegacyKinescopePlaybackClaims(
                $claims['sub'],
                $claims['loc'],
                CarbonImmutable::createFromTimestampUTC($expiresAt)->toDateTimeImmutable(),
                $accessIntent,
            );
        } catch (KinescopeNotConfiguredException $exception) {
            throw $exception;
        } catch (InvalidKinescopePlaybackTokenException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw $this->invalidToken($exception);
        }
    }

    private function accessIntent(mixed $subject, mixed $scope): ?string
    {
        if (! is_string($subject) || ! is_string($scope)) {
            return null;
        }

        if ($scope === 'kinescope:playback' && preg_match('/^[1-9][0-9]{0,18}$/', $subject) === 1) {
            return LegacyKinescopePlaybackClaims::INTENT_PROTECTED_PLAYBACK;
        }

        if ($scope === 'kinescope:public-preview' && $subject === 'public-preview') {
            return LegacyKinescopePlaybackClaims::INTENT_PUBLIC_PREVIEW;
        }

        return null;
    }

    /**
     * @return array{OpenSSLAsymmetricKey, string, string, string}
     */
    private function configuration(): array
    {
        $encodedPublicKey = config('services.kinescope.legacy_playback_public_key_base64');
        $issuer = config('services.kinescope.legacy_playback_issuer', 'christmedschool-legacy');
        $audience = config('services.kinescope.legacy_playback_audience', 'kinescope-player');
        $keyId = config('services.kinescope.legacy_playback_key_id', 'legacy-2026-01');
        $publicKeyPem = is_string($encodedPublicKey) ? base64_decode($encodedPublicKey, true) : false;
        $publicKey = is_string($publicKeyPem) ? openssl_pkey_get_public($publicKeyPem) : false;
        $details = $publicKey instanceof OpenSSLAsymmetricKey ? openssl_pkey_get_details($publicKey) : false;

        if (
            config('services.kinescope.enabled') !== true
            || ! $publicKey instanceof OpenSSLAsymmetricKey
            || ! is_array($details)
            || ($details['type'] ?? null) !== OPENSSL_KEYTYPE_RSA
            || ($details['bits'] ?? 0) < 2048
            || ! is_string($issuer)
            || trim($issuer) === ''
            || ! is_string($audience)
            || trim($audience) === ''
            || ! is_string($keyId)
            || preg_match('/^[A-Za-z0-9._-]{1,64}$/', $keyId) !== 1
        ) {
            throw new KinescopeNotConfiguredException('Legacy Kinescope playback token configuration is invalid.');
        }

        return [$publicKey, $issuer, $audience, $keyId];
    }

    /**
     * @return array<string, mixed>
     *
     * @throws JsonException
     */
    private function decodeJson(string $value): array
    {
        $decoded = json_decode($this->base64UrlDecode($value), true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($decoded) || array_is_list($decoded)) {
            throw $this->invalidToken();
        }

        return $decoded;
    }

    private function base64UrlDecode(string $value): string
    {
        if ($value === '' || preg_match('/^[A-Za-z0-9_-]+$/', $value) !== 1) {
            throw $this->invalidToken();
        }

        $remainder = strlen($value) % 4;
        if ($remainder === 1) {
            throw $this->invalidToken();
        }

        $decoded = base64_decode(strtr($value, '-_', '+/').str_repeat('=', (4 - $remainder) % 4), true);
        if (! is_string($decoded)) {
            throw $this->invalidToken();
        }

        return $decoded;
    }

    private function invalidToken(?Throwable $previous = null): InvalidKinescopePlaybackTokenException
    {
        return new InvalidKinescopePlaybackTokenException('Invalid legacy Kinescope playback token.', previous: $previous);
    }
}
