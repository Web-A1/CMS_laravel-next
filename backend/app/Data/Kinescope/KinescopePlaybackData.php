<?php

namespace App\Data\Kinescope;

use DateTimeImmutable;

final readonly class KinescopePlaybackData
{
    public function __construct(
        public string $embedUrl,
        public DateTimeImmutable $expiresAt,
    ) {}
}
