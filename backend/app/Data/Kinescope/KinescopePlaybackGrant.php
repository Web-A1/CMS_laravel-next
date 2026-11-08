<?php

namespace App\Data\Kinescope;

use DateTimeImmutable;

final readonly class KinescopePlaybackGrant
{
    public function __construct(
        public string $token,
        public DateTimeImmutable $expiresAt,
    ) {}
}
