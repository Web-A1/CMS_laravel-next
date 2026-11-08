<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class KinescopeInspectVideoCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.kinescope.api_base_url', 'https://api.kinescope.io');
        config()->set('services.kinescope.api_token', '11111111-1111-4111-8111-111111111111');
        config()->set('services.kinescope.project_id', 'project-123');
    }

    public function test_command_prints_only_safe_read_only_inspection(): void
    {
        Http::fake([
            'https://api.kinescope.io/v1/videos/player-123' => Http::response([
                'data' => [
                    'id' => 'canonical-video-123',
                    'project_id' => 'project-123',
                    'status' => 'done',
                    'embed_link' => 'https://kinescope.io/embed/player-123',
                    'hls_link' => 'private-hls-sentinel',
                    'assets' => [['download_link' => 'private-download-sentinel']],
                ],
            ]),
        ]);

        $exitCode = Artisan::call('kinescope:inspect-video', ['embedLocator' => 'player-123']);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringNotContainsString('canonical-video-123', $output);
        $this->assertStringNotContainsString('player-123', $output);
        $this->assertStringNotContainsString('project-123', $output);
        $this->assertStringContainsString('done', $output);
        $this->assertStringContainsString('kinescope.io', $output);
        $this->assertStringContainsString('mapping=verified', $output);
        $this->assertStringNotContainsString('private-hls-sentinel', $output);
        $this->assertStringNotContainsString('private-download-sentinel', $output);
        $this->assertStringNotContainsString('11111111-1111-4111-8111-111111111111', $output);
    }

    public function test_command_returns_generic_failure_without_provider_details(): void
    {
        Http::fake([
            'https://api.kinescope.io/*' => Http::response([
                'error' => [
                    'code' => 500500,
                    'message' => 'provider secret sentinel',
                ],
            ], 500),
        ]);

        $exitCode = Artisan::call('kinescope:inspect-video', ['embedLocator' => 'video-123']);
        $output = Artisan::output();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Не удалось безопасно проверить видео Kinescope.', $output);
        $this->assertStringNotContainsString('provider secret sentinel', $output);
    }
}
