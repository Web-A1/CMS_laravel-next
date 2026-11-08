<?php

namespace App\Data\Kinescope;

use DateTimeImmutable;

final readonly class KinescopePlaybackClaims
{
    public function __construct(
        public int $userId,
        public int $materialId,
        public string $videoId,
        public DateTimeImmutable $expiresAt,
        public ?string $watermarkSessionId,
    ) {}
}
