<?php

namespace App\Data\Kinescope;

final readonly class KinescopeVideoInspection
{
    public function __construct(
        public string $videoId,
        public string $embedLocator,
        public string $projectId,
        public string $status,
        public string $embedHost,
    ) {}
}
