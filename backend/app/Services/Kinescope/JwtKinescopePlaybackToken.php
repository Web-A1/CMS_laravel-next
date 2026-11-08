<?php

namespace App\Services\Kinescope;

use App\Contracts\Kinescope\KinescopePlaybackToken;
use App\Data\Kinescope\KinescopePlaybackClaims;
use App\Data\Kinescope\KinescopePlaybackGrant;
use App\Exceptions\InvalidKinescopePlaybackTokenException;
use App\Exceptions\KinescopeNotConfiguredException;
use App\Exceptions\KinescopePlaybackUnavailableException;
use Carbon\CarbonImmutable;
use JsonException;
use Throwable;

class JwtKinescopePlaybackToken implements KinescopePlaybackToken
{
    private const MAX_TOKEN_LENGTH = 4096;

    private const FREE_REQUIRED_CLAIMS = [
        'iss',
        'aud',
        'jti',
        'sub',
        'iat',
        'nbf',
        'exp',
        'mid',
        'vid',
    ];

    private const PAID_REQUIRED_CLAIMS = [
        'iss',
        'aud',
        'jti',
        'sub',
        'iat',
        'nbf',
        'exp',
        'mid',
        'vid',
        'wmid',
    ];

    public function issue(
        int $userId,
        int $materialId,
        string $videoId,
        ?string $watermarkSessionId = null,
    ): KinescopePlaybackGrant {
        [$secret, $issuer, $audience, $ttlSeconds, $keyId] = $this->configuration();

        if (
            $userId < 1
            || $materialId < 1
            || ! $this->validVideoId($videoId)
            || ($watermarkSessionId !== null && ! $this->validWatermarkSessionId($watermarkSessionId))
        ) {
            throw new KinescopePlaybackUnavailableException('Kinescope playback token input is invalid.');
        }

        $issuedAt = CarbonImmutable::now('UTC');
        $expiresAt = $issuedAt->addSeconds($ttlSeconds);

        try {
            $header = $this->encodeJson([
                'alg' => 'HS256',
                'typ' => 'JWT',
                'kid' => $keyId,
            ]);
            $payloadClaims = [
                'iss' => $issuer,
                'aud' => $audience,
                'jti' => (string) str()->uuid(),
                'sub' => (string) $userId,
                'iat' => $issuedAt->timestamp,
                'nbf' => $issuedAt->timestamp,
                'exp' => $expiresAt->timestamp,
                'mid' => $materialId,
                'vid' => $videoId,
            ];
            if ($watermarkSessionId !== null) {
                $payloadClaims['wmid'] = $watermarkSessionId;
            }
            $payload = $this->encodeJson($payloadClaims);
            $signingInput = $header.'.'.$payload;
            $signature = $this->base64UrlEncode(hash_hmac('sha256', $signingInput, $secret, true));
        } catch (Throwable $exception) {
            throw new KinescopePlaybackUnavailableException('Unable to issue Kinescope playback token.', previous: $exception);
        }

        return new KinescopePlaybackGrant($signingInput.'.'.$signature, $expiresAt->toDateTimeImmutable());
    }

    public function verify(string $token): KinescopePlaybackClaims
    {
        try {
            [$secret, $issuer, $audience, , $keyId] = $this->configuration();

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
                'alg' => 'HS256',
                'typ' => 'JWT',
                'kid' => $keyId,
            ]) {
                throw $this->invalidToken();
            }

            $signature = $this->base64UrlDecode($encodedSignature);
            $expectedSignature = hash_hmac('sha256', $encodedHeader.'.'.$encodedPayload, $secret, true);
            if (strlen($signature) !== 32 || ! hash_equals($expectedSignature, $signature)) {
                throw $this->invalidToken();
            }

            $claims = $this->decodeJson($encodedPayload);
            $hasWatermarkSession = array_key_exists('wmid', $claims);
            if (array_keys($claims) !== ($hasWatermarkSession ? self::PAID_REQUIRED_CLAIMS : self::FREE_REQUIRED_CLAIMS)) {
                throw $this->invalidToken();
            }

