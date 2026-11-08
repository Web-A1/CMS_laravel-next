<?php

namespace Tests\Unit;

use App\Exceptions\KinescopeApiUnavailableException;
use App\Services\Kinescope\KinescopeApiClient;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class KinescopeApiClientTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.kinescope.api_base_url', 'https://api.kinescope.io');
        config()->set('services.kinescope.api_token', '11111111-1111-4111-8111-111111111111');
        config()->set('services.kinescope.project_id', 'project-123');
    }

    public function test_it_separates_canonical_video_id_from_embed_locator(): void
    {
        Http::fake([
            'https://api.kinescope.io/v1/videos/player-123' => Http::response([
                'data' => [
                    'id' => 'canonical-video-123',
                    'project_id' => 'project-123',
                    'status' => 'done',
                    'embed_link' => 'https://kinescope.io/embed/player-123',
                    'hls_link' => 'https://kinescope.io/private/master.m3u8',
                    'assets' => [[
                        'download_link' => 'https://private.invalid/secret-download',
                    ]],
                ],
            ], 200, ['X-Request-ID' => 'request-123']),
        ]);

        $inspection = app(KinescopeApiClient::class)->inspectReady('player-123');

        $this->assertSame('canonical-video-123', $inspection->videoId);
        $this->assertSame('player-123', $inspection->embedLocator);
        $this->assertSame('project-123', $inspection->projectId);
        $this->assertSame('done', $inspection->status);
        $this->assertSame('kinescope.io', $inspection->embedHost);
        $this->assertObjectNotHasProperty('assets', $inspection);
        $this->assertObjectNotHasProperty('hlsLink', $inspection);
        $this->assertObjectNotHasProperty('embedLink', $inspection);

        Http::assertSent(fn ($request): bool => $request->method() === 'GET'
            && $request->url() === 'https://api.kinescope.io/v1/videos/player-123'
            && $request->hasHeader('Authorization', 'Bearer 11111111-1111-4111-8111-111111111111'));
    }

    public function test_unavailable_or_invalid_provider_response_fails_closed(): void
    {
        foreach ([
            Http::response(['error' => ['code' => 500500]], 500),
            Http::response('{malformed-json', 200, ['Content-Type' => 'application/json']),
            Http::response(['data' => ['id' => 'another-video']], 200),
            Http::response([
                'data' => [
                    'id' => 'canonical-video-123',
                    'project_id' => 'another-project',
                    'status' => 'done',
                    'embed_link' => 'https://kinescope.io/embed/public-player-id',
                ],
            ]),
            Http::response([
                'data' => [
                    'id' => 'canonical-video-123',
                    'project_id' => 'project-123',
                    'status' => 'processing',
                    'embed_link' => 'https://kinescope.io/embed/public-player-id',
                ],
            ]),
            Http::response([
                'data' => [
                    'id' => 'canonical-video-123',
                    'project_id' => 'project-123',
                    'status' => 'done',
                    'embed_link' => 'https://attacker.invalid/embed/video-123',
                ],
            ]),
            Http::response([
                'data' => [
                    'id' => 'canonical-video-123',
                    'project_id' => 'project-123',
                    'status' => 'done',
                    'embed_link' => 'https://kinescope.io/embed/another-player',
                ],
            ]),
        ] as $response) {
            Http::fake(['https://api.kinescope.io/*' => $response]);

            try {
                app(KinescopeApiClient::class)->inspectReady('video-123');
                $this->fail('Invalid Kinescope response was accepted.');
            } catch (KinescopeApiUnavailableException $exception) {
                $this->assertSame('Kinescope video could not be verified.', $exception->getMessage());
            }
        }
    }

    public function test_invalid_local_configuration_makes_no_request(): void
    {
        Http::fake();
        config()->set('services.kinescope.api_token', 'not-a-uuid');

        $this->expectException(KinescopeApiUnavailableException::class);

        try {
            app(KinescopeApiClient::class)->inspectReady('video-123');
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_identity_resolution_accepts_a_verified_mapping_regardless_of_readiness(): void
    {
        Http::fake([
            'https://api.kinescope.io/v1/videos/player-error' => Http::response(['data' => [
                'id' => 'canonical-error',
                'project_id' => 'project-123',
                'status' => 'error',
                'embed_link' => 'https://kinescope.io/embed/player-error',
            ]]),
        ]);

        $inspection = app(KinescopeApiClient::class)->resolveIdentity('player-error');

        $this->assertSame('canonical-error', $inspection->videoId);
        $this->assertSame('player-error', $inspection->embedLocator);
        $this->assertSame('error', $inspection->status);

        $this->expectException(KinescopeApiUnavailableException::class);
        app(KinescopeApiClient::class)->inspectReady('player-error');
    }
}
