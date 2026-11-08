<?php

namespace Tests\Unit;

use Tests\TestCase;

class KinescopePlaybackConfigTest extends TestCase
{
    public function test_playback_ttl_default_is_fifteen_minutes(): void
    {
        $servicesConfig = (string) file_get_contents(config_path('services.php'));

        $this->assertStringContainsString("env('KINESCOPE_PLAYBACK_TTL_SECONDS', 900)", $servicesConfig);
    }
}
