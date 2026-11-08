<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Lesson;
use App\Models\LessonMaterial;
use App\Models\LessonMaterialFile;
use App\Models\Subject;
use App\Services\ProtectedFiles\ProtectedFileSourceIngestor;
use Composer\InstalledVersions;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class ProtectedFileSourceIngestionCommandTest extends TestCase
{
    use RefreshDatabase;

    private string $sourceDirectory;

    protected function setUp(): void
    {
        parent::setUp();

        $systemTemp = realpath(sys_get_temp_dir());
        $this->assertIsString($systemTemp);
        $this->sourceDirectory = $systemTemp.'/cms-protected-source-'.Str::uuid();
        File::makeDirectory($this->sourceDirectory, 0700);

        config()->set('protected_files.source_disk', 'protected-source');
        config()->set('protected_files.source_prefix', 'protected-sources');
        config()->set('protected_files.max_source_ingest_bytes', 2 * 1024 * 1024);
        config()->set('filesystems.disks.protected-source', [
            'driver' => 'local',
            'root' => storage_path('framework/testing/protected-source'),
            'throw' => true,
        ]);
        Storage::fake('protected-source');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->sourceDirectory);

        parent::tearDown();
    }

    public function test_dry_run_returns_an_opaque_plan_without_database_or_storage_writes(): void
    {
        $material = $this->createMaterial(published: false);
        $pdf = $this->createPdf('dry-run.pdf', 'Dry run source');

        [$exitCode, $output] = $this->runCommand($material, $pdf);

        $this->assertSame(0, $exitCode, $output);
        $this->assertMatchesRegularExpression(
            '/\Astatus=ready mode=dry-run change=create plan=[a-f0-9]{64}\z/',
            $output,
        );
        $this->assertDatabaseCount('lesson_material_files', 0);
        $this->assertSame([], Storage::disk('protected-source')->allFiles());
        $this->assertSafeOutput($output, [$pdf, hash_file('sha256', $pdf), 'protected-sources']);
    }

    public function test_expected_source_metadata_is_bound_to_plan_and_apply(): void
    {
        $material = $this->createMaterial(published: false);
        $pdf = $this->createPdf('expected.pdf', 'Expected source');
        $expectedOptions = $this->expectedOptionsFor($pdf);

        [$dryRunExit, $dryRunOutput] = $this->runCommand($material, $pdf, $expectedOptions);
        $plan = $this->extractPlan($dryRunOutput);
        [$applyExit, $applyOutput] = $this->runCommand($material, $pdf, [
            ...$expectedOptions,
            '--apply' => true,
            '--confirm' => $plan,
        ]);

        $this->assertSame(0, $dryRunExit, $dryRunOutput);
        $this->assertSame(0, $applyExit, $applyOutput);
        $this->assertSame('status=applied mode=apply change=create cleanup=not_needed', $applyOutput);
        $source = LessonMaterialFile::query()->sole();
        $this->assertSame(hash_file('sha256', $pdf), $source->source_sha256);
        $this->assertSame(filesize($pdf), (int) $source->source_size_bytes);
        Storage::disk('protected-source')->assertExists($source->source_object_key);
    }

    public function test_source_ingest_limit_is_separate_from_delivery_source_limit(): void
    {
        $material = $this->createMaterial(published: false);
        $pdf = $this->createPdf('archival-large.pdf', 'Archival source');
        file_put_contents($pdf, str_repeat("\n% archival padding", 64), FILE_APPEND);
        chmod($pdf, 0600);
        $size = filesize($pdf);
        $this->assertIsInt($size);

        config()->set('protected_files.max_source_bytes', max(1, $size - 1));
        config()->set('protected_files.max_source_ingest_bytes', $size + 1024);

        [$exitCode, $output] = $this->runCommand($material, $pdf, $this->expectedOptionsFor($pdf));

        $this->assertSame(0, $exitCode, $output);
        $this->assertMatchesRegularExpression(
            '/\Astatus=ready mode=dry-run change=create plan=[a-f0-9]{64}\z/',
            $output,
        );
        $this->assertDatabaseCount('lesson_material_files', 0);
        $this->assertSame([], Storage::disk('protected-source')->allFiles());
    }

    public function test_expected_source_metadata_mismatch_is_rejected_before_storage_or_registry_writes(): void
    {
        $material = $this->createMaterial(published: false);
        $pdf = $this->createPdf('unexpected.pdf', 'Unexpected source');

        [$exitCode, $output] = $this->runCommand($material, $pdf, [
            '--expected-sha256' => str_repeat('b', 64),
            '--expected-size-bytes' => (string) filesize($pdf),
            '--expected-mime-type' => 'application/pdf',
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertSame('status=error code=source_expectation_mismatch', $output);
        $this->assertSame([], Storage::disk('protected-source')->allFiles());
        $this->assertDatabaseCount('lesson_material_files', 0);
    }

    public function test_expected_source_options_are_required_again_for_confirmed_apply(): void
    {
        $material = $this->createMaterial(published: false);
        $pdf = $this->createPdf('expected-plan.pdf', 'Expected plan');
        $expectedOptions = $this->expectedOptionsFor($pdf);

        [$dryRunExit, $dryRunOutput] = $this->runCommand($material, $pdf, $expectedOptions);
        $plan = $this->extractPlan($dryRunOutput);
        [$applyExit, $applyOutput] = $this->runCommand($material, $pdf, [
            '--apply' => true,
            '--confirm' => $plan,
        ]);

        $this->assertSame(0, $dryRunExit, $dryRunOutput);
        $this->assertSame(1, $applyExit);
        $this->assertSame('status=error code=plan_mismatch', $applyOutput);
        $this->assertSame([], Storage::disk('protected-source')->allFiles());
        $this->assertDatabaseCount('lesson_material_files', 0);
    }

    public function test_invalid_expected_source_options_are_rejected(): void
    {
        $material = $this->createMaterial(published: false);
        $pdf = $this->createPdf('invalid-expected.pdf', 'Invalid expected source');

        foreach ([
            ['--expected-sha256' => strtoupper(str_repeat('a', 64))],
            ['--expected-size-bytes' => '0'],
            ['--expected-mime-type' => 'text/plain'],
        ] as $options) {
            [$exitCode, $output] = $this->runCommand($material, $pdf, $options);

            $this->assertSame(1, $exitCode);
            $this->assertSame('status=error code=invalid_options', $output);
        }

        $this->assertSame([], Storage::disk('protected-source')->allFiles());
        $this->assertDatabaseCount('lesson_material_files', 0);
    }

    public function test_confirmed_apply_creates_a_private_verified_source_without_publishing_material(): void
    {
        $material = $this->createMaterial(published: false);
        $pdf = $this->createPdf('create.pdf', 'Create source');
        $plan = $this->planFor($material, $pdf);

        [$exitCode, $output] = $this->runCommand($material, $pdf, [
            '--apply' => true,
            '--confirm' => $plan,
        ]);

        $this->assertSame(0, $exitCode);
        $this->assertSame('status=applied mode=apply change=create cleanup=not_needed', $output);

        $source = LessonMaterialFile::query()->sole();
        $this->assertMatchesRegularExpression(
            "~\Aprotected-sources/{$material->id}/[a-f0-9-]{36}\.pdf\z~",
            $source->source_object_key,
        );
        $this->assertSame(hash_file('sha256', $pdf), $source->source_sha256);
        $this->assertSame(filesize($pdf), $source->source_size_bytes);
        $this->assertSame('application/pdf', $source->source_mime_type);
        $this->assertSame('create.pdf', $source->download_filename);
        Storage::disk('protected-source')->assertExists($source->source_object_key);
        $this->assertSame('private', Storage::disk('protected-source')->getVisibility($source->source_object_key));
        $this->assertFalse($material->refresh()->is_published);
        $this->assertSafeOutput($output, [$pdf, $source->source_sha256, $source->source_object_key]);
    }

    public function test_replacement_is_copy_on_write_and_deletes_only_the_safe_previous_object(): void
    {
        $material = $this->createMaterial(published: true);
        $oldBytes = $this->pdfBytes('Old source');
        $oldKey = "protected-sources/{$material->id}/old-source.pdf";
        Storage::disk('protected-source')->put($oldKey, $oldBytes, 'private');
        $source = LessonMaterialFile::query()->create([
            'lesson_material_id' => $material->id,
            'source_object_key' => $oldKey,
            'source_sha256' => hash('sha256', $oldBytes),
            'source_size_bytes' => strlen($oldBytes),
            'source_mime_type' => 'application/pdf',
            'download_filename' => 'old.pdf',
        ]);
        $pdf = $this->createPdf('replacement.pdf', 'Replacement source');
        $plan = $this->planFor($material, $pdf);

        [$exitCode, $output] = $this->runCommand($material, $pdf, [
            '--apply' => true,
            '--confirm' => $plan,
        ]);

        $this->assertSame(0, $exitCode);
        $this->assertSame('status=applied mode=apply change=replace cleanup=done', $output);
        $source->refresh();
        $this->assertNotSame($oldKey, $source->source_object_key);
        Storage::disk('protected-source')->assertMissing($oldKey);
        Storage::disk('protected-source')->assertExists($source->source_object_key);
        $this->assertTrue($material->refresh()->is_published);
    }

    public function test_stale_confirmation_is_rejected_before_upload(): void
    {
        $material = $this->createMaterial();
        $pdf = $this->createPdf('stale.pdf', 'Stale plan');
        $plan = $this->planFor($material, $pdf);

        LessonMaterialFile::query()->create([
            'lesson_material_id' => $material->id,
            'source_object_key' => "protected-sources/{$material->id}/concurrent.pdf",
            'source_sha256' => str_repeat('a', 64),
            'source_size_bytes' => 100,
            'source_mime_type' => 'application/pdf',
            'download_filename' => 'concurrent.pdf',
        ]);

        [$exitCode, $output] = $this->runCommand($material, $pdf, [
            '--apply' => true,
            '--confirm' => $plan,
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertSame('status=error code=plan_mismatch', $output);
        $this->assertSame([], Storage::disk('protected-source')->allFiles());
        $this->assertDatabaseCount('lesson_material_files', 1);
    }

    public function test_identical_source_is_no_change_and_never_writes_storage(): void
    {
        $material = $this->createMaterial();
        $pdf = $this->createPdf('same.pdf', 'Same source');
        LessonMaterialFile::query()->create([
            'lesson_material_id' => $material->id,
            'source_object_key' => "protected-sources/{$material->id}/existing.pdf",
            'source_sha256' => hash_file('sha256', $pdf),
            'source_size_bytes' => filesize($pdf),
            'source_mime_type' => 'application/pdf',
            'download_filename' => 'same.pdf',
        ]);

        [$dryRunExit, $dryRunOutput] = $this->runCommand($material, $pdf);
        $plan = $this->extractPlan($dryRunOutput);
        [$applyExit, $applyOutput] = $this->runCommand($material, $pdf, [
            '--apply' => true,
            '--confirm' => $plan,
        ]);

        $this->assertSame(0, $dryRunExit);
        $this->assertStringStartsWith('status=no_change mode=dry-run plan=', $dryRunOutput);
        $this->assertSame(0, $applyExit);
        $this->assertSame('status=no_change mode=apply', $applyOutput);
        $this->assertSame([], Storage::disk('protected-source')->allFiles());
    }

    public function test_apply_and_confirm_must_be_used_together(): void
    {
        $material = $this->createMaterial();
        $pdf = $this->createPdf('options.pdf', 'Options');

        [$applyExit, $applyOutput] = $this->runCommand($material, $pdf, ['--apply' => true]);
        [$confirmExit, $confirmOutput] = $this->runCommand($material, $pdf, [
            '--confirm' => str_repeat('a', 64),
        ]);
        [$malformedExit, $malformedOutput] = $this->runCommand($material, $pdf, [
            '--apply' => true,
            '--confirm' => strtoupper(str_repeat('a', 64)),
        ]);

        $this->assertSame([1, 1, 1], [$applyExit, $confirmExit, $malformedExit]);
        $this->assertSame('status=error code=invalid_options', $applyOutput);
        $this->assertSame('status=error code=invalid_options', $confirmOutput);
        $this->assertSame('status=error code=invalid_confirmation', $malformedOutput);
        $this->assertSame([], Storage::disk('protected-source')->allFiles());
        $this->assertDatabaseCount('lesson_material_files', 0);
    }

    public function test_non_pdf_material_and_unsafe_filename_are_rejected(): void
    {
        $material = $this->createMaterial(kind: LessonMaterial::KIND_NOTES);
        $pdf = $this->createPdf('input.pdf', 'Input');

        [$kindExit, $kindOutput] = $this->runCommand($material, $pdf);
        $material->forceFill(['kind' => LessonMaterial::KIND_PDF])->save();
        $unsafeNames = [
            '../private.pdf',
            "invoice\u{202E}fdp.pdf",
            str_repeat('a', 177).'.pdf',
            'private.txt',
            "invalid-\xC3\x28.pdf",
        ];
        $nameResults = [];
        foreach ($unsafeNames as $unsafeName) {
            $nameResults[] = $this->runCommand($material, $pdf, [
                '--download-filename' => $unsafeName,
            ]);
        }

        $this->assertSame(1, $kindExit);
        $this->assertSame('status=error code=material_not_pdf', $kindOutput);
        foreach ($nameResults as [$exitCode, $output]) {
            $this->assertSame(1, $exitCode);
            $this->assertSame('status=error code=invalid_filename', $output);
        }
        $this->assertDatabaseCount('lesson_material_files', 0);
    }

    public function test_invalid_and_missing_material_ids_are_rejected_without_storage_access(): void
    {
        $pdf = $this->createPdf('material-id.pdf', 'Material ID');

        $invalidExit = Artisan::call('protected-files:ingest-source', [
            'material' => '0',
            'pdf' => $pdf,
        ]);
        $invalidOutput = trim(Artisan::output());
        $missingExit = Artisan::call('protected-files:ingest-source', [
            'material' => '999999',
            'pdf' => $pdf,
        ]);
        $missingOutput = trim(Artisan::output());

        $this->assertSame(1, $invalidExit);
        $this->assertSame('status=error code=invalid_options', $invalidOutput);
        $this->assertSame(1, $missingExit);
        $this->assertSame('status=error code=material_not_found', $missingOutput);
        $this->assertSame([], Storage::disk('protected-source')->allFiles());
    }

    public function test_repository_symlink_permissive_and_non_pdf_inputs_are_rejected(): void
    {
        $material = $this->createMaterial();
        $outsidePdf = $this->createPdf('private.pdf', 'Private');
        $symlink = $this->sourceDirectory.'/linked.pdf';
        symlink($outsidePdf, $symlink);
        $hardLink = $this->sourceDirectory.'/hard-linked.pdf';
        link($outsidePdf, $hardLink);
        $permissivePdf = $this->createPdf('permissive.pdf', 'Permissive');
        chmod($permissivePdf, 0644);
        $text = $this->sourceDirectory.'/fake.pdf';
        file_put_contents($text, 'not a PDF document');
        chmod($text, 0600);
        $empty = $this->sourceDirectory.'/empty.pdf';
        touch($empty);
        chmod($empty, 0600);
        // Use an existing repository file: isolated storage_path intentionally lives outside it.
        $repositoryFile = __FILE__;

        $cases = [
            [$symlink, 'unsafe_path'],
            [$hardLink, 'unsafe_path'],
            [$permissivePdf, 'unsafe_permissions'],
            [$text, 'invalid_pdf'],
            [$empty, 'source_too_large'],
            [$this->sourceDirectory, 'unsafe_path'],
            [ltrim($outsidePdf, DIRECTORY_SEPARATOR), 'unsafe_path'],
            [$this->sourceDirectory.'/./private.pdf', 'unsafe_path'],
            [realpath($repositoryFile), 'unsafe_path'],
        ];

        foreach ($cases as [$path, $code]) {
            $this->assertIsString($path);
            [$exitCode, $output] = $this->runCommand($material, $path);
            $this->assertSame(1, $exitCode);
            $this->assertSame("status=error code={$code}", $output);
            $this->assertSafeOutput($output, [$path]);
        }

        $this->assertSame([], Storage::disk('protected-source')->allFiles());
        $this->assertDatabaseCount('lesson_material_files', 0);
    }

    public function test_source_above_three_hundred_mib_and_configuration_above_ceiling_are_rejected(): void
    {
        $material = $this->createMaterial();
        $pdf = $this->createPdf('ceiling.pdf', 'Bounded');
        $stream = fopen($pdf, 'r+b');
        $this->assertIsResource($stream);
        ftruncate($stream, 300 * 1024 * 1024 + 1);
        fclose($stream);
        config()->set('protected_files.max_source_ingest_bytes', 300 * 1024 * 1024);
        [$exit, $output] = $this->runCommand($material, $pdf);
        $this->assertSame(1, $exit);
        $this->assertSame('status=error code=source_too_large', $output);
        config()->set('protected_files.max_source_ingest_bytes', 300 * 1024 * 1024 + 1);
        [$exit, $output] = $this->runCommand($material, $pdf);
        $this->assertSame(1, $exit);
        $this->assertSame('status=error code=invalid_configuration', $output);
        $this->assertDatabaseCount('lesson_material_files', 0);
        $this->assertSame([], Storage::disk('protected-source')->allFiles());
    }

    public function test_oversized_file_and_invalid_storage_configuration_are_rejected(): void
    {
        $material = $this->createMaterial();
        $pdf = $this->createPdf('bounded.pdf', 'Bounded');
        config()->set('protected_files.max_source_ingest_bytes', filesize($pdf) - 1);

        [$largeExit, $largeOutput] = $this->runCommand($material, $pdf);

        config()->set('protected_files.max_source_ingest_bytes', 2 * 1024 * 1024);
        config()->set('protected_files.source_prefix', '../unsafe');
        [$configExit, $configOutput] = $this->runCommand($material, $pdf);
        config()->set('protected_files.source_prefix', 'protected-sources');
        config()->set('protected_files.source_disk', 'protected-source-s3');
        config()->set('filesystems.disks.protected-source-s3', [
            'driver' => 's3',
            'visibility' => 'private',
            'throw' => true,
            'report' => false,
            'use_path_style_endpoint' => false,
        ]);
        [$inactiveS3Exit, $inactiveS3Output] = $this->runCommand($material, $pdf);
        config()->set('protected_files.source_disk', 'public');
        [$publicDiskExit, $publicDiskOutput] = $this->runCommand($material, $pdf);
        config()->set('filesystems.disks.relative-source', [
            'driver' => 'local',
            'root' => 'storage/private',
            'throw' => true,
        ]);
        config()->set('protected_files.source_disk', 'relative-source');
        [$relativeRootExit, $relativeRootOutput] = $this->runCommand($material, $pdf);
        $uncreatedPublicTarget = sys_get_temp_dir().'/cms-uncreated-public-target-'.Str::uuid();
        config()->set('filesystems.links.public-probe', $uncreatedPublicTarget);
        config()->set('filesystems.disks.linked-source', [
            'driver' => 'local',
            'root' => $uncreatedPublicTarget.'/private-child',
            'throw' => true,
        ]);
        config()->set('protected_files.source_disk', 'linked-source');
        [$linkedRootExit, $linkedRootOutput] = $this->runCommand($material, $pdf);
        $privateParent = storage_path('framework/testing/private-parent');
        config()->set('filesystems.links.public-child-probe', $privateParent.'/public-child');
        config()->set('filesystems.disks.parent-source', [
            'driver' => 'local',
            'root' => $privateParent,
            'throw' => true,
        ]);
        config()->set('protected_files.source_disk', 'parent-source');
        [$publicChildExit, $publicChildOutput] = $this->runCommand($material, $pdf);
        $dotSegmentRoots = [
            storage_path('uncreated/../app/public/private'),
            storage_path('uncreated/../../outside'),
            storage_path('framework/testing/uncreated/../public-target/private'),
        ];
        config()->set(
            'filesystems.links.dot-public-target',
            storage_path('framework/testing/public-target'),
        );
        $dotSegmentResults = [];
        foreach ($dotSegmentRoots as $index => $root) {
            $diskName = "dot-segment-source-{$index}";
            config()->set("filesystems.disks.{$diskName}", [
                'driver' => 'local',
                'root' => $root,
                'throw' => true,
            ]);
            config()->set('protected_files.source_disk', $diskName);
            $dotSegmentResults[] = $this->runCommand($material, $pdf);
        }

        $this->assertSame(1, $largeExit);
        $this->assertSame('status=error code=source_too_large', $largeOutput);
        $this->assertSame(1, $configExit);
        $this->assertSame('status=error code=invalid_configuration', $configOutput);
        $this->assertSame(1, $inactiveS3Exit);
        $this->assertSame('status=error code=invalid_configuration', $inactiveS3Output);
        $this->assertSame(1, $publicDiskExit);
        $this->assertSame('status=error code=invalid_configuration', $publicDiskOutput);
        $this->assertSame(1, $relativeRootExit);
        $this->assertSame('status=error code=invalid_configuration', $relativeRootOutput);
        $this->assertSame(1, $linkedRootExit);
        $this->assertSame('status=error code=invalid_configuration', $linkedRootOutput);
        $this->assertSame(1, $publicChildExit);
        $this->assertSame('status=error code=invalid_configuration', $publicChildOutput);
        foreach ($dotSegmentResults as [$exitCode, $output]) {
            $this->assertSame(1, $exitCode);
            $this->assertSame('status=error code=invalid_configuration', $output);
        }
        $this->assertSame([], Storage::disk('protected-source')->allFiles());
    }

    public function test_corrupt_storage_read_back_fails_closed_and_removes_the_new_object(): void
    {
        $material = $this->createMaterial();
        $pdf = $this->createPdf('corrupt-read-back.pdf', 'Read back');
        $plan = $this->planFor($material, $pdf);
        $disk = Mockery::mock(FilesystemAdapter::class);
        $disk->shouldReceive('put')->once()->andReturn(true);
        $disk->shouldReceive('readStream')->once()->andReturn($this->streamFor('corrupted'));
        $disk->shouldReceive('delete')->once()->andReturn(true);
        Storage::set('protected-source', $disk);

        [$exitCode, $output] = $this->runCommand($material, $pdf, [
            '--apply' => true,
            '--confirm' => $plan,
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertSame('status=error code=storage_integrity_failed', $output);
        $this->assertDatabaseCount('lesson_material_files', 0);
    }

    public function test_failed_storage_write_is_cleaned_up_before_the_registry_changes(): void
    {
        $material = $this->createMaterial();
        $pdf = $this->createPdf('write-failure.pdf', 'Write failure');
        $plan = $this->planFor($material, $pdf);
        $disk = Mockery::mock(FilesystemAdapter::class);
        $disk->shouldReceive('put')->once()->andReturn(false);
        $disk->shouldReceive('delete')->once()->andReturn(true);
        Storage::set('protected-source', $disk);

        [$exitCode, $output] = $this->runCommand($material, $pdf, [
            '--apply' => true,
            '--confirm' => $plan,
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertSame('status=error code=storage_write_failed', $output);
        $this->assertDatabaseCount('lesson_material_files', 0);
    }

    public function test_cleanup_failure_is_reported_without_leaking_the_new_object_locator(): void
    {
        $material = $this->createMaterial();
        $pdf = $this->createPdf('cleanup.pdf', 'Cleanup');
        $plan = $this->planFor($material, $pdf);
        $disk = Mockery::mock(FilesystemAdapter::class);
        $disk->shouldReceive('put')->once()->andReturn(true);
        $disk->shouldReceive('readStream')->once()->andReturn($this->streamFor('corrupted'));
        $disk->shouldReceive('delete')->once()->andThrow(new RuntimeException('private locator sentinel'));
        Storage::set('protected-source', $disk);

        [$exitCode, $output] = $this->runCommand($material, $pdf, [
            '--apply' => true,
            '--confirm' => $plan,
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertSame('status=error code=cleanup_deferred', $output);
        $this->assertStringNotContainsString('private locator sentinel', $output);
        $this->assertDatabaseCount('lesson_material_files', 0);
    }

    public function test_locked_compare_and_swap_preserves_a_concurrently_created_source(): void
    {
        $material = $this->createMaterial();
        $pdf = $this->createPdf('cas.pdf', 'CAS source');
        $plan = $this->planFor($material, $pdf);
        $disk = Mockery::mock(FilesystemAdapter::class);
        $disk->shouldReceive('put')->once()->andReturn(true);
        $disk->shouldReceive('readStream')->once()->andReturnUsing(function () use ($material, $pdf) {
            LessonMaterialFile::query()->create([
                'lesson_material_id' => $material->id,
                'source_object_key' => "protected-sources/{$material->id}/concurrent.pdf",
                'source_sha256' => str_repeat('c', 64),
                'source_size_bytes' => 100,
                'source_mime_type' => 'application/pdf',
                'download_filename' => 'concurrent.pdf',
            ]);

            return fopen($pdf, 'rb');
        });
        $disk->shouldReceive('delete')->once()->andReturn(true);
        Storage::set('protected-source', $disk);

        [$exitCode, $output] = $this->runCommand($material, $pdf, [
            '--apply' => true,
            '--confirm' => $plan,
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertSame('status=error code=registry_conflict', $output);
        $source = LessonMaterialFile::query()->sole();
        $this->assertSame('concurrent.pdf', $source->download_filename);
        $this->assertSame(str_repeat('c', 64), $source->source_sha256);
    }

    public function test_post_commit_cleanup_failure_keeps_the_new_source_active(): void
    {
        $material = $this->createMaterial();
        LessonMaterialFile::query()->create([
            'lesson_material_id' => $material->id,
            'source_object_key' => "protected-sources/{$material->id}/old.pdf",
            'source_sha256' => str_repeat('d', 64),
            'source_size_bytes' => 100,
            'source_mime_type' => 'application/pdf',
            'download_filename' => 'old.pdf',
        ]);
        $pdf = $this->createPdf('post-commit.pdf', 'Post commit');
        $plan = $this->planFor($material, $pdf);
        $disk = Mockery::mock(FilesystemAdapter::class);
        $disk->shouldReceive('put')->once()->andReturn(true);
        $disk->shouldReceive('readStream')->once()->andReturn($this->streamFor(file_get_contents($pdf)));
        $disk->shouldReceive('delete')->once()->andThrow(new RuntimeException('old locator sentinel'));
        Storage::set('protected-source', $disk);

        [$exitCode, $output] = $this->runCommand($material, $pdf, [
            '--apply' => true,
            '--confirm' => $plan,
        ]);

        $this->assertSame(0, $exitCode);
        $this->assertSame('status=applied mode=apply change=replace cleanup=deferred', $output);
        $this->assertStringNotContainsString('old locator sentinel', $output);
        $this->assertSame(hash_file('sha256', $pdf), LessonMaterialFile::query()->sole()->source_sha256);
    }

    public function test_post_commit_cleanup_does_not_delete_an_object_still_referenced_by_another_material(): void
    {
        $material = $this->createMaterial();
        $otherMaterial = $this->createMaterial();
        $oldKey = "protected-sources/{$material->id}/shared.pdf";
        $oldBytes = $this->pdfBytes('Shared source');
        Storage::disk('protected-source')->put($oldKey, $oldBytes, 'private');

        foreach ([$material, $otherMaterial] as $sourceMaterial) {
            LessonMaterialFile::query()->create([
                'lesson_material_id' => $sourceMaterial->id,
                'source_object_key' => $oldKey,
                'source_sha256' => hash('sha256', $oldBytes),
                'source_size_bytes' => strlen($oldBytes),
                'source_mime_type' => 'application/pdf',
                'download_filename' => 'shared.pdf',
            ]);
        }
        $pdf = $this->createPdf('new-shared.pdf', 'New source');
        $plan = $this->planFor($material, $pdf);

        [$exitCode, $output] = $this->runCommand($material, $pdf, [
            '--apply' => true,
            '--confirm' => $plan,
        ]);

        $this->assertSame(0, $exitCode);
        $this->assertSame('status=applied mode=apply change=replace cleanup=deferred', $output);
        Storage::disk('protected-source')->assertExists($oldKey);
        $this->assertSame($oldKey, $otherMaterial->protectedFile()->sole()->source_object_key);
    }

    public function test_unexpected_exception_is_reduced_to_a_safe_error_code(): void
    {
        $material = $this->createMaterial();
        $pdf = $this->createPdf('exception.pdf', 'Exception');
        $ingestor = Mockery::mock(ProtectedFileSourceIngestor::class);
        $ingestor->shouldReceive('execute')->once()->andThrow(
            new RuntimeException('secret endpoint and object key sentinel'),
        );
        $this->app->instance(ProtectedFileSourceIngestor::class, $ingestor);

        [$exitCode, $output] = $this->runCommand($material, $pdf);

        $this->assertSame(1, $exitCode);
        $this->assertSame('status=error code=internal_error', $output);
        $this->assertStringNotContainsString('secret endpoint and object key sentinel', $output);
    }

    public function test_s3_disks_are_separate_private_and_env_driven(): void
    {
        $filesystems = require config_path('filesystems.php');
        $protectedFiles = require config_path('protected_files.php');

        $this->assertSame(env('PROTECTED_FILES_SOURCE_DISK', 'local'), $protectedFiles['source_disk']);
        $this->assertSame(env('PROTECTED_FILES_OUTPUT_DISK', 'local'), $protectedFiles['output_disk']);
        $this->assertSame('protected-sources', $protectedFiles['source_prefix']);
        $this->assertTrue(InstalledVersions::isInstalled('league/flysystem-aws-s3-v3'));

        foreach (['protected-source-s3', 'protected-output-s3'] as $diskName) {
            $disk = $filesystems['disks'][$diskName];
            $this->assertSame('s3', $disk['driver']);
            $this->assertSame('private', $disk['visibility']);
            $this->assertTrue($disk['throw']);
            $this->assertFalse($disk['report']);
            $this->assertFalse($disk['use_path_style_endpoint']);
            foreach (['key', 'secret', 'region', 'bucket', 'endpoint'] as $field) {
                $this->assertTrue(
                    $disk[$field] === null || (is_string($disk[$field]) && trim($disk[$field]) !== ''),
                    "{$diskName}.{$field} must be null or a non-empty string.",
                );
            }
        }
    }

    public function test_complete_private_s3_configuration_is_accepted_for_dry_run_without_provider_access(): void
    {
        $material = $this->createMaterial();
        $pdf = $this->createPdf('s3-dry-run.pdf', 'S3 dry run');
        config()->set('protected_files.source_disk', 'protected-source-s3');
        config()->set('filesystems.disks.protected-source-s3', [
            'driver' => 's3',
            'key' => 'fixture-access-key',
            'secret' => 'fixture-secret-key',
            'region' => 'ru-1',
            'bucket' => 'fixture-private-bucket',
            'endpoint' => 'https://s3.example.invalid',
            'use_path_style_endpoint' => false,
            'visibility' => 'private',
            'throw' => true,
            'report' => false,
        ]);

        [$exitCode, $output] = $this->runCommand($material, $pdf);

        $this->assertSame(0, $exitCode, $output);
        $this->assertMatchesRegularExpression(
            '/\Astatus=ready mode=dry-run change=create plan=[a-f0-9]{64}\z/',
            $output,
        );
        $this->assertDatabaseCount('lesson_material_files', 0);
        $this->assertSame([], Storage::disk('protected-source')->allFiles());
    }

    public function test_s3_configuration_rejects_non_boolean_addressing_mode(): void
    {
        $material = $this->createMaterial();
        $pdf = $this->createPdf('s3-invalid-addressing.pdf', 'S3 invalid addressing');
        config()->set('protected_files.source_disk', 'protected-source-s3');
        config()->set('filesystems.disks.protected-source-s3', [
            'driver' => 's3',
            'key' => 'fixture-access-key',
            'secret' => 'fixture-secret-key',
            'region' => 'ru-1',
            'bucket' => 'fixture-private-bucket',
            'endpoint' => 'https://s3.example.invalid',
            'use_path_style_endpoint' => 'false',
            'visibility' => 'private',
            'throw' => true,
            'report' => false,
        ]);

        [$exitCode, $output] = $this->runCommand($material, $pdf);

        $this->assertSame(1, $exitCode);
        $this->assertSame('status=error code=invalid_configuration', $output);
        $this->assertDatabaseCount('lesson_material_files', 0);
        $this->assertSame([], Storage::disk('protected-source')->allFiles());
    }

    private function createMaterial(
        bool $published = false,
        string $kind = LessonMaterial::KIND_PDF,
    ): LessonMaterial {
        $suffix = Str::lower(Str::random(8));
        $subject = Subject::query()->create([
            'slug' => "subject-{$suffix}",
            'title' => 'Предмет',
        ]);
        $course = Course::query()->create([
            'subject_id' => $subject->id,
            'slug' => "course-{$suffix}",
            'title' => 'Курс',
            'price_cents' => 100000,
        ]);
        $lesson = Lesson::query()->create([
            'course_id' => $course->id,
            'slug' => "lesson-{$suffix}",
            'title' => 'Урок',
        ]);

        return LessonMaterial::query()->create([
            'lesson_id' => $lesson->id,
            'kind' => $kind,
            'title' => 'Защищённый PDF',
            'is_published' => $published,
        ]);
    }

    private function createPdf(string $filename, string $text): string
    {
        $path = $this->sourceDirectory.'/'.$filename;
        file_put_contents($path, $this->pdfBytes($text));
        chmod($path, 0600);
        $canonicalPath = realpath($path);
        $this->assertIsString($canonicalPath);

        return $canonicalPath;
    }

    private function pdfBytes(string $text): string
    {
        $pdf = new \FPDF;
        $pdf->AddPage();
        $pdf->SetFont('Helvetica', '', 12);
        $pdf->Cell(40, 10, $text);

        return $pdf->Output('S');
    }

    /** @return resource */
    private function streamFor(string|false $bytes): mixed
    {
        $this->assertIsString($bytes);
        $stream = fopen('php://temp', 'w+b');
        $this->assertIsResource($stream);
        fwrite($stream, $bytes);
        rewind($stream);

        return $stream;
    }

    /** @param array<string, mixed> $options */
    private function runCommand(
        LessonMaterial $material,
        string $pdf,
        array $options = [],
    ): array {
        $exitCode = Artisan::call('protected-files:ingest-source', [
            'material' => $material->id,
            'pdf' => $pdf,
            ...$options,
        ]);

        return [$exitCode, trim(Artisan::output())];
    }

    private function planFor(LessonMaterial $material, string $pdf): string
    {
        [$exitCode, $output] = $this->runCommand($material, $pdf);
        $this->assertSame(0, $exitCode);

        return $this->extractPlan($output);
    }

    /**
     * @return array<string, string>
     */
    private function expectedOptionsFor(string $pdf): array
    {
        $size = filesize($pdf);
        $this->assertIsInt($size);

        return [
            '--expected-sha256' => hash_file('sha256', $pdf),
            '--expected-size-bytes' => (string) $size,
            '--expected-mime-type' => 'application/pdf',
        ];
    }

    private function extractPlan(string $output): string
    {
        $matched = preg_match('/\bplan=([a-f0-9]{64})\b/', $output, $matches);
        $this->assertSame(1, $matched, $output);

        return $matches[1];
    }

    /** @param list<string|false> $forbidden */
    private function assertSafeOutput(string $output, array $forbidden): void
    {
        foreach ($forbidden as $value) {
            if (is_string($value) && $value !== '') {
                $this->assertStringNotContainsString($value, $output);
            }
        }
    }
}
