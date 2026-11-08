<?php

namespace App\Data\Kinescope;

use DateTimeImmutable;

final readonly class LegacyKinescopePlaybackClaims
{
    public const INTENT_PROTECTED_PLAYBACK = 'protected-playback';

    public const INTENT_PUBLIC_PREVIEW = 'public-preview';

    public function __construct(
        public string $memberReference,
        public string $embedLocator,
        public DateTimeImmutable $expiresAt,
        public string $accessIntent,
    ) {}
}
