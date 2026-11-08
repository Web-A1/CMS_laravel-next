<?php

namespace App\Contracts\Kinescope;

use App\Data\Kinescope\KinescopePlaybackClaims;
use App\Data\Kinescope\KinescopePlaybackGrant;

interface KinescopePlaybackToken
{
    public function issue(
        int $userId,
        int $materialId,
        string $videoId,
        ?string $watermarkSessionId = null,
    ): KinescopePlaybackGrant;

    public function verify(string $token): KinescopePlaybackClaims;
}
