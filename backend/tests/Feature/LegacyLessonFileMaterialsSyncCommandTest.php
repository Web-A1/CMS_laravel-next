<?php

namespace Tests\Feature;

use App\Exceptions\ProtectedFileGenerationException;
use App\Models\Course;
use App\Models\LegacyImportMapping;
use App\Models\Lesson;
use App\Models\LessonMaterial;
use App\Models\LessonMaterialFile;
use App\Models\Subject;
use App\Services\ProtectedFiles\ProtectedFileSourceValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\TestCase;

class LegacyLessonFileMaterialsSyncCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_dry_run_plans_materials_and_file_ingestion_without_database_writes(): void
    {
        $lesson = $this->createLesson();
        $sourceReport = $this->writeDryRunReport([
            $this->transferableItem($lesson, platformPageId: 501, fileId: 9001, occurrenceIndex: 0),
        ]);
        $reportPath = storage_path('app/legacy-file-materials-sync-dry-run-report.json');

        $this->artisan('legacy:learning:file-materials:sync', [
            '--source-report' => $sourceReport,
            '--report' => $reportPath,
        ])
            ->expectsOutputToContain('Dry-run completed: considered=1 material_create=1 material_update=0 material_no_change=0 file_create=0 file_needs_ingestion=1 manual_review=0')
            ->assertSuccessful();

        $report = $this->readReport($reportPath);
        $item = $report['items'][0];

        $this->assertSame('0600', substr(sprintf('%o', fileperms($reportPath)), -4));
        $this->assertSame('dry-run', $report['mode']);
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', $report['source_report_sha256']);
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', $report['plan_sha256']);
        $this->assertSame('planned', $report['target_behavior']['lesson_material_files']);
        $this->assertSame('create', $item['material_action']);
        $this->assertSame('needs_source_ingestion', $item['file_action']);
        $this->assertSame(false, $item['is_published']);
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', $item['import_source_key']);
        $this->assertArrayHasKey('source_object_key', $item);
        $this->assertNull($item['source_object_key']);
        $this->assertSame(0, LessonMaterial::query()->count());
        $this->assertSame(0, LessonMaterialFile::query()->count());
        $this->assertSame(0, LegacyImportMapping::query()->where('batch_key', 'lesson_file_material_v1')->count());
    }

    public function test_apply_creates_unpublished_material_and_mapping_idempotently_without_file_row(): void
    {
        $lesson = $this->createLesson();
        $sourceReport = $this->writeDryRunReport([
            $this->transferableItem($lesson, platformPageId: 502, fileId: 9002, occurrenceIndex: 3, byteOffset: 450),
        ]);
        $reportPath = storage_path('app/legacy-file-materials-sync-apply-report.json');

        $this->artisan('legacy:learning:file-materials:sync', array_merge([
            '--source-report' => $sourceReport,
            '--apply' => true,
            '--report' => $reportPath,
        ], $this->applyOptions($sourceReport)))
            ->expectsOutputToContain('Apply completed: considered=1 material_create=1 material_update=0 material_no_change=0 file_create=0 file_needs_ingestion=1 manual_review=0')
            ->assertSuccessful();

        $material = LessonMaterial::query()->sole();
        $mapping = LegacyImportMapping::query()->where('batch_key', 'lesson_file_material_v1')->sole();
        $this->assertSame($lesson->id, $material->lesson_id);
        $this->assertSame(LessonMaterial::KIND_PDF, $material->kind);
        $this->assertSame('Конспект к уроку', $material->title);
        $this->assertSame(1003, $material->sort_order);
        $this->assertFalse((bool) $material->is_published);
        $this->assertSame('platform_page_file_shortcode', $mapping->source_table);
        $this->assertSame('learning_lesson_material', $mapping->target_domain);
        $this->assertSame('lesson_material', $mapping->target_type);
        $this->assertSame($material->id, $mapping->target_id);
        $this->assertSame(LegacyImportMapping::MAPPING_MAPPED, $mapping->mapping_status);
        $this->assertSame('needs_source_ingestion', $mapping->metadata['file_source_status']);
        $this->assertSame('chr/anatomy/konspekt-9002.pdf', $mapping->metadata['storage_relative_path']);
        $this->assertSame(0, LessonMaterialFile::query()->count());

        $this->artisan('legacy:learning:file-materials:sync', array_merge([
            '--source-report' => $sourceReport,
            '--apply' => true,
            '--report' => $reportPath,
        ], $this->applyOptions($sourceReport)))
            ->expectsOutputToContain('Apply completed: considered=1 material_create=0 material_update=0 material_no_change=1 file_create=0 file_needs_ingestion=1 manual_review=0')
            ->assertSuccessful();

        $this->assertSame(1, LessonMaterial::query()->count());
        $this->assertSame(1, LegacyImportMapping::query()->where('batch_key', 'lesson_file_material_v1')->count());
        $this->assertSame(0, LessonMaterialFile::query()->count());
    }

    public function test_apply_creates_validated_file_source_row_when_source_object_key_is_present(): void
    {
        $lesson = $this->createLesson();
        $validator = new class extends ProtectedFileSourceValidator
        {
            /** @var list<array<string, mixed>> */
            public array $calls = [];

            public function __construct() {}

            public function validate(
                string $objectKey,
                string $expectedHash,
                int $expectedSize,
                string $mimeType,
                string $filename,
            ): array {
                $this->calls[] = compact('objectKey', 'expectedHash', 'expectedSize', 'mimeType', 'filename');

                return [
                    'output_sha256' => str_repeat('b', 64),
                    'output_size_bytes' => 1024,
                ];
            }
        };
        $this->app->instance(ProtectedFileSourceValidator::class, $validator);
        $sourceReport = $this->writeDryRunReport([
            $this->transferableItem(
                $lesson,
                platformPageId: 506,
                fileId: 9003,
                occurrenceIndex: 0,
                sourceObjectKey: 'protected-sources/9003/source.pdf',
            ),
        ]);
        $reportPath = storage_path('app/legacy-file-materials-sync-source-object-report.json');

        $this->artisan('legacy:learning:file-materials:sync', array_merge([
            '--source-report' => $sourceReport,
            '--apply' => true,
            '--report' => $reportPath,
        ], $this->applyOptions($sourceReport)))
            ->expectsOutputToContain('Apply completed: considered=1 material_create=1 material_update=0 material_no_change=0 file_create=1 file_needs_ingestion=0 manual_review=0')
            ->assertSuccessful();

        $material = LessonMaterial::query()->sole();
        $source = LessonMaterialFile::query()->sole();
        $mapping = LegacyImportMapping::query()->where('batch_key', 'lesson_file_material_v1')->sole();

        $this->assertSame($material->id, $source->lesson_material_id);
        $this->assertSame('protected-sources/9003/source.pdf', $source->source_object_key);
        $this->assertSame(str_repeat('a', 64), $source->source_sha256);
        $this->assertSame(512, $source->source_size_bytes);
        $this->assertSame('application/pdf', $source->source_mime_type);
        $this->assertSame('konspekt.pdf', $source->download_filename);
        $this->assertSame('linked_source_object', $mapping->metadata['file_source_status']);
        $this->assertSame('protected-sources/9003/source.pdf', $mapping->metadata['source_object_key']);
        $this->assertSame([
            [
                'objectKey' => 'protected-sources/9003/source.pdf',
                'expectedHash' => str_repeat('a', 64),
                'expectedSize' => 512,
                'mimeType' => 'application/pdf',
                'filename' => 'konspekt.pdf',
            ],
        ], $validator->calls);
    }

    public function test_apply_marks_unsupported_pdf_source_for_manual_review_without_partial_rows(): void
    {
        $lesson = $this->createLesson();
        $validator = new class extends ProtectedFileSourceValidator
        {
            /** @var list<string> */
            public array $calls = [];

            public function __construct() {}

            public function validate(
                string $objectKey,
                string $expectedHash,
                int $expectedSize,
                string $mimeType,
                string $filename,
            ): array {
                $this->calls[] = $objectKey;

                throw new ProtectedFileGenerationException(ProtectedFileGenerationException::PDF_UNSUPPORTED);
            }
        };
        $this->app->instance(ProtectedFileSourceValidator::class, $validator);
        $sourceReport = $this->writeDryRunReport([
            $this->transferableItem(
                $lesson,
                platformPageId: 506,
                fileId: 9004,
                occurrenceIndex: 0,
                sourceObjectKey: 'protected-sources/9004/unsupported.pdf',
            ),
        ]);
        $reportPath = storage_path('app/legacy-file-materials-sync-unsupported-source-report.json');

        $this->artisan('legacy:learning:file-materials:sync', array_merge([
            '--source-report' => $sourceReport,
            '--apply' => true,
            '--report' => $reportPath,
        ], $this->applyOptions($sourceReport)))
            ->expectsOutputToContain('Apply completed: considered=1 material_create=0 material_update=0 material_no_change=0 file_create=0 file_needs_ingestion=0 manual_review=1')
            ->assertSuccessful();

        $report = $this->readReport($reportPath);

        $this->assertSame(['protected-sources/9004/unsupported.pdf'], $validator->calls);
        $this->assertSame('manual_review', $report['items'][0]['material_action']);
        $this->assertSame('manual_review', $report['items'][0]['file_action']);
        $this->assertSame([
            'protected_file_source_validation_failed:pdf_unsupported',
        ], $report['items'][0]['manual_review_reasons']);
        $this->assertSame(0, LessonMaterial::query()->count());
        $this->assertSame(0, LessonMaterialFile::query()->count());
        $this->assertSame(0, LegacyImportMapping::query()->where('batch_key', 'lesson_file_material_v1')->count());
    }

    public function test_apply_treats_published_matching_pdf_material_as_no_change(): void
    {
        $lesson = $this->createLesson();
        $validator = new class extends ProtectedFileSourceValidator
        {
            /** @var list<array<string, mixed>> */
            public array $calls = [];

            public function __construct() {}

            public function validate(
                string $objectKey,
                string $expectedHash,
                int $expectedSize,
                string $mimeType,
                string $filename,
            ): array {
                $this->calls[] = compact('objectKey', 'expectedHash', 'expectedSize', 'mimeType', 'filename');

                return [
                    'output_sha256' => $expectedHash,
                    'output_size_bytes' => $expectedSize,
                ];
            }
        };
        $this->app->instance(ProtectedFileSourceValidator::class, $validator);
        $sourceReport = $this->writeDryRunReport([
            $this->transferableItem(
                $lesson,
                platformPageId: 506,
                fileId: 9004,
                occurrenceIndex: 1,
                sourceObjectKey: 'protected-sources/9004/source.pdf',
            ),
        ]);
        $reportPath = storage_path('app/legacy-file-materials-sync-published-no-change-report.json');

        $this->artisan('legacy:learning:file-materials:sync', array_merge([
            '--source-report' => $sourceReport,
            '--apply' => true,
            '--report' => $reportPath,
        ], $this->applyOptions($sourceReport)))
            ->expectsOutputToContain('Apply completed: considered=1 material_create=1 material_update=0 material_no_change=0 file_create=1 file_needs_ingestion=0 manual_review=0')
            ->assertSuccessful();

        $material = LessonMaterial::query()->sole();
        $material->forceFill(['is_published' => true])->save();

        $this->artisan('legacy:learning:file-materials:sync', array_merge([
            '--source-report' => $sourceReport,
            '--apply' => true,
            '--report' => $reportPath,
        ], $this->applyOptions($sourceReport)))
            ->expectsOutputToContain('Apply completed: considered=1 material_create=0 material_update=0 material_no_change=1 file_create=0 file_needs_ingestion=0 manual_review=0')
            ->assertSuccessful();

        $report = $this->readReport($reportPath);

        $this->assertSame('no_change', $report['items'][0]['material_action']);
        $this->assertSame('no_change', $report['items'][0]['file_action']);
        $this->assertTrue((bool) $material->refresh()->is_published);
        $this->assertSame(1, LessonMaterial::query()->count());
        $this->assertSame(1, LessonMaterialFile::query()->count());
        $this->assertCount(1, $validator->calls);
    }

    public function test_skipped_source_report_items_are_not_considered_for_sync(): void
    {
        $lesson = $this->createLesson();
        $sourceReport = $this->writeDryRunReport([
            $this->transferableItem($lesson, platformPageId: 503, fileId: 9003, occurrenceIndex: 0),
            [
                ...$this->transferableItem($lesson, platformPageId: 503, fileId: 9004, occurrenceIndex: 1),
                'result' => 'skipped',
                'reasons' => ['unsafe_pdf'],
            ],
        ]);
        $reportPath = storage_path('app/legacy-file-materials-sync-skipped-report.json');

        $this->artisan('legacy:learning:file-materials:sync', [
            '--source-report' => $sourceReport,
            '--report' => $reportPath,
        ])
            ->expectsOutputToContain('Dry-run completed: considered=1 material_create=1 material_update=0 material_no_change=0 file_create=0 file_needs_ingestion=1 manual_review=0')
            ->assertSuccessful();

        $report = $this->readReport($reportPath);

        $this->assertSame(1, $report['stats']['considered']);
        $this->assertCount(1, $report['items']);
        $this->assertSame(0, LessonMaterial::query()->count());
        $this->assertSame(0, LessonMaterialFile::query()->count());
    }

    public function test_apply_requires_matching_source_and_plan_hashes_before_writes(): void
    {
        $lesson = $this->createLesson();
        $sourceReport = $this->writeDryRunReport([
            $this->transferableItem($lesson, platformPageId: 510, fileId: 9012, occurrenceIndex: 0),
        ]);
        $reportPath = storage_path('app/legacy-file-materials-sync-confirmation-required-report.json');

        $this->artisan('legacy:learning:file-materials:sync', [
            '--source-report' => $sourceReport,
            '--apply' => true,
            '--report' => $reportPath,
        ])
            ->expectsOutputToContain('Missing or invalid --confirm-source-sha256')
            ->assertFailed();

        $options = $this->applyOptions($sourceReport);
        $options['--confirm-plan-sha256'] = str_repeat('0', 64);

        $this->artisan('legacy:learning:file-materials:sync', array_merge([
            '--source-report' => $sourceReport,
            '--apply' => true,
            '--report' => $reportPath,
        ], $options))
            ->expectsOutputToContain('Apply confirmation failed: --confirm-plan-sha256 does not match the current dry-run sync plan.')
            ->assertFailed();

        $this->assertSame(0, LessonMaterial::query()->count());
        $this->assertSame(0, LessonMaterialFile::query()->count());
        $this->assertSame(0, LegacyImportMapping::query()->where('batch_key', 'lesson_file_material_v1')->count());
    }

    public function test_occurrence_key_filter_processes_exact_subset_in_requested_order(): void
    {
        $lesson = $this->createLesson();
        $first = $this->transferableItem($lesson, platformPageId: 509, fileId: 9010, occurrenceIndex: 0);
        $second = $this->transferableItem($lesson, platformPageId: 509, fileId: 9011, occurrenceIndex: 1);
        $sourceReport = $this->writeDryRunReport([$first, $second]);
        $reportPath = storage_path('app/legacy-file-materials-sync-occurrence-filter-report.json');

        $this->artisan('legacy:learning:file-materials:sync', [
            '--source-report' => $sourceReport,
            '--occurrence-key' => [$second['occurrence_key'], $first['occurrence_key']],
            '--report' => $reportPath,
        ])
            ->expectsOutputToContain('Dry-run completed: considered=2 material_create=2 material_update=0 material_no_change=0 file_create=0 file_needs_ingestion=2 manual_review=0')
            ->assertSuccessful();

        $report = $this->readReport($reportPath);
        $reportedOccurrenceKeys = array_map(
            fn (array $item): string => $item['metadata']['dry_run_occurrence_key'],
            $report['items'],
        );

        $this->assertSame([$second['occurrence_key'], $first['occurrence_key']], $report['selection']['occurrence_keys']);
        $this->assertSame([$second['occurrence_key'], $first['occurrence_key']], $reportedOccurrenceKeys);
        $this->assertSame('chr/anatomy/konspekt-9011.pdf', $report['items'][0]['storage_relative_path']);
        $this->assertSame(0, LessonMaterial::query()->count());
    }

    public function test_occurrence_keys_file_processes_exact_subset(): void
    {
        $lesson = $this->createLesson();
        $first = $this->transferableItem($lesson, platformPageId: 510, fileId: 9012, occurrenceIndex: 0);
        $second = $this->transferableItem($lesson, platformPageId: 510, fileId: 9013, occurrenceIndex: 1);
        $sourceReport = $this->writeDryRunReport([$first, $second]);
        $keysFile = storage_path('app/legacy-file-materials-pilot-keys.json');
        File::put($keysFile, json_encode([
            'occurrence_keys' => [$second['occurrence_key']],
        ], JSON_THROW_ON_ERROR));
        $canonicalKeysFile = realpath($keysFile);
        $this->assertIsString($canonicalKeysFile);
        $reportPath = storage_path('app/legacy-file-materials-sync-occurrence-file-report.json');

        $this->artisan('legacy:learning:file-materials:sync', [
            '--source-report' => $sourceReport,
            '--occurrence-keys-file' => $canonicalKeysFile,
            '--report' => $reportPath,
        ])
            ->expectsOutputToContain('Dry-run completed: considered=1 material_create=1 material_update=0 material_no_change=0 file_create=0 file_needs_ingestion=1 manual_review=0')
            ->assertSuccessful();

        $report = $this->readReport($reportPath);

        $this->assertSame([$second['occurrence_key']], $report['selection']['occurrence_keys']);
        $this->assertSame($second['occurrence_key'], $report['items'][0]['metadata']['dry_run_occurrence_key']);
        $this->assertSame('chr/anatomy/konspekt-9013.pdf', $report['items'][0]['storage_relative_path']);
        $this->assertSame(0, LessonMaterial::query()->count());
    }

    public function test_limit_cannot_be_combined_with_occurrence_key_filter(): void
    {
        $lesson = $this->createLesson();
        $first = $this->transferableItem($lesson, platformPageId: 512, fileId: 9016, occurrenceIndex: 0);
        $second = $this->transferableItem($lesson, platformPageId: 512, fileId: 9017, occurrenceIndex: 1);
        $sourceReport = $this->writeDryRunReport([$first, $second]);
        $reportPath = storage_path('app/legacy-file-materials-sync-limit-filter-report.json');

        $this->artisan('legacy:learning:file-materials:sync', [
            '--source-report' => $sourceReport,
            '--occurrence-key' => [$first['occurrence_key'], $second['occurrence_key']],
            '--limit' => 1,
            '--report' => $reportPath,
        ])
            ->expectsOutputToContain('Do not combine --limit with explicit occurrence key filters.')
            ->assertFailed();

        $this->assertSame(0, LessonMaterial::query()->count());
        $this->assertSame(0, LegacyImportMapping::query()->where('batch_key', 'lesson_file_material_v1')->count());
    }

    public function test_limit_must_be_a_non_negative_integer(): void
    {
        $lesson = $this->createLesson();
        $sourceReport = $this->writeDryRunReport([
            $this->transferableItem($lesson, platformPageId: 513, fileId: 9018, occurrenceIndex: 0),
        ]);
        $reportPath = storage_path('app/legacy-file-materials-sync-invalid-limit-report.json');

        $this->artisan('legacy:learning:file-materials:sync', [
            '--source-report' => $sourceReport,
            '--limit' => 'not-a-number',
            '--report' => $reportPath,
        ])
            ->expectsOutputToContain('Option --limit must be a non-negative integer.')
            ->assertFailed();

        $this->assertSame(0, LessonMaterial::query()->count());
        $this->assertSame(0, LegacyImportMapping::query()->where('batch_key', 'lesson_file_material_v1')->count());
    }

    public function test_occurrence_key_filter_rejects_missing_or_non_transferable_keys(): void
    {
        $lesson = $this->createLesson();
        $transferable = $this->transferableItem($lesson, platformPageId: 511, fileId: 9014, occurrenceIndex: 0);
        $skipped = [
            ...$this->transferableItem($lesson, platformPageId: 511, fileId: 9015, occurrenceIndex: 1),
            'result' => 'skipped',
            'reasons' => ['unsafe_pdf'],
        ];
        $sourceReport = $this->writeDryRunReport([$transferable, $skipped]);
        $reportPath = storage_path('app/legacy-file-materials-sync-occurrence-missing-report.json');

        $this->artisan('legacy:learning:file-materials:sync', [
            '--source-report' => $sourceReport,
            '--occurrence-key' => [$skipped['occurrence_key']],
            '--report' => $reportPath,
        ])
            ->expectsOutputToContain('Requested occurrence key is not transferable in source report')
            ->assertFailed();

        $this->assertSame(0, LessonMaterial::query()->count());
        $this->assertSame(0, LegacyImportMapping::query()->where('batch_key', 'lesson_file_material_v1')->count());
    }

    public function test_scoped_duplicate_source_items_are_considered_separately(): void
    {
        $firstLesson = $this->createLesson();
        $secondLesson = $this->createLesson(
            subjectSlug: 'histology',
            courseSlug: 'cytology',
            lessonSlug: 'cells',
        );
        $sourceReport = $this->writeDryRunReport([
            $this->transferableItem($firstLesson, platformPageId: 507, fileId: 9008, occurrenceIndex: 0, productId: 7001),
            $this->transferableItem($secondLesson, platformPageId: 507, fileId: 9008, occurrenceIndex: 0, productId: 7002),
        ]);
        $reportPath = storage_path('app/legacy-file-materials-sync-scoped-duplicates-report.json');

        $this->artisan('legacy:learning:file-materials:sync', [
            '--source-report' => $sourceReport,
            '--report' => $reportPath,
        ])
            ->expectsOutputToContain('Dry-run completed: considered=2 material_create=2 material_update=0 material_no_change=0 file_create=0 file_needs_ingestion=2 manual_review=0')
            ->assertSuccessful();

        $report = $this->readReport($reportPath);
        $items = $report['items'];

        $this->assertSame(2, $report['stats']['considered']);
        $this->assertSame($items[0]['import_source_key'], $items[1]['import_source_key']);
        $this->assertNotSame($items[0]['idempotency_key'], $items[1]['idempotency_key']);
        $this->assertSame([
            LegacyImportMapping::SOURCE_SCOPE_LEGACY_PRODUCT,
            LegacyImportMapping::SOURCE_SCOPE_LEGACY_PRODUCT,
        ], array_column($items, 'source_scope_type'));
        $this->assertSame(['7001', '7002'], array_column($items, 'source_scope_id'));
        $this->assertSame(0, LessonMaterial::query()->count());
        $this->assertSame(0, LegacyImportMapping::query()->where('batch_key', 'lesson_file_material_v1')->count());
    }

    public function test_legacy_title_path_separators_are_sanitized_for_material_title(): void
    {
        $lesson = $this->createLesson();
        $sourceReport = $this->writeDryRunReport([
            [
                ...$this->transferableItem($lesson, platformPageId: 505, fileId: 9006, occurrenceIndex: 0),
                'title' => 'Артерии/вены \\ таблица',
            ],
        ]);
        $reportPath = storage_path('app/legacy-file-materials-sync-title-report.json');

        $this->artisan('legacy:learning:file-materials:sync', array_merge([
            '--source-report' => $sourceReport,
            '--apply' => true,
            '--report' => $reportPath,
        ], $this->applyOptions($sourceReport)))
            ->expectsOutputToContain('Apply completed: considered=1 material_create=1 material_update=0 material_no_change=0 file_create=0 file_needs_ingestion=1 manual_review=0')
            ->assertSuccessful();

        $this->assertSame('Артерии вены таблица', LessonMaterial::query()->sole()->title);
    }

    public function test_existing_ready_file_source_is_reported_as_no_change(): void
    {
        $lesson = $this->createLesson();
        $sourceItem = $this->transferableItem($lesson, platformPageId: 504, fileId: 9005, occurrenceIndex: 0);
        $sourceReport = $this->writeDryRunReport([$sourceItem]);
        $reportPath = storage_path('app/legacy-file-materials-sync-ready-report.json');

        $this->artisan('legacy:learning:file-materials:sync', array_merge([
            '--source-report' => $sourceReport,
            '--apply' => true,
            '--report' => $reportPath,
        ], $this->applyOptions($sourceReport)))->assertSuccessful();

        $material = LessonMaterial::query()->sole();
        LessonMaterialFile::query()->create([
            'lesson_material_id' => $material->id,
            'source_object_key' => 'private/legacy/material.pdf',
            'source_sha256' => $sourceItem['source_sha256'],
            'source_size_bytes' => $sourceItem['source_size_bytes'],
            'source_mime_type' => $sourceItem['source_mime_type'],
            'download_filename' => $sourceItem['download_filename'],
        ]);

        $this->artisan('legacy:learning:file-materials:sync', [
            '--source-report' => $sourceReport,
            '--report' => $reportPath,
        ])
            ->expectsOutputToContain('Dry-run completed: considered=1 material_create=0 material_update=0 material_no_change=1 file_create=0 file_needs_ingestion=0 manual_review=0')
            ->assertSuccessful();

        $report = $this->readReport($reportPath);

        $this->assertSame('no_change', $report['items'][0]['file_action']);
        $this->assertSame(1, LessonMaterial::query()->count());
        $this->assertSame(1, LessonMaterialFile::query()->count());
    }

    public function test_apply_reports_existing_file_source_mismatch_for_manual_review(): void
    {
        $lesson = $this->createLesson();
        $sourceItem = $this->transferableItem($lesson, platformPageId: 506, fileId: 9007, occurrenceIndex: 0);
        $sourceReport = $this->writeDryRunReport([$sourceItem]);
        $reportPath = storage_path('app/legacy-file-materials-sync-file-conflict-report.json');

        $this->artisan('legacy:learning:file-materials:sync', array_merge([
            '--source-report' => $sourceReport,
            '--apply' => true,
            '--report' => $reportPath,
        ], $this->applyOptions($sourceReport)))->assertSuccessful();

        $material = LessonMaterial::query()->sole();
        LessonMaterialFile::query()->create([
            'lesson_material_id' => $material->id,
            'source_object_key' => 'private/legacy/conflict.pdf',
            'source_sha256' => str_repeat('b', 64),
            'source_size_bytes' => $sourceItem['source_size_bytes'],
            'source_mime_type' => $sourceItem['source_mime_type'],
            'download_filename' => $sourceItem['download_filename'],
        ]);

        $this->artisan('legacy:learning:file-materials:sync', array_merge([
            '--source-report' => $sourceReport,
            '--apply' => true,
            '--report' => $reportPath,
        ], $this->applyOptions($sourceReport)))
            ->expectsOutputToContain('Apply completed: considered=1 material_create=0 material_update=0 material_no_change=0 file_create=0 file_needs_ingestion=0 manual_review=1')
            ->assertSuccessful();

        $report = $this->readReport($reportPath);

        $this->assertSame('manual_review', $report['items'][0]['file_action']);
        $this->assertSame('manual_review', $report['items'][0]['material_action']);
        $this->assertSame(['file_source_mismatch'], $report['items'][0]['manual_review_reasons']);
        $this->assertSame(1, LessonMaterial::query()->count());
        $this->assertSame(1, LessonMaterialFile::query()->count());
    }

    public function test_apply_reports_existing_file_source_object_key_mismatch_for_manual_review(): void
    {
        $lesson = $this->createLesson();
        $sourceItem = $this->transferableItem(
            $lesson,
            platformPageId: 506,
            fileId: 9017,
            occurrenceIndex: 0,
            sourceObjectKey: 'protected-sources/9017/current.pdf',
        );
        $validator = new class extends ProtectedFileSourceValidator
        {
            public function __construct() {}

            public function validate(
                string $objectKey,
                string $expectedHash,
                int $expectedSize,
                string $mimeType,
                string $filename,
            ): array {
                return [
                    'output_sha256' => str_repeat('b', 64),
                    'output_size_bytes' => 1024,
                ];
            }
        };
        $this->app->instance(ProtectedFileSourceValidator::class, $validator);
        $sourceReport = $this->writeDryRunReport([$sourceItem]);
        $reportPath = storage_path('app/legacy-file-materials-sync-file-object-key-conflict-report.json');

        $this->artisan('legacy:learning:file-materials:sync', array_merge([
            '--source-report' => $sourceReport,
            '--apply' => true,
            '--report' => $reportPath,
        ], $this->applyOptions($sourceReport)))->assertSuccessful();

        LessonMaterialFile::query()->sole()->forceFill([
            'source_object_key' => 'protected-sources/9017/stale.pdf',
        ])->save();

        $this->artisan('legacy:learning:file-materials:sync', array_merge([
            '--source-report' => $sourceReport,
            '--apply' => true,
            '--report' => $reportPath,
        ], $this->applyOptions($sourceReport)))
            ->expectsOutputToContain('Apply completed: considered=1 material_create=0 material_update=0 material_no_change=0 file_create=0 file_needs_ingestion=0 manual_review=1')
            ->assertSuccessful();

        $report = $this->readReport($reportPath);

        $this->assertSame('manual_review', $report['items'][0]['file_action']);
        $this->assertSame(['file_source_mismatch'], $report['items'][0]['manual_review_reasons']);
        $this->assertSame('protected-sources/9017/stale.pdf', LessonMaterialFile::query()->sole()->source_object_key);
    }

    public function test_apply_does_not_clear_existing_blocked_mapping_with_target_id(): void
    {
        $lesson = $this->createLesson();
        $sourceReport = $this->writeDryRunReport([
            $this->transferableItem($lesson, platformPageId: 507, fileId: 9008, occurrenceIndex: 0),
        ]);
        $reportPath = storage_path('app/legacy-file-materials-sync-blocked-mapping-report.json');

        $this->artisan('legacy:learning:file-materials:sync', array_merge([
            '--source-report' => $sourceReport,
            '--apply' => true,
            '--report' => $reportPath,
        ], $this->applyOptions($sourceReport)))->assertSuccessful();

        $mapping = LegacyImportMapping::query()->where('batch_key', 'lesson_file_material_v1')->sole();
        $mapping->forceFill([
            'mapping_status' => LegacyImportMapping::MAPPING_BLOCKED,
            'validation_status' => LegacyImportMapping::VALIDATION_MANUAL_REVIEW,
            'manual_review_reason' => 'owner_check_required',
        ])->save();

        $this->artisan('legacy:learning:file-materials:sync', array_merge([
            '--source-report' => $sourceReport,
            '--apply' => true,
            '--report' => $reportPath,
        ], $this->applyOptions($sourceReport)))
            ->expectsOutputToContain('Apply completed: considered=1 material_create=0 material_update=0 material_no_change=0 file_create=0 file_needs_ingestion=0 manual_review=1')
            ->assertSuccessful();

        $report = $this->readReport($reportPath);
        $mapping->refresh();

        $this->assertSame(['existing_mapping_requires_review'], $report['items'][0]['manual_review_reasons']);
        $this->assertSame(LegacyImportMapping::MAPPING_BLOCKED, $mapping->mapping_status);
        $this->assertSame(LegacyImportMapping::VALIDATION_MANUAL_REVIEW, $mapping->validation_status);
        $this->assertSame('owner_check_required', $mapping->manual_review_reason);
    }

    public function test_apply_reports_source_fingerprint_drift_before_source_ingestion(): void
    {
        $lesson = $this->createLesson();
        $sourceItem = $this->transferableItem($lesson, platformPageId: 508, fileId: 9009, occurrenceIndex: 0);
        $sourceReport = $this->writeDryRunReport([$sourceItem]);
        $reportPath = storage_path('app/legacy-file-materials-sync-fingerprint-drift-report.json');

        $this->artisan('legacy:learning:file-materials:sync', array_merge([
            '--source-report' => $sourceReport,
            '--apply' => true,
            '--report' => $reportPath,
        ], $this->applyOptions($sourceReport)))->assertSuccessful();

        $mapping = LegacyImportMapping::query()->where('batch_key', 'lesson_file_material_v1')->sole();
        $originalFingerprint = $mapping->source_fingerprint_hash;
        $driftedReport = $this->writeDryRunReport([[
            ...$sourceItem,
            'source_sha256' => str_repeat('c', 64),
        ]]);

        $this->artisan('legacy:learning:file-materials:sync', array_merge([
            '--source-report' => $driftedReport,
            '--apply' => true,
            '--report' => $reportPath,
        ], $this->applyOptions($driftedReport)))
            ->expectsOutputToContain('Apply completed: considered=1 material_create=0 material_update=0 material_no_change=0 file_create=0 file_needs_ingestion=0 manual_review=1')
            ->assertSuccessful();

        $report = $this->readReport($reportPath);
        $mapping->refresh();

        $this->assertSame(['source_fingerprint_drift'], $report['items'][0]['manual_review_reasons']);
        $this->assertSame($originalFingerprint, $mapping->source_fingerprint_hash);
        $this->assertSame(1, LessonMaterial::query()->count());
        $this->assertSame(0, LessonMaterialFile::query()->count());
    }

    private function createLesson(
        string $subjectSlug = 'anatomy',
        string $courseSlug = 'osteology',
        string $lessonSlug = 'bones',
    ): Lesson {
        $subject = Subject::query()->create([
            'slug' => $subjectSlug,
            'title' => 'Анатомия',
        ]);
        $course = Course::query()->create([
            'subject_id' => $subject->id,
            'slug' => $courseSlug,
            'title' => 'Остеология',
            'price_cents' => 100000,
        ]);

        return Lesson::query()->create([
            'course_id' => $course->id,
            'slug' => $lessonSlug,
            'title' => 'Кости',
        ]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     */
    private function writeDryRunReport(array $items): string
    {
        $path = storage_path('app/legacy-file-materials-dry-run-'.Str::uuid().'.json');
        File::put($path, json_encode([
            'mode' => 'dry-run',
            'source_table' => 'platform_page',
            'target_tables' => [
                'lesson_materials',
                'lesson_material_files',
            ],
            'stats' => [
                'occurrences' => count($items),
            ],
            'items' => $items,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $canonicalPath = realpath($path);
        $this->assertIsString($canonicalPath);

        return $canonicalPath;
    }

    /**
     * @return array<string, string>
     */
    private function applyOptions(string $sourceReport): array
    {
        $reportPath = storage_path('app/legacy-file-materials-sync-confirm-'.Str::uuid().'.json');

        $this->artisan('legacy:learning:file-materials:sync', [
            '--source-report' => $sourceReport,
            '--report' => $reportPath,
        ])->assertSuccessful();

        $report = $this->readReport($reportPath);
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', $report['source_report_sha256']);
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', $report['plan_sha256']);

        return [
            '--confirm-source-sha256' => $report['source_report_sha256'],
            '--confirm-plan-sha256' => $report['plan_sha256'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function transferableItem(
        Lesson $lesson,
        int $platformPageId,
        int $fileId,
        int $occurrenceIndex,
        int $byteOffset = 100,
        ?int $productId = null,
        ?string $sourceObjectKey = null,
    ): array {
        $item = [
            'occurrence_key' => hash('sha256', "{$platformPageId}:{$fileId}:{$occurrenceIndex}"),
            'platform_page_id' => $platformPageId,
            'product_id' => $productId,
            'occurrence_index' => $occurrenceIndex,
            'byte_offset' => $byteOffset,
            'legacy_file_id' => $fileId,
            'lesson_id' => $lesson->id,
            'subject_slug' => 'anatomy',
            'course_slug' => 'osteology',
            'lesson_slug' => 'bones',
            'title' => 'Конспект к уроку',
            'download_filename' => 'konspekt.pdf',
            'source_size_bytes' => 512,
            'source_mime_type' => 'application/pdf',
            'source_sha256' => str_repeat('a', 64),
            'storage_relative_path' => "chr/anatomy/konspekt-{$fileId}.pdf",
            'technical_format' => 'pdf',
            'learning_role' => 'notes',
            'lesson_material_kind_candidate' => 'pdf',
            'pdf_risk_markers' => [],
            'result' => 'transferable',
            'reasons' => [],
            'warnings' => [],
            'manual_review' => false,
            'checks' => [
                'lesson_mapping' => 'matched',
                'file_owner' => 'matched',
                'physical_file' => 'ok',
                'pdf_policy' => 'pass',
            ],
        ];

        if ($sourceObjectKey !== null) {
            $item['source_object_key'] = $sourceObjectKey;
        }

        return $item;
    }

    /**
     * @return array<string, mixed>
     */
    private function readReport(string $path): array
    {
        $report = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($report);

        return $report;
    }
}
