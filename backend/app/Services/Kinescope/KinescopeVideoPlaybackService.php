<?php

namespace App\Services\Kinescope;

use App\Contracts\Kinescope\KinescopePlaybackToken;
use App\Data\Kinescope\KinescopePlaybackData;
use App\Exceptions\KinescopeNotConfiguredException;
use App\Exceptions\KinescopePlaybackUnavailableException;
use App\Models\LessonMaterial;
use App\Models\VideoWatermarkSession;

class KinescopeVideoPlaybackService
{
    public function __construct(
        private readonly KinescopePlaybackToken $playbackToken,
    ) {}

    public function issue(
        int $userId,
        LessonMaterial $material,
        ?VideoWatermarkSession $watermarkSession = null,
    ): KinescopePlaybackData {
        $embedBaseUrl = $this->embedBaseUrl();
        $locator = $material->kinescopeVideo()->first();

        if (
            ! $locator
            || ! is_string($locator->embed_locator)
            || preg_match('/^[A-Za-z0-9_-]{1,255}$/', $locator->embed_locator) !== 1
            || ! is_string($locator->provider_video_id)
            || preg_match('/^[A-Za-z0-9_-]{1,255}$/', $locator->provider_video_id) !== 1
        ) {
            throw new KinescopePlaybackUnavailableException('Kinescope video locator is missing or invalid.');
        }

        $grant = $this->playbackToken->issue(
            $userId,
            (int) $material->id,
            $locator->provider_video_id,
            $watermarkSession?->id,
        );
        $query = ['drmauthtoken' => $grant->token];
        if ($watermarkSession) {
            $query['watermark'] = $watermarkSession->code;
        }
        $embedUrl = $embedBaseUrl.'/'.rawurlencode($locator->embed_locator).'?'.http_build_query($query, encoding_type: PHP_QUERY_RFC3986);

        return new KinescopePlaybackData($embedUrl, $grant->expiresAt);
    }

    private function embedBaseUrl(): string
    {
        if (config('services.kinescope.enabled') !== true) {
            throw new KinescopeNotConfiguredException('Kinescope playback is disabled.');
        }

        $embedBaseUrl = config('services.kinescope.embed_base_url');
        if (! is_string($embedBaseUrl)) {
            throw new KinescopeNotConfiguredException('Kinescope embed URL is missing.');
        }

        $parts = parse_url($embedBaseUrl);
        if (
            ! is_array($parts)
            || ($parts['scheme'] ?? null) !== 'https'
            || ($parts['host'] ?? null) !== 'kinescope.io'
            || rtrim((string) ($parts['path'] ?? ''), '/') !== '/embed'
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
        ) {
            throw new KinescopeNotConfiguredException('Kinescope embed URL is invalid.');
        }

        return 'https://kinescope.io/embed';
    }
}
