<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\KinescopeLegacyVideoAllowlistBatch;
use App\Models\KinescopeLegacyVideoAllowlistEntry;
use App\Models\LegacyImportMapping;
use App\Models\LegacyProtectedAssetMapping;
use App\Models\Lesson;
use App\Models\LessonLearningContent;
use App\Models\LessonMaterial;
use App\Models\LessonMaterialKinescopeVideo;
use App\Models\Subject;
use App\Models\User;
use App\Models\UserEntitlementGrant;
use App\Services\Kinescope\KinescopeApiClient;
use App\Services\Kinescope\KinescopeStagingParityFixtureService;
use App\Services\Learning\LegacyLessonBodyImporter;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class KinescopeStagingParityFixtureCommandTest extends TestCase
{
    use RefreshDatabase;

    private string $protectedDirectory;

    /** @var array{status:int, body:array<string, mixed>}|null */
    private ?array $providerResponseOverride = null;

    protected function setUp(): void
    {
        parent::setUp();

        $temporaryRoot = realpath(sys_get_temp_dir());
        $this->assertIsString($temporaryRoot);
        $this->protectedDirectory = $temporaryRoot.'/cms-parity-fixture-'.Str::lower(Str::random(16));
        File::makeDirectory($this->protectedDirectory, 0700, true);

        config()->set('services.kinescope.api_base_url', 'https://api.kinescope.io');
        config()->set('services.kinescope.api_token', '11111111-1111-4111-8111-111111111111');
        config()->set('services.kinescope.project_id', 'school-project');
        config()->set('services.kinescope.enabled', true);
        config()->set('services.kinescope.embed_base_url', 'https://kinescope.io/embed');
        config()->set('services.kinescope.playback_secret', str_repeat('k', 64));
        config()->set('services.kinescope.playback_issuer', 'cms-laravel-next');
        config()->set('services.kinescope.playback_audience', 'kinescope-player');
        config()->set('services.kinescope.playback_ttl_seconds', 300);

        Http::fake(function (Request $request) {
            if ($this->providerResponseOverride !== null) {
                return Http::response(
                    $this->providerResponseOverride['body'],
                    $this->providerResponseOverride['status'],
                );
            }

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
        File::deleteDirectory($this->protectedDirectory);

        parent::tearDown();
    }

    public function test_command_is_discovered_without_registration_changes(): void
    {
        $commands = $this->app->make(Kernel::class)->all();

        $this->assertArrayHasKey('kinescope:staging-parity-fixture', $commands);
    }

    public function test_create_is_redacted_zero_write_dry_run_by_default(): void
    {
        [$lesson, $user] = $this->createTarget();
        $this->createActiveAllowlist();
        $manifest = $this->writeCreateManifest($lesson, $user);
        $filesBefore = $this->protectedDirectoryEntries();

        [$exitCode, $output] = $this->runFixture(['manifest' => $manifest]);

        $this->assertSame(0, $exitCode, $output);
        $this->assertStringContainsString('mode=create-dry-run status=ready', $output);
        $this->assertMatchesRegularExpression('/manifest_sha256=[0-9a-f]{64}/', $output);
        $this->assertMatchesRegularExpression('/plan_sha256=[0-9a-f]{64}/', $output);
        $this->assertStringContainsString('insert_count=6', $output);
        $this->assertSensitiveValuesAreAbsent($output, $manifest);
        $this->assertFixtureRows(0);
        $this->assertSame($filesBefore, $this->protectedDirectoryEntries());
        Http::assertSentCount(1);
    }

    public function test_create_apply_requires_both_fresh_hash_confirmations(): void
    {
        [$lesson, $user] = $this->createTarget();
        $this->createActiveAllowlist();
        $manifest = $this->writeCreateManifest($lesson, $user);
        [$dryRunExit, $dryRunOutput] = $this->runFixture(['manifest' => $manifest]);
        $this->assertSame(0, $dryRunExit);
        $manifestHash = hash_file('sha256', $manifest);
        $this->assertIsString($manifestHash);
        $planHash = $this->planHash($dryRunOutput);
        $rollbackOutput = $this->protectedDirectory.'/rollback.json';

        foreach ([
            [],
            ['--confirm-manifest-sha256' => $manifestHash],
            ['--confirm-plan-sha256' => $planHash],
            [
                '--confirm-manifest-sha256' => str_repeat('0', 64),
                '--confirm-plan-sha256' => $planHash,
            ],
        ] as $confirmations) {
            [$exitCode, $output] = $this->runFixture([
                'manifest' => $manifest,
                '--apply' => true,
                '--rollback-output' => $rollbackOutput,
                ...$confirmations,
            ]);

            $this->assertSame(1, $exitCode);
            $this->assertSame("code=operation_rejected\n", $output);
            $this->assertFixtureRows(0);
            $this->assertFileDoesNotExist($rollbackOutput);
        }

        $unsafeOutputDirectory = $this->protectedDirectory.'/unsafe-output';
        File::makeDirectory($unsafeOutputDirectory, 0755);
        $this->assertTrue(chmod($unsafeOutputDirectory, 0755));
        [$unsafeOutputExit, $unsafeOutput] = $this->runFixture([
            'manifest' => $manifest,
            '--apply' => true,
            '--rollback-output' => $unsafeOutputDirectory.'/rollback.json',
            '--confirm-manifest-sha256' => $manifestHash,
            '--confirm-plan-sha256' => $planHash,
        ]);
        $this->assertSame(1, $unsafeOutputExit);
        $this->assertSame("code=operation_rejected\n", $unsafeOutput);
        $this->assertFixtureRows(0);
    }

    public function test_confirmed_create_and_rollback_are_atomic_owned_and_idempotent(): void
    {
        [$lesson, $user] = $this->createTarget();
        [$batch] = $this->createActiveAllowlist();
        $manifest = $this->writeCreateManifest($lesson, $user);
        [$dryRunExit, $dryRunOutput] = $this->runFixture(['manifest' => $manifest]);
        $this->assertSame(0, $dryRunExit);
        $rollbackOutput = $this->protectedDirectory.'/rollback.json';

        [$applyExit, $applyOutput] = $this->runFixture([
            'manifest' => $manifest,
            '--apply' => true,
            '--rollback-output' => $rollbackOutput,
            '--confirm-manifest-sha256' => hash_file('sha256', $manifest),
            '--confirm-plan-sha256' => $this->planHash($dryRunOutput),
        ]);

        $this->assertSame(0, $applyExit);
        $this->assertSafeOutputShape($applyOutput, 'create-apply', 'applied', 'insert_count', 6);
        $this->assertSensitiveValuesAreAbsent($applyOutput, $manifest);
        $this->assertFixtureRows(1);
        $this->assertSame(1, Subject::query()->count());
        $this->assertSame(1, Course::query()->count());
        $this->assertSame(1, Lesson::query()->count());
        $this->assertSame(1, User::query()->count());
        $this->assertSame(1, KinescopeLegacyVideoAllowlistBatch::query()->count());
        $this->assertTrue($batch->fresh()->is_active);

        $content = LessonLearningContent::query()->sole();
        $legacyImport = LegacyImportMapping::query()->sole();
        $material = LessonMaterial::query()->sole();
        $video = LessonMaterialKinescopeVideo::query()->sole();
        $mapping = LegacyProtectedAssetMapping::query()->sole();
        $grant = UserEntitlementGrant::query()->sole();
        $this->assertSame(LessonLearningContent::IMPORT_STATUS_IMPORTED, $content->import_status);
        $this->assertSame(LegacyLessonBodyImporter::TARGET_DOMAIN, $legacyImport->target_domain);
        $this->assertTrue($material->is_published);
        $this->assertSame($material->id, $video->lesson_material_id);
        $this->assertSame($material->id, $mapping->internal_asset_id);
        $this->assertSame(UserEntitlementGrant::SOURCE_TEST_FIXTURE, $grant->source_type);
        $this->assertTrue($grant->expires_at->isFuture());
        $this->assertTrue($grant->expires_at->lessThanOrEqualTo(now()->addHours(2)));

        foreach ([$content, $legacyImport, $mapping, $grant] as $ownedRow) {
            $this->assertSame('kinescope_staging_parity_v1', data_get($ownedRow->metadata, 'fixture_owner'));
            $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', data_get($ownedRow->metadata, 'fixture_key_sha256'));
            $this->assertSame($this->planHash($dryRunOutput), data_get($ownedRow->metadata, 'plan_sha256'));
        }

        $playbackUrl = sprintf(
            '/api/v1/learning/subjects/%s/courses/%s/lessons/%s/video-playback',
            $lesson->course->subject->slug,
            $lesson->course->slug,
            $lesson->slug,
        );
        Sanctum::actingAs($user);
        $this->withHeader('Referer', 'http://localhost:3000/')
            ->withSession(['auth_marker' => 'staging-parity-fixture'])
            ->postJson($playbackUrl, ['materialId' => $material->id])
            ->assertOk()
            ->assertJsonStructure(['data' => ['embedUrl', 'expiresAt']]);
        $userWithoutGrant = User::factory()->create(['role' => 'user_no_purchase']);
        Sanctum::actingAs($userWithoutGrant);
        $this->postJson($playbackUrl, ['materialId' => $material->id])
            ->assertForbidden();

        $this->assertFileExists($rollbackOutput);
        $this->assertSame(0600, fileperms($rollbackOutput) & 0777);
        $rollback = json_decode((string) file_get_contents($rollbackOutput), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('rollback', $rollback['operation']);
        $this->assertSame('applied', $rollback['state']);
        $this->assertCount(6, $rollback['rows']);
        $this->assertSame([
            'lesson_learning_contents' => 0,
            'legacy_import_lineage' => 0,
            'lesson_materials' => 0,
            'kinescope_identity_conflicts' => 0,
            'lesson_protected_mappings' => 0,
            'effective_lesson_grants' => 0,
        ], $rollback['preexisting_row_counts']);
        foreach ($rollback['ownership_metadata_sha256'] as $metadataHash) {
            $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $metadataHash);
        }

        [$rollbackDryExit, $rollbackDryOutput] = $this->runFixture([
            'manifest' => $rollbackOutput,
            '--rollback' => true,
        ]);
        $this->assertSame(0, $rollbackDryExit);
        $this->assertSafeOutputShape($rollbackDryOutput, 'rollback-dry-run', 'ready', 'delete_count', 6);
        $this->assertFixtureRows(1);

        $rollbackManifestHash = hash_file('sha256', $rollbackOutput);
        $rollbackPlanHash = $this->planHash($rollbackDryOutput);
        foreach ([
            [],
            ['--confirm-manifest-sha256' => $rollbackManifestHash],
            ['--confirm-plan-sha256' => $rollbackPlanHash],
        ] as $confirmations) {
            [$missingExit, $missingOutput] = $this->runFixture([
                'manifest' => $rollbackOutput,
                '--rollback' => true,
                '--apply' => true,
                ...$confirmations,
            ]);
            $this->assertSame(1, $missingExit);
            $this->assertSame("code=operation_rejected\n", $missingOutput);
            $this->assertFixtureRows(1);
        }

        [$rollbackExit, $rollbackApplyOutput] = $this->runFixture([
            'manifest' => $rollbackOutput,
            '--rollback' => true,
            '--apply' => true,
            '--confirm-manifest-sha256' => $rollbackManifestHash,
            '--confirm-plan-sha256' => $rollbackPlanHash,
        ]);
        $this->assertSame(0, $rollbackExit);
        $this->assertSafeOutputShape($rollbackApplyOutput, 'rollback-apply', 'rolled_back', 'delete_count', 6);
        $this->assertFixtureRows(0);
        $this->assertSame(1, Lesson::query()->count());
        $this->assertSame(2, User::query()->count());
        $this->assertSame(1, KinescopeLegacyVideoAllowlistBatch::query()->count());

        [$alreadyExit, $alreadyOutput] = $this->runFixture([
            'manifest' => $rollbackOutput,
            '--rollback' => true,
        ]);
        $this->assertSame(0, $alreadyExit);
        $this->assertSafeOutputShape($alreadyOutput, 'rollback-dry-run', 'already_rolled_back', 'delete_count', 0);

        [$wrongIdempotentExit, $wrongIdempotentOutput] = $this->runFixture([
            'manifest' => $rollbackOutput,
            '--rollback' => true,
            '--apply' => true,
            '--confirm-manifest-sha256' => hash_file('sha256', $rollbackOutput),
            '--confirm-plan-sha256' => str_repeat('0', 64),
        ]);
        $this->assertSame(1, $wrongIdempotentExit);
        $this->assertSame("code=operation_rejected\n", $wrongIdempotentOutput);

        [$idempotentExit, $idempotentOutput] = $this->runFixture([
            'manifest' => $rollbackOutput,
            '--rollback' => true,
            '--apply' => true,
            '--confirm-manifest-sha256' => hash_file('sha256', $rollbackOutput),
            '--confirm-plan-sha256' => $this->planHash($alreadyOutput),
        ]);
        $this->assertSame(0, $idempotentExit);
        $this->assertSafeOutputShape($idempotentOutput, 'rollback-apply', 'already_rolled_back', 'delete_count', 0);
    }

    public function test_invalid_manifest_file_schema_and_runtime_guards_fail_closed_without_leakage(): void
    {
        [$lesson, $user] = $this->createTarget();
        $this->createActiveAllowlist();
        $validManifest = $this->manifestData($lesson, $user);
        $cases = [
            ['payload' => [...$validManifest, 'unknown' => true]],
            ['payload' => [...$validManifest, 'environment' => 'production']],
            ['payload' => [...$validManifest, 'expires_at' => now()->subMinute()->toIso8601ZuluString()]],
            ['raw' => '{"schema_version":1,"schema_version":1}'],
        ];

        foreach ($cases as $index => $case) {
            $manifest = $this->protectedDirectory."/invalid-{$index}.json";
            file_put_contents(
                $manifest,
                $case['raw'] ?? json_encode($case['payload'], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            );
            chmod($manifest, 0600);

            [$exitCode, $output] = $this->runFixture(['manifest' => $manifest]);

            $this->assertSame(1, $exitCode);
            $this->assertSame("code=operation_rejected\n", $output);
            $this->assertFixtureRows(0);
        }

        chmod($this->writeCreateManifest($lesson, $user, 'unsafe.json'), 0644);
        [$unsafeExit, $unsafeOutput] = $this->runFixture([
            'manifest' => $this->protectedDirectory.'/unsafe.json',
        ]);
        $this->assertSame(1, $unsafeExit);
        $this->assertSame("code=operation_rejected\n", $unsafeOutput);
        $this->assertFixtureRows(0);

        [$relativeExit, $relativeOutput] = $this->runFixture(['manifest' => 'create.json']);
        $this->assertSame(1, $relativeExit);
        $this->assertSame("code=operation_rejected\n", $relativeOutput);

        $symlink = $this->protectedDirectory.'/manifest-link.json';
        symlink($validManifest = $this->writeCreateManifest($lesson, $user, 'linked-target.json'), $symlink);
        [$symlinkExit, $symlinkOutput] = $this->runFixture(['manifest' => $symlink]);
        $this->assertSame(1, $symlinkExit);
        $this->assertSame("code=operation_rejected\n", $symlinkOutput);

        $nonCanonical = dirname($this->protectedDirectory).'/'.basename($this->protectedDirectory).'/../'.basename($this->protectedDirectory).'/linked-target.json';
        [$nonCanonicalExit, $nonCanonicalOutput] = $this->runFixture(['manifest' => $nonCanonical]);
        $this->assertSame(1, $nonCanonicalExit);
        $this->assertSame("code=operation_rejected\n", $nonCanonicalOutput);

        $oversized = $this->protectedDirectory.'/oversized.json';
        file_put_contents($oversized, str_repeat('x', 1_048_577));
        chmod($oversized, 0600);
        [$oversizedExit, $oversizedOutput] = $this->runFixture(['manifest' => $oversized]);
        $this->assertSame(1, $oversizedExit);
        $this->assertSame("code=operation_rejected\n", $oversizedOutput);

        $deeplyNested = $this->protectedDirectory.'/deeply-nested.json';
        file_put_contents($deeplyNested, str_repeat('[', 66).str_repeat(']', 66));
        chmod($deeplyNested, 0600);
        [$nestedExit, $nestedOutput] = $this->runFixture(['manifest' => $deeplyNested]);
        $this->assertSame(1, $nestedExit);
        $this->assertSame("code=operation_rejected\n", $nestedOutput);

        chmod($this->protectedDirectory, 0755);
        [$directoryExit, $directoryOutput] = $this->runFixture(['manifest' => $validManifest]);
        chmod($this->protectedDirectory, 0700);
        $this->assertSame(1, $directoryExit);
        $this->assertSame("code=operation_rejected\n", $directoryOutput);

        $credentialLike = $this->manifestData($lesson, $user);
        $credentialLike['kinescope']['api_token'] = 'must-never-be-accepted';
        $credentialManifest = $this->writeManifestData($credentialLike, 'credential-like.json');
        [$credentialExit, $credentialOutput] = $this->runFixture(['manifest' => $credentialManifest]);
        $this->assertSame(1, $credentialExit);
        $this->assertSame("code=operation_rejected\n", $credentialOutput);
        $this->assertFixtureRows(0);
    }

    public function test_legacy_preview_and_protected_reference_guards_fail_closed(): void
    {
        [$lesson, $user] = $this->createTarget();
        $this->createActiveAllowlist();

        $cases = [];

        $scopeMismatch = $this->manifestData($lesson, $user);
        $scopeMismatch['legacy']['expected_source_scope_id'] = '9002';
        $cases['scope-mismatch'] = $scopeMismatch;

        $fingerprintMismatch = $this->manifestData($lesson, $user);
        $fingerprintMismatch['legacy']['source_fingerprint_sha256'] = str_repeat('0', 64);
        $cases['fingerprint-mismatch'] = $fingerprintMismatch;

        foreach ([
            'zero-reference' => '<p>No protected reference.</p>',
            'multiple-references' => '{video::880055}{video::880056}',
            'unsupported-reference' => '{file::880055}',
        ] as $name => $content) {
            $manifest = $this->manifestData($lesson, $user);
            $manifest['legacy']['source']['content'] = $content;
            $cases[$name] = $manifest;
        }

        $manualReview = $this->manifestData($lesson, $user);
        $manualReview['legacy']['source']['product_id'] = 9002;
        $manualReview['legacy']['expected_source_scope_id'] = '9002';
        $manualReview['legacy']['source_fingerprint_sha256'] = $this->sourceFingerprint($manualReview);
        $cases['manual-review'] = $manualReview;

        foreach ($cases as $name => $manifest) {
            $this->assertRejectedWithoutChanges($this->writeManifestData($manifest, "legacy-{$name}.json"));
        }
    }

    public function test_allowlist_guards_reject_missing_ambiguous_incomplete_and_mismatched_state(): void
    {
        [$lesson, $user] = $this->createTarget();
        $manifest = $this->writeCreateManifest($lesson, $user, 'allowlist-zero.json');
        $this->assertRejectedWithoutChanges($manifest);

        [$batch] = $this->createActiveAllowlist();
        $secondBatch = KinescopeLegacyVideoAllowlistBatch::query()->create([
            'source_count' => 0,
            'source_sha256' => hash('sha256', ''),
            'is_active' => true,
            'activated_at' => now(),
        ]);
        $this->assertRejectedWithoutChanges($this->writeCreateManifest($lesson, $user, 'allowlist-many.json'));
        $secondBatch->delete();

        $batch->update(['source_count' => 2]);
        $this->assertRejectedWithoutChanges($this->writeCreateManifest($lesson, $user, 'allowlist-incomplete.json'));
        $batch->update(['source_count' => 1]);

        $batch->update(['source_sha256' => str_repeat('0', 64)]);
        $this->assertRejectedWithoutChanges($this->writeCreateManifest($lesson, $user, 'allowlist-hash.json'));
        $batch->update(['source_sha256' => hash('sha256', "legacy-video-a\tprovider-legacy-video-a\n")]);

        $batch->update(['activated_at' => null]);
        $this->assertRejectedWithoutChanges($this->writeCreateManifest($lesson, $user, 'allowlist-activation.json'));
        $batch->update(['activated_at' => now()]);

        $pairMismatch = $this->manifestData($lesson, $user);
        $pairMismatch['kinescope']['embed_locator'] = 'legacy-video-b';
        $pairMismatch['kinescope']['provider_video_id'] = 'provider-legacy-video-b';
        $pairMismatch['kinescope']['pair_sha256'] = hash('sha256', "legacy-video-b\tprovider-legacy-video-b\n");
        $this->assertRejectedWithoutChanges($this->writeManifestData($pairMismatch, 'allowlist-pair.json'));
    }

    public function test_provider_configuration_and_identity_failures_are_redacted_and_read_only(): void
    {
        [$lesson, $user] = $this->createTarget();
        $this->createActiveAllowlist();
        $manifest = $this->writeCreateManifest($lesson, $user, 'provider.json');

        config()->set('services.kinescope.api_token', 'invalid');
        $this->assertRejectedWithoutChanges($manifest);
        config()->set('services.kinescope.api_token', '11111111-1111-4111-8111-111111111111');

        foreach ([
            'unavailable' => ['status' => 503, 'body' => []],
            'project-mismatch' => ['status' => 200, 'body' => ['data' => $this->providerData(projectId: 'other-project')]],
            'status-not-done' => ['status' => 200, 'body' => ['data' => $this->providerData(status: 'error')]],
            'provider-mismatch' => ['status' => 200, 'body' => ['data' => $this->providerData(videoId: 'provider-mismatch')]],
            'locator-mismatch' => ['status' => 200, 'body' => ['data' => $this->providerData(embedLocator: 'locator-mismatch')]],
        ] as $name => $response) {
            $this->providerResponseOverride = $response;
            $this->assertRejectedWithoutChanges($manifest, $name);
        }
    }

    public function test_target_hierarchy_user_and_isolation_guards_reject_unsafe_targets(): void
    {
        [$lesson, $user] = $this->createTarget();
        $this->createActiveAllowlist();

        $lesson->update(['is_active' => false]);
        $this->assertRejectedWithoutChanges($this->writeCreateManifest($lesson, $user, 'lesson-inactive.json'));
        $lesson->update(['is_active' => true]);

        $lesson->course->update(['is_active' => false]);
        $this->assertRejectedWithoutChanges($this->writeCreateManifest($lesson->fresh(), $user, 'course-inactive.json'));
        $lesson->course->update(['is_active' => true]);

        $lesson->course->subject->update(['is_active' => false]);
        $this->assertRejectedWithoutChanges($this->writeCreateManifest($lesson->fresh(), $user, 'subject-inactive.json'));
        $lesson->course->subject->update(['is_active' => true]);

        $material = LessonMaterial::query()->create([
            'lesson_id' => $lesson->id,
            'kind' => LessonMaterial::KIND_NOTES,
            'title' => 'Existing material',
            'is_published' => true,
        ]);
        $this->assertRejectedWithoutChanges($this->writeCreateManifest($lesson->fresh(), $user, 'lesson-nonempty.json'));
        $material->delete();

        $grant = UserEntitlementGrant::query()->create([
            'user_id' => $user->id,
            'atom_kind' => UserEntitlementGrant::ATOM_LESSON,
            'atom_id' => $lesson->id,
            'status' => UserEntitlementGrant::STATUS_ACTIVE,
            'source_type' => UserEntitlementGrant::SOURCE_TEST_FIXTURE,
            'source_id' => 'pre-existing-grant',
            'idempotency_key' => 'pre-existing-grant',
            'granted_at' => now(),
            'expires_at' => now()->addHour(),
        ]);
        $this->assertRejectedWithoutChanges($this->writeCreateManifest($lesson->fresh(), $user, 'grant-existing.json'));
        $grant->delete();

        foreach (['admin', 'admin_full', 'admin_limited', 'manager', 'teacher'] as $staffRole) {
            $user->update(['role' => $staffRole]);
            $this->assertRejectedWithoutChanges(
                $this->writeCreateManifest($lesson->fresh(), $user, "staff-{$staffRole}.json"),
            );
        }
        $user->update(['role' => 'user_no_purchase']);

        $missingUser = $this->manifestData($lesson->fresh(), $user->fresh());
        $missingUser['staging']['user_id'] = 999_999;
        $this->assertRejectedWithoutChanges($this->writeManifestData($missingUser, 'missing-user.json'));
    }

    public function test_existing_legacy_lineage_and_kinescope_identities_are_rejected(): void
    {
        [$lesson, $user] = $this->createTarget();
        $this->createActiveAllowlist();
        $manifestData = $this->manifestData($lesson, $user);
        $preview = $this->app->make(LegacyLessonBodyImporter::class)
            ->previewPlatformPage($manifestData['legacy']['source']);
        LegacyImportMapping::query()->create([
            'batch_key' => 'existing-lineage',
            'source_table' => $preview['source_table'],
            'source_id' => $preview['source_id'],
            'source_scope_type' => $preview['source_scope_type'],
            'source_scope_id' => $preview['source_scope_id'],
            'target_domain' => LegacyLessonBodyImporter::TARGET_DOMAIN,
            'target_type' => LegacyLessonBodyImporter::TARGET_TYPE,
            'target_id' => null,
            'mapping_status' => LegacyImportMapping::MAPPING_MAPPED,
            'validation_status' => LegacyImportMapping::VALIDATION_PASSED,
            'idempotency_key' => $preview['idempotency_key'],
            'source_fingerprint_hash' => $preview['source_fingerprint_hash'],
        ]);
        $this->assertRejectedWithoutChanges($this->writeManifestData($manifestData, 'duplicate-lineage.json'));
        LegacyImportMapping::query()->delete();

        $otherLesson = Lesson::query()->create([
            'course_id' => $lesson->course_id,
            'slug' => 'fixture-lesson-other',
            'title' => 'Fixture lesson other',
            'is_active' => true,
        ]);
        $otherMaterial = LessonMaterial::query()->create([
            'lesson_id' => $otherLesson->id,
            'kind' => LessonMaterial::KIND_VIDEO,
            'title' => 'Existing video',
            'is_published' => true,
        ]);
        $video = LessonMaterialKinescopeVideo::query()->create([
            'lesson_material_id' => $otherMaterial->id,
            'embed_locator' => 'legacy-video-a',
            'provider_video_id' => 'provider-other',
        ]);
        $this->assertRejectedWithoutChanges($this->writeManifestData($manifestData, 'duplicate-locator.json'));
        $video->update([
            'embed_locator' => 'other-locator',
            'provider_video_id' => 'provider-legacy-video-a',
        ]);
        $this->assertRejectedWithoutChanges($this->writeManifestData($manifestData, 'duplicate-provider.json'));
    }

    #[DataProvider('transactionCheckpointProvider')]
    public function test_injected_transaction_failures_roll_back_every_fixture_row(string $checkpoint): void
    {
        [$lesson, $user] = $this->createTarget();
        $this->createActiveAllowlist();
        $manifest = $this->writeCreateManifest($lesson, $user, "failure-{$checkpoint}.json");
        [, $dryRunOutput] = $this->runFixture(['manifest' => $manifest]);
        $rollbackOutput = $this->protectedDirectory."/rollback-{$checkpoint}.json";
        $this->bindCheckpointFailure($checkpoint);

        [$exitCode, $output] = $this->runFixture([
            'manifest' => $manifest,
            '--apply' => true,
            '--rollback-output' => $rollbackOutput,
            '--confirm-manifest-sha256' => hash_file('sha256', $manifest),
            '--confirm-plan-sha256' => $this->planHash($dryRunOutput),
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertSame("code=operation_rejected\n", $output);
        $this->assertFixtureRows(0);
        $this->assertFileDoesNotExist($rollbackOutput);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function transactionCheckpointProvider(): array
    {
        return [
            'after_import' => ['after_import'],
            'after_material' => ['after_material'],
            'after_video' => ['after_video'],
            'after_protected_mapping' => ['after_protected_mapping'],
            'after_grant' => ['after_grant'],
            'after_publish' => ['after_publish'],
            'after_rollback_manifest_prepared' => ['after_rollback_manifest_prepared'],
        ];
    }

    public function test_concurrent_foreign_rollback_output_is_never_overwritten_or_deleted(): void
    {
        [$lesson, $user] = $this->createTarget();
        $this->createActiveAllowlist();
        $manifest = $this->writeCreateManifest($lesson, $user, 'foreign-output-race.json');
        [, $dryRunOutput] = $this->runFixture(['manifest' => $manifest]);
        $rollbackOutput = $this->protectedDirectory.'/foreign-output.json';
        $foreignContents = "{\"foreign\":true}\n";
        $this->bindForeignOutputRace($foreignContents);

        [$exitCode, $output] = $this->runFixture([
            'manifest' => $manifest,
            '--apply' => true,
            '--rollback-output' => $rollbackOutput,
            '--confirm-manifest-sha256' => hash_file('sha256', $manifest),
            '--confirm-plan-sha256' => $this->planHash($dryRunOutput),
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertSame("code=operation_rejected\n", $output);
        $this->assertFixtureRows(0);
        $this->assertFileExists($rollbackOutput);
        $this->assertSame($foreignContents, file_get_contents($rollbackOutput));
    }

    public function test_ambiguous_post_commit_failure_preserves_prepared_recovery_manifest(): void
    {
        [$lesson, $user] = $this->createTarget();
        $this->createActiveAllowlist();
        $manifest = $this->writeCreateManifest($lesson, $user, 'ambiguous-commit.json');
        [, $dryRunOutput] = $this->runFixture(['manifest' => $manifest]);
        $rollbackOutput = $this->protectedDirectory.'/ambiguous-commit-rollback.json';
        $this->bindCheckpointFailure('after_create_transaction_commit');

        [$exitCode, $output] = $this->runFixture([
            'manifest' => $manifest,
            '--apply' => true,
            '--rollback-output' => $rollbackOutput,
            '--confirm-manifest-sha256' => hash_file('sha256', $manifest),
            '--confirm-plan-sha256' => $this->planHash($dryRunOutput),
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertSafeOutputShape($output, 'create-apply', 'recovery_required');
        $this->assertFixtureRows(1);
        $this->assertFileExists($rollbackOutput);
        $rollback = json_decode((string) file_get_contents($rollbackOutput), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('prepared', $rollback['state']);
    }

    public function test_post_commit_manifest_state_failure_returns_recovery_manifest_that_can_be_rolled_back(): void
    {
        [$lesson, $user] = $this->createTarget();
        $this->createActiveAllowlist();
        $manifest = $this->writeCreateManifest($lesson, $user, 'post-commit-failure.json');
        [, $dryRunOutput] = $this->runFixture(['manifest' => $manifest]);
        $rollbackOutput = $this->protectedDirectory.'/post-commit-rollback.json';
        $this->bindAppliedStateWriteFailure();

        [$exitCode, $output] = $this->runFixture([
            'manifest' => $manifest,
            '--apply' => true,
            '--rollback-output' => $rollbackOutput,
            '--confirm-manifest-sha256' => hash_file('sha256', $manifest),
            '--confirm-plan-sha256' => $this->planHash($dryRunOutput),
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertSafeOutputShape($output, 'create-apply', 'recovery_required');
        $this->assertFixtureRows(1);
        $rollback = json_decode((string) file_get_contents($rollbackOutput), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('prepared', $rollback['state']);

        [, $rollbackDryOutput] = $this->runFixture(['manifest' => $rollbackOutput, '--rollback' => true]);
        [$rollbackExit, $rollbackApplyOutput] = $this->runFixture([
            'manifest' => $rollbackOutput,
            '--rollback' => true,
            '--apply' => true,
            '--confirm-manifest-sha256' => hash_file('sha256', $rollbackOutput),
            '--confirm-plan-sha256' => $this->planHash($rollbackDryOutput),
        ]);
        $this->assertSame(0, $rollbackExit);
        $this->assertSafeOutputShape($rollbackApplyOutput, 'rollback-apply', 'rolled_back', 'delete_count', 6);
        $this->assertFixtureRows(0);
    }

    public function test_duplicate_create_is_rejected_without_creating_second_fixture(): void
    {
        [$lesson, $user] = $this->createTarget();
        $this->createActiveAllowlist();
        $manifest = $this->writeCreateManifest($lesson, $user, 'duplicate-create.json');
        [, $dryRunOutput] = $this->runFixture(['manifest' => $manifest]);
        $rollbackOutput = $this->protectedDirectory.'/duplicate-create-rollback.json';
        [$firstExit] = $this->runFixture([
            'manifest' => $manifest,
            '--apply' => true,
            '--rollback-output' => $rollbackOutput,
            '--confirm-manifest-sha256' => hash_file('sha256', $manifest),
            '--confirm-plan-sha256' => $this->planHash($dryRunOutput),
        ]);
        $this->assertSame(0, $firstExit);

        $before = $this->rowCounts();
        [$duplicateExit, $duplicateOutput] = $this->runFixture([
            'manifest' => $manifest,
            '--apply' => true,
            '--rollback-output' => $this->protectedDirectory.'/duplicate-create-second-rollback.json',
            '--confirm-manifest-sha256' => hash_file('sha256', $manifest),
            '--confirm-plan-sha256' => $this->planHash($dryRunOutput),
        ]);
        $this->assertSame(1, $duplicateExit);
        $this->assertSame("code=operation_rejected\n", $duplicateOutput);
        $this->assertSame($before, $this->rowCounts());
        $this->assertFixtureRows(1);
    }

    public function test_stale_create_plan_is_rejected_after_target_changes(): void
    {
        [$lesson, $user] = $this->createTarget();
        $this->createActiveAllowlist();
        $manifest = $this->writeCreateManifest($lesson, $user, 'stale-plan.json');
        [, $dryRunOutput] = $this->runFixture(['manifest' => $manifest]);
        $user->update(['role' => 'admin']);

        [$exitCode, $output] = $this->runFixture([
            'manifest' => $manifest,
            '--apply' => true,
            '--rollback-output' => $this->protectedDirectory.'/stale-plan-rollback.json',
            '--confirm-manifest-sha256' => hash_file('sha256', $manifest),
            '--confirm-plan-sha256' => $this->planHash($dryRunOutput),
        ]);
        $this->assertSame(1, $exitCode);
        $this->assertSame("code=operation_rejected\n", $output);
        $this->assertFixtureRows(0);
    }

    public function test_rollback_drift_and_partial_absence_never_delete_remaining_rows(): void
    {
        [$lesson, $user] = $this->createTarget();
        $this->createActiveAllowlist();
        [$rollbackOutput] = $this->createAppliedFixture($lesson, $user, 'rollback-drift');

        $rollbackDocument = json_decode((string) file_get_contents($rollbackOutput), true, 512, JSON_THROW_ON_ERROR);
        $appliedAt = $rollbackDocument['applied_at'];
        $rollbackDocument['applied_at'] = null;
        file_put_contents($rollbackOutput, json_encode($rollbackDocument, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        chmod($rollbackOutput, 0600);
        [$envelopeExit, $envelopeOutput] = $this->runFixture(['manifest' => $rollbackOutput, '--rollback' => true]);
        $this->assertSame(1, $envelopeExit);
        $this->assertSame("code=operation_rejected\n", $envelopeOutput);
        $this->assertFixtureRows(1);
        $rollbackDocument['applied_at'] = $appliedAt;

        $rollbackDocument['environment'] = 'staging';
        $rollbackDocument['payload_sha256'] = $this->rollbackPayloadHash($rollbackDocument);
        file_put_contents($rollbackOutput, json_encode($rollbackDocument, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        chmod($rollbackOutput, 0600);
        $this->app['env'] = 'production';
        try {
            [$environmentExit, $environmentOutput] = $this->runFixture([
                'manifest' => $rollbackOutput,
                '--rollback' => true,
            ]);
        } finally {
            $this->app['env'] = 'testing';
        }
        $this->assertSame(1, $environmentExit);
        $this->assertSame("code=operation_rejected\n", $environmentOutput);
        $this->assertFixtureRows(1);

        $rollbackDocument['environment'] = 'testing';
        $rollbackDocument['payload_sha256'] = $this->rollbackPayloadHash($rollbackDocument);
        file_put_contents($rollbackOutput, json_encode($rollbackDocument, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        chmod($rollbackOutput, 0600);
        $rollbackDocument['state'] = 'rolled_back';
        $rollbackDocument['rolled_back_at'] = now()->toIso8601ZuluString();
        file_put_contents($rollbackOutput, json_encode($rollbackDocument, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        chmod($rollbackOutput, 0600);
        [$stateExit, $stateOutput] = $this->runFixture(['manifest' => $rollbackOutput, '--rollback' => true]);
        $this->assertSame(1, $stateExit);
        $this->assertSame("code=operation_rejected\n", $stateOutput);
        $this->assertFixtureRows(1);
        $rollbackDocument['state'] = 'applied';
        $rollbackDocument['rolled_back_at'] = null;
        file_put_contents($rollbackOutput, json_encode($rollbackDocument, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        chmod($rollbackOutput, 0600);

        LessonMaterial::query()->sole()->update(['title' => 'Drifted title']);

        $beforeDrift = $this->rowCounts();
        [$driftExit, $driftOutput] = $this->runFixture(['manifest' => $rollbackOutput, '--rollback' => true]);
        $this->assertSame(1, $driftExit);
        $this->assertSame("code=operation_rejected\n", $driftOutput);
        $this->assertSame($beforeDrift, $this->rowCounts());

        LessonMaterial::query()->sole()->update(['title' => 'Parity fixture video']);

        $course = $lesson->course;
        $course->update(['title' => 'Drifted course title']);
        $beforeHierarchyDrift = $this->rowCounts();
        [$hierarchyExit, $hierarchyOutput] = $this->runFixture(['manifest' => $rollbackOutput, '--rollback' => true]);
        $this->assertSame(1, $hierarchyExit);
        $this->assertSame("code=operation_rejected\n", $hierarchyOutput);
        $this->assertSame($beforeHierarchyDrift, $this->rowCounts());
        $course->update(['title' => 'Fixture course']);

        $foreignLesson = Lesson::query()->create([
            'course_id' => $lesson->course_id,
            'slug' => 'foreign-dependent-lesson',
            'title' => 'Foreign dependent lesson',
            'is_active' => true,
        ]);
        $foreignContent = LessonLearningContent::query()->create([
            'lesson_id' => $foreignLesson->id,
            'status' => LessonLearningContent::STATUS_DRAFT,
            'format' => LessonLearningContent::FORMAT_LESSON_BLOCKS_V1,
            'version' => 1,
            'body' => ['version' => 1, 'blocks' => []],
            'import_status' => LessonLearningContent::IMPORT_STATUS_MANUAL,
        ]);
        $foreignMapping = LegacyProtectedAssetMapping::query()->create([
            'lesson_learning_content_id' => $foreignContent->id,
            'lesson_id' => $foreignLesson->id,
            'asset_kind' => LegacyProtectedAssetMapping::ASSET_KIND_VIDEO,
            'legacy_asset_id' => 990_001,
            'readiness_status' => LegacyProtectedAssetMapping::READINESS_MAPPED_INTERNAL,
            'validation_status' => LegacyProtectedAssetMapping::VALIDATION_PASSED,
            'internal_asset_type' => 'lesson_material',
            'internal_asset_id' => LessonMaterial::query()->where('lesson_id', $lesson->id)->sole()->id,
        ]);
        $beforeForeignMapping = $this->rowCounts();
        [$mappingExit, $mappingOutput] = $this->runFixture(['manifest' => $rollbackOutput, '--rollback' => true]);
        $this->assertSame(1, $mappingExit);
        $this->assertSame("code=operation_rejected\n", $mappingOutput);
        $this->assertSame($beforeForeignMapping, $this->rowCounts());
        $foreignMapping->delete();
        $foreignContent->delete();
        $foreignLesson->delete();

        $foreignUser = User::factory()->create(['role' => 'user_no_purchase']);
        $foreignGrant = UserEntitlementGrant::query()->create([
            'user_id' => $foreignUser->id,
            'atom_kind' => UserEntitlementGrant::ATOM_LESSON,
            'atom_id' => $lesson->id,
            'status' => UserEntitlementGrant::STATUS_ACTIVE,
            'source_type' => UserEntitlementGrant::SOURCE_TEST_FIXTURE,
            'source_id' => 'foreign-dependent-grant',
            'idempotency_key' => 'foreign-dependent-grant',
            'granted_at' => now(),
            'expires_at' => now()->addHour(),
        ]);
        $beforeForeignGrant = $this->rowCounts();
        [$grantExit, $grantOutput] = $this->runFixture(['manifest' => $rollbackOutput, '--rollback' => true]);
        $this->assertSame(1, $grantExit);
        $this->assertSame("code=operation_rejected\n", $grantOutput);
        $this->assertSame($beforeForeignGrant, $this->rowCounts());
        $foreignGrant->delete();

        UserEntitlementGrant::query()->sole()->delete();
        $beforePartial = $this->rowCounts();
        [$partialExit, $partialOutput] = $this->runFixture(['manifest' => $rollbackOutput, '--rollback' => true]);
        $this->assertSame(1, $partialExit);
        $this->assertSame("code=operation_rejected\n", $partialOutput);
        $this->assertSame($beforePartial, $this->rowCounts());
    }

    /**
     * @return array{Lesson, User}
     */
    private function createTarget(): array
    {
        $subject = Subject::query()->create([
            'slug' => 'fixture-subject',
            'title' => 'Fixture subject',
            'is_active' => true,
        ]);
        $course = Course::query()->create([
            'subject_id' => $subject->id,
            'slug' => 'fixture-course',
            'title' => 'Fixture course',
            'price_cents' => 10000,
            'is_active' => true,
        ]);
        $lesson = Lesson::query()->create([
            'course_id' => $course->id,
            'slug' => 'fixture-lesson',
            'title' => 'Fixture lesson',
            'is_active' => true,
            'legacy_product_id' => 9001,
        ]);
        $user = User::factory()->create(['role' => 'user_no_purchase']);

        return [$lesson, $user];
    }

    /**
     * @return array{KinescopeLegacyVideoAllowlistBatch, KinescopeLegacyVideoAllowlistEntry}
     */
    private function createActiveAllowlist(
        string $locator = 'legacy-video-a',
        string $providerVideoId = 'provider-legacy-video-a',
    ): array {
        $sourceHash = hash('sha256', "{$locator}\t{$providerVideoId}\n");
        $batch = KinescopeLegacyVideoAllowlistBatch::query()->create([
            'source_count' => 1,
            'source_sha256' => $sourceHash,
            'is_active' => true,
            'activated_at' => now(),
        ]);
        $entry = KinescopeLegacyVideoAllowlistEntry::query()->create([
            'batch_id' => $batch->id,
            'embed_locator' => $locator,
            'provider_video_id' => $providerVideoId,
        ]);

        return [$batch, $entry];
    }

    private function writeCreateManifest(
        Lesson $lesson,
        User $user,
        string $filename = 'create.json',
    ): string {
        return $this->writeManifestData($this->manifestData($lesson, $user), $filename);
    }

    /**
     * @param  array<string, mixed>  $manifest
     */
    private function writeManifestData(array $manifest, string $filename): string
    {
        $path = $this->protectedDirectory.'/'.$filename;
        file_put_contents(
            $path,
            json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        );
        chmod($path, 0600);

        return $path;
    }

    /**
     * @return array<string, mixed>
     */
    private function manifestData(Lesson $lesson, User $user): array
    {
        $source = [
            'id' => 501,
            'product_id' => 9001,
            'content' => '<p>Fixture lesson body.</p>{video::880055}',
        ];
        $preview = $this->app->make(LegacyLessonBodyImporter::class)->previewPlatformPage($source);
        $locator = 'legacy-video-a';
        $providerVideoId = 'provider-legacy-video-a';

        return [
            'schema_version' => 1,
            'operation' => 'create',
            'environment' => 'testing',
            'fixture_key' => 'fixture_'.Str::lower(Str::random(48)),
            'issued_at' => now()->subMinute()->toIso8601ZuluString(),
            'expires_at' => now()->addMinutes(10)->toIso8601ZuluString(),
            'legacy' => [
                'source' => $source,
                'expected_source_scope_type' => 'legacy_product',
                'expected_source_scope_id' => '9001',
                'expected_legacy_asset_id' => 880055,
                'source_fingerprint_sha256' => $preview['source_fingerprint_hash'],
                'video_candidate_fingerprint_sha256' => hash('sha256', 'fixture-video-candidate'),
            ],
            'staging' => [
                'lesson_id' => $lesson->id,
                'lesson_precondition_sha256' => $this->lessonPreconditionHash($lesson),
                'user_id' => $user->id,
                'user_precondition_sha256' => $this->userPreconditionHash($user),
                'entitlement_expires_at' => now()->addHour()->toIso8601ZuluString(),
            ],
            'kinescope' => [
                'active_batch_sha256' => hash('sha256', "{$locator}\t{$providerVideoId}\n"),
                'embed_locator' => $locator,
                'provider_video_id' => $providerVideoId,
                'pair_sha256' => hash('sha256', "{$locator}\t{$providerVideoId}\n"),
            ],
            'material' => [
                'title' => 'Parity fixture video',
                'sort_order' => 1,
            ],
        ];
    }

    private function lessonPreconditionHash(Lesson $lesson): string
    {
        return $this->canonicalHash([
            'id' => (int) $lesson->id,
            'course_id' => (int) $lesson->course_id,
            'slug' => $lesson->slug,
            'title' => $lesson->title,
            'sort_order' => (int) $lesson->sort_order,
            'is_active' => (bool) $lesson->is_active,
            'legacy_product_id' => $lesson->legacy_product_id,
            'legacy_url' => $lesson->legacy_url,
            'legacy_price_cents' => $lesson->legacy_price_cents,
            'metadata' => $lesson->metadata,
        ]);
    }

    private function userPreconditionHash(User $user): string
    {
        return $this->canonicalHash([
            'id' => (int) $user->id,
            'role' => $user->role,
        ]);
    }

    /**
     * @param  array<string, mixed>  $value
     */
    private function canonicalHash(array $value): string
    {
        return hash('sha256', json_encode($this->canonicalize($value), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->canonicalize($item), $value);
        }
        ksort($value);
        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalize($item);
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $document
     */
    private function rollbackPayloadHash(array $document): string
    {
        return $this->canonicalHash([
            'environment' => $document['environment'],
            'fixture_key_sha256' => $document['fixture_key_sha256'],
            'create_manifest_sha256' => $document['create_manifest_sha256'],
            'create_plan_sha256' => $document['create_plan_sha256'],
            'target_preconditions' => $document['target_preconditions'],
            'preexisting_row_counts' => $document['preexisting_row_counts'],
            'ownership_metadata_sha256' => $document['ownership_metadata_sha256'],
            'rows' => $document['rows'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $manifest
     */
    private function sourceFingerprint(array $manifest): string
    {
        $preview = $this->app->make(LegacyLessonBodyImporter::class)
            ->previewPlatformPage($manifest['legacy']['source']);

        return $preview['source_fingerprint_hash'];
    }

    /**
     * @return array<string, string>
     */
    private function providerData(
        string $videoId = 'provider-legacy-video-a',
        string $projectId = 'school-project',
        string $status = 'done',
        string $embedLocator = 'legacy-video-a',
    ): array {
        return [
            'id' => $videoId,
            'project_id' => $projectId,
            'status' => $status,
            'embed_link' => "https://kinescope.io/embed/{$embedLocator}",
        ];
    }

    private function assertRejectedWithoutChanges(string $manifest, string $context = ''): void
    {
        $before = $this->rowCounts();
        [$exitCode, $output] = $this->runFixture(['manifest' => $manifest]);

        $this->assertSame(1, $exitCode, $context);
        $this->assertSame("code=operation_rejected\n", $output);
        $this->assertSame($before, $this->rowCounts());
    }

    /**
     * @return array<string, int>
     */
    private function rowCounts(): array
    {
        return [
            'subjects' => Subject::query()->count(),
            'courses' => Course::query()->count(),
            'lessons' => Lesson::query()->count(),
            'users' => User::query()->count(),
            'allowlist_batches' => KinescopeLegacyVideoAllowlistBatch::query()->count(),
            'allowlist_entries' => KinescopeLegacyVideoAllowlistEntry::query()->count(),
            'contents' => LessonLearningContent::query()->count(),
            'legacy_imports' => LegacyImportMapping::query()->count(),
            'materials' => LessonMaterial::query()->count(),
            'videos' => LessonMaterialKinescopeVideo::query()->count(),
            'protected_mappings' => LegacyProtectedAssetMapping::query()->count(),
            'grants' => UserEntitlementGrant::query()->count(),
        ];
    }

    /**
     * @return array<int, string>
     */
    private function protectedDirectoryEntries(): array
    {
        $entries = scandir($this->protectedDirectory);
        $this->assertIsArray($entries);

        return array_values(array_filter($entries, fn (string $entry): bool => ! in_array($entry, ['.', '..'], true)));
    }

    private function bindCheckpointFailure(string $checkpoint): void
    {
        $this->app->bind(
            KinescopeStagingParityFixtureService::class,
            fn ($app) => new class($app->make(LegacyLessonBodyImporter::class), $app->make(KinescopeApiClient::class), $checkpoint) extends KinescopeStagingParityFixtureService
            {
                public function __construct(
                    LegacyLessonBodyImporter $lessonBodyImporter,
                    KinescopeApiClient $kinescopeApiClient,
                    private readonly string $failingCheckpoint,
                ) {
                    parent::__construct($lessonBodyImporter, $kinescopeApiClient);
                }

                protected function checkpoint(string $name): void
                {
                    if ($name === $this->failingCheckpoint) {
                        throw new RuntimeException('Injected fixture transaction failure.');
                    }
                }
            },
        );
    }

    private function bindAppliedStateWriteFailure(): void
    {
        $this->app->bind(
            KinescopeStagingParityFixtureService::class,
            fn ($app) => new class($app->make(LegacyLessonBodyImporter::class), $app->make(KinescopeApiClient::class)) extends KinescopeStagingParityFixtureService
            {
                protected function writeJsonAtomically(string $path, array $document, bool $overwrite = false): void
                {
                    if ($overwrite && ($document['state'] ?? null) === 'applied') {
                        throw new RuntimeException('Injected applied-state write failure.');
                    }

                    parent::writeJsonAtomically($path, $document, $overwrite);
                }
            },
        );
    }

    private function bindForeignOutputRace(string $foreignContents): void
    {
        $this->app->bind(
            KinescopeStagingParityFixtureService::class,
            fn ($app) => new class($app->make(LegacyLessonBodyImporter::class), $app->make(KinescopeApiClient::class), $foreignContents) extends KinescopeStagingParityFixtureService
            {
                public function __construct(
                    LegacyLessonBodyImporter $lessonBodyImporter,
                    KinescopeApiClient $kinescopeApiClient,
                    private readonly string $foreignContents,
                ) {
                    parent::__construct($lessonBodyImporter, $kinescopeApiClient);
                }

                protected function writeJsonAtomically(string $path, array $document, bool $overwrite = false): void
                {
                    if (! $overwrite && ! file_exists($path)) {
                        file_put_contents($path, $this->foreignContents);
                        chmod($path, 0600);
                    }

                    parent::writeJsonAtomically($path, $document, $overwrite);
                }
            },
        );
    }

    /**
     * @return array{string, string}
     */
    private function createAppliedFixture(Lesson $lesson, User $user, string $name): array
    {
        $manifest = $this->writeCreateManifest($lesson, $user, "{$name}.json");
        [$dryRunExit, $dryRunOutput] = $this->runFixture(['manifest' => $manifest]);
        $this->assertSame(0, $dryRunExit, $dryRunOutput);
        $rollbackOutput = $this->protectedDirectory."/{$name}-rollback.json";
        [$applyExit, $applyOutput] = $this->runFixture([
            'manifest' => $manifest,
            '--apply' => true,
            '--rollback-output' => $rollbackOutput,
            '--confirm-manifest-sha256' => hash_file('sha256', $manifest),
            '--confirm-plan-sha256' => $this->planHash($dryRunOutput),
        ]);
        $this->assertSame(0, $applyExit, $applyOutput);

        return [$rollbackOutput, $manifest];
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array{int, string}
     */
    private function runFixture(array $arguments): array
    {
        $exitCode = Artisan::call('kinescope:staging-parity-fixture', $arguments);

        return [$exitCode, Artisan::output()];
    }

    private function planHash(string $output): string
    {
        preg_match('/plan_sha256=([0-9a-f]{64})/', $output, $matches);
        $this->assertArrayHasKey(1, $matches, $output);

        return $matches[1];
    }

    private function assertFixtureRows(int $expected): void
    {
        $this->assertSame($expected, LessonLearningContent::query()->count());
        $this->assertSame($expected, LegacyImportMapping::query()->count());
        $this->assertSame($expected, LessonMaterial::query()->count());
        $this->assertSame($expected, LessonMaterialKinescopeVideo::query()->count());
        $this->assertSame($expected, LegacyProtectedAssetMapping::query()->count());
        $this->assertSame($expected, UserEntitlementGrant::query()->count());
    }

    private function assertSafeOutputShape(
        string $output,
        string $mode,
        string $status,
        ?string $countKey = null,
        ?int $count = null,
    ): void {
        $pattern = sprintf(
            '/\Amode=%s status=%s manifest_sha256=[0-9a-f]{64} plan_sha256=[0-9a-f]{64}%s\n\z/',
            preg_quote($mode, '/'),
            preg_quote($status, '/'),
            $countKey === null || $count === null
                ? ''
                : sprintf(' %s=%d', preg_quote($countKey, '/'), $count),
        );

        $this->assertMatchesRegularExpression($pattern, $output);
    }

    private function assertSensitiveValuesAreAbsent(string $output, string $manifestPath): void
    {
        $manifest = json_decode((string) file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
        foreach (array_filter([
            $manifestPath,
            data_get($manifest, 'fixture_key'),
            data_get($manifest, 'legacy.source.content'),
            data_get($manifest, 'kinescope.embed_locator'),
            data_get($manifest, 'kinescope.provider_video_id'),
        ], is_string(...)) as $sensitiveValue) {
            $this->assertStringNotContainsString($sensitiveValue, $output);
        }
    }
}
