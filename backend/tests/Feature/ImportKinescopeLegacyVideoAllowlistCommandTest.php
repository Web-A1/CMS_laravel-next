<?php

namespace Tests\Feature;

use App\Models\KinescopeLegacyVideoAllowlistBatch;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ImportKinescopeLegacyVideoAllowlistCommandTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, string> */
    private array $temporaryFiles = [];

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.kinescope.api_base_url', 'https://api.kinescope.io');
        config()->set('services.kinescope.api_token', '11111111-1111-4111-8111-111111111111');
        config()->set('services.kinescope.project_id', 'school-project');

        Http::fake(function (Request $request) {
            $locator = basename((string) parse_url($request->url(), PHP_URL_PATH));

            return Http::response(['data' => [
                'id' => "provider-{$locator}",
                'project_id' => 'school-project',
                'status' => 'done',
                'embed_link' => "https://kinescope.io/embed/{$locator}",
            ]]);
        });
    }

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $temporaryFile) {
            @unlink($temporaryFile);
        }

        parent::tearDown();
    }

    public function test_default_expected_count_matches_verified_legacy_snapshot(): void
    {
        $command = $this->app->make(Kernel::class)
            ->all()['kinescope:legacy-video-allowlist'];

        $this->assertSame(
            '544',
            $command->getDefinition()->getOption('expected-count')->getDefault(),
        );
    }

    public function test_import_is_dry_run_by_default_and_never_prints_video_ids(): void
    {
        $input = $this->inputFile([
            "https://kinescope.io/embed/legacy-video-a\tprovider-legacy-video-a",
            "https://kinescope.io/embed/legacy-video-b\tprovider-legacy-video-b",
        ]);

        $this->artisan('kinescope:legacy-video-allowlist', [
            'input' => $input,
            '--expected-count' => 2,
        ])
            ->expectsOutputToContain('mode=dry-run count=2 sha256=')
            ->doesntExpectOutputToContain('legacy-video-a')
            ->assertSuccessful();

        $this->assertDatabaseCount('kinescope_legacy_video_allowlist_batches', 0);
        Http::assertSentCount(2);
    }

    public function test_import_activation_and_rollback_are_explicit_and_atomic(): void
    {
        $firstInput = $this->inputFile([
            "https://kinescope.io/embed/legacy-video-a\tprovider-legacy-video-a",
            "https://kinescope.io/embed/legacy-video-b\tprovider-legacy-video-b",
        ]);
        $secondInput = $this->inputFile([
            "https://kinescope.io/embed/legacy-video-b\tprovider-legacy-video-b",
            "https://kinescope.io/embed/legacy-video-c\tprovider-legacy-video-c",
        ]);

        $this->artisan('kinescope:legacy-video-allowlist', [
            'input' => $firstInput,
            '--expected-count' => 2,
            '--apply' => true,
        ])->assertSuccessful();
        $firstBatch = KinescopeLegacyVideoAllowlistBatch::query()->sole();
        $this->assertFalse($firstBatch->is_active);

        $this->artisan('kinescope:legacy-video-allowlist', [
            '--activate' => $firstBatch->source_sha256,
            '--apply' => true,
        ])->assertSuccessful();
        $this->assertTrue($firstBatch->fresh()->is_active);

        $this->artisan('kinescope:legacy-video-allowlist', [
            'input' => $secondInput,
            '--expected-count' => 2,
            '--apply' => true,
        ])->assertSuccessful();
        $secondBatch = KinescopeLegacyVideoAllowlistBatch::query()
            ->where('id', '!=', $firstBatch->id)
            ->sole();

        $this->artisan('kinescope:legacy-video-allowlist', [
            '--activate' => $secondBatch->source_sha256,
            '--apply' => true,
        ])->assertSuccessful();
        $this->assertFalse($firstBatch->fresh()->is_active);
        $this->assertTrue($secondBatch->fresh()->is_active);

        $this->artisan('kinescope:legacy-video-allowlist', [
            '--activate' => $firstBatch->source_sha256,
            '--apply' => true,
        ])->assertSuccessful();
        $this->assertTrue($firstBatch->fresh()->is_active);
        $this->assertFalse($secondBatch->fresh()->is_active);
    }

    public function test_import_rejects_count_drift_noncanonical_urls_and_unsafe_files(): void
    {
        $countDrift = $this->inputFile([
            "https://kinescope.io/embed/legacy-video-a\tprovider-legacy-video-a",
        ]);
        $invalidUrl = $this->inputFile([
            "https://evil.example/embed/legacy-video-a\tprovider-legacy-video-a",
        ]);
        $unsafePermissions = $this->inputFile([
            "https://kinescope.io/embed/legacy-video-a\tprovider-legacy-video-a",
        ], 0644);

        $this->artisan('kinescope:legacy-video-allowlist', [
            'input' => $countDrift,
            '--expected-count' => 2,
        ])->assertFailed();
        $this->artisan('kinescope:legacy-video-allowlist', [
            'input' => $invalidUrl,
            '--expected-count' => 1,
        ])->assertFailed();
        $this->artisan('kinescope:legacy-video-allowlist', [
            'input' => $unsafePermissions,
            '--expected-count' => 1,
        ])->assertFailed();
    }

    public function test_import_rejects_duplicate_embed_locator_even_with_different_provider_ids(): void
    {
        $duplicateEmbedLocator = $this->inputFile([
            "https://kinescope.io/embed/legacy-video-a\tprovider-legacy-video-a",
            "https://kinescope.io/embed/legacy-video-a\tanother-provider-id",
        ]);

        $this->artisan('kinescope:legacy-video-allowlist', [
            'input' => $duplicateEmbedLocator,
            '--expected-count' => 1,
            '--apply' => true,
        ])->assertFailed();

        $this->assertDatabaseCount('kinescope_legacy_video_allowlist_batches', 0);
    }

    public function test_import_rejects_unverified_pairs(): void
    {
        $wrongProviderId = $this->inputFile([
            "https://kinescope.io/embed/legacy-video-a\twrong-provider",
        ]);

        $this->artisan('kinescope:legacy-video-allowlist', [
            'input' => $wrongProviderId,
            '--expected-count' => 1,
        ])->assertFailed();
    }

    public function test_import_accepts_shared_provider_video_id_for_distinct_embed_locators(): void
    {
        Http::swap(new Factory);
        Http::fake(function (Request $request) {
            $locator = basename((string) parse_url($request->url(), PHP_URL_PATH));

            return Http::response(['data' => [
                'id' => 'shared-provider-id',
                'project_id' => 'school-project',
                'status' => 'done',
                'embed_link' => "https://kinescope.io/embed/{$locator}",
            ]]);
        });
        $manyToOne = $this->inputFile([
            "https://kinescope.io/embed/legacy-video-a\tshared-provider-id",
            "https://kinescope.io/embed/legacy-video-b\tshared-provider-id",
        ]);

        $this->artisan('kinescope:legacy-video-allowlist', [
            'input' => $manyToOne,
            '--expected-count' => 2,
            '--apply' => true,
        ])->assertSuccessful();

        $batch = KinescopeLegacyVideoAllowlistBatch::query()->sole();
        $this->assertSame(2, $batch->source_count);
        $this->assertDatabaseHas('kinescope_legacy_video_allowlist_entries', [
            'batch_id' => $batch->id,
            'embed_locator' => 'legacy-video-a',
            'provider_video_id' => 'shared-provider-id',
        ]);
        $this->assertDatabaseHas('kinescope_legacy_video_allowlist_entries', [
            'batch_id' => $batch->id,
            'embed_locator' => 'legacy-video-b',
            'provider_video_id' => 'shared-provider-id',
        ]);
    }

    public function test_import_rejects_provider_failure_and_project_mismatch(): void
    {
        $input = $this->inputFile([
            "https://kinescope.io/embed/legacy-video-a\tprovider-legacy-video-a",
        ]);

        Http::swap(new Factory);
        Http::fake(['https://api.kinescope.io/*' => Http::response([], 503)]);
        $this->artisan('kinescope:legacy-video-allowlist', [
            'input' => $input,
            '--expected-count' => 1,
        ])->assertFailed();

        Http::swap(new Factory);
        Http::fake(['https://api.kinescope.io/*' => Http::response(['data' => [
            'id' => 'provider-legacy-video-a',
            'project_id' => 'another-project',
            'status' => 'done',
            'embed_link' => 'https://kinescope.io/embed/legacy-video-a',
        ]])]);
        $this->artisan('kinescope:legacy-video-allowlist', [
            'input' => $input,
            '--expected-count' => 1,
        ])->assertFailed();
    }

    public function test_import_accepts_verified_identity_even_when_legacy_video_is_not_ready(): void
    {
        Http::swap(new Factory);
        Http::fake(['https://api.kinescope.io/*' => Http::response(['data' => [
            'id' => 'provider-legacy-video-error',
            'project_id' => 'school-project',
            'status' => 'error',
            'embed_link' => 'https://kinescope.io/embed/legacy-video-error',
        ]])]);
        $input = $this->inputFile([
            "https://kinescope.io/embed/legacy-video-error\tprovider-legacy-video-error",
        ]);

        $this->artisan('kinescope:legacy-video-allowlist', [
            'input' => $input,
            '--expected-count' => 1,
        ])->assertSuccessful();
    }

    /**
     * @param  array<int, string>  $lines
     */
    private function inputFile(array $lines, int $permissions = 0600): string
    {
        $path = tempnam(sys_get_temp_dir(), 'kinescope-allowlist-');
        $this->assertIsString($path);
        file_put_contents($path, implode(PHP_EOL, $lines).PHP_EOL);
        chmod($path, $permissions);
        $this->temporaryFiles[] = $path;

        return $path;
    }
}