            $userId = filter_var($claims['sub'], FILTER_VALIDATE_INT);
            $materialId = $claims['mid'];
            $videoId = $claims['vid'];
            $issuedAt = $claims['iat'];
            $notBefore = $claims['nbf'];
            $expiresAt = $claims['exp'];
            $watermarkSessionId = $hasWatermarkSession ? $claims['wmid'] : null;
            $now = CarbonImmutable::now('UTC')->timestamp;

            if (
                ! is_string($claims['iss'])
                || ! hash_equals($issuer, $claims['iss'])
                || ! is_string($claims['aud'])
                || ! hash_equals($audience, $claims['aud'])
                || ! is_string($claims['jti'])
                || preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $claims['jti']) !== 1
                || ! is_int($userId)
                || $userId < 1
                || ! is_int($materialId)
                || $materialId < 1
                || ! is_string($videoId)
                || ! $this->validVideoId($videoId)
                || ($hasWatermarkSession && (! is_string($watermarkSessionId) || ! $this->validWatermarkSessionId($watermarkSessionId)))
                || ! is_int($issuedAt)
                || ! is_int($notBefore)
                || ! is_int($expiresAt)
                || $issuedAt > $now
                || $notBefore > $now
                || $notBefore !== $issuedAt
                || $expiresAt <= $now
                || $expiresAt <= $issuedAt
                || $expiresAt - $issuedAt > 600
            ) {
                throw $this->invalidToken();
            }

            return new KinescopePlaybackClaims(
                $userId,
                $materialId,
                $videoId,
                CarbonImmutable::createFromTimestampUTC($expiresAt)->toDateTimeImmutable(),
                $watermarkSessionId,
            );
        } catch (KinescopePlaybackUnavailableException $exception) {
            throw $exception;
        } catch (InvalidKinescopePlaybackTokenException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw $this->invalidToken($exception);
        }
    }

    /**
     * @return array{string, string, string, int, string}
     */
    private function configuration(): array
    {
        $enabled = config('services.kinescope.enabled') === true;
        $secret = config('services.kinescope.playback_secret');
        $issuer = config('services.kinescope.playback_issuer');
        $audience = config('services.kinescope.playback_audience');
        $ttlSeconds = config('services.kinescope.playback_ttl_seconds');
        $keyId = config('services.kinescope.playback_key_id', 'new-2026-01');

        if (
            ! $enabled
            || ! is_string($secret)
            || strlen($secret) < 32
            || ! is_string($issuer)
            || trim($issuer) === ''
            || ! is_string($audience)
            || trim($audience) === ''
            || ! is_int($ttlSeconds)
            || $ttlSeconds < 60
            || $ttlSeconds > 600
            || ! is_string($keyId)
            || preg_match('/^[A-Za-z0-9._-]{1,64}$/', $keyId) !== 1
        ) {
            throw new KinescopeNotConfiguredException('Kinescope playback token configuration is invalid.');
        }

        return [$secret, $issuer, $audience, $ttlSeconds, $keyId];
    }

    /**
     * @param  array<string, int|string>  $value
     *
     * @throws JsonException
     */
    private function encodeJson(array $value): string
    {
        return $this->base64UrlEncode(json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
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

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
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

        $padded = strtr($value, '-_', '+/').str_repeat('=', (4 - $remainder) % 4);
        $decoded = base64_decode($padded, true);
        if (! is_string($decoded)) {
            throw $this->invalidToken();
        }

        return $decoded;
    }

    private function validVideoId(string $videoId): bool
    {
        return preg_match('/^[A-Za-z0-9_-]{1,255}$/', $videoId) === 1;
    }

    private function validWatermarkSessionId(string $watermarkSessionId): bool
    {
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $watermarkSessionId) === 1;
    }

    private function invalidToken(?Throwable $previous = null): InvalidKinescopePlaybackTokenException
    {
        return new InvalidKinescopePlaybackTokenException('Invalid Kinescope playback token.', previous: $previous);
    }
}
