<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\LegacyImportMapping;
use App\Models\Lesson;
use App\Models\LessonLearningContent;
use App\Models\LessonMaterial;
use App\Models\LessonMaterialFile;
use App\Models\Subject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\TestCase;

class LegacyLessonFileMaterialsDryRunCommandTest extends TestCase
{
    use RefreshDatabase;

    private string $storageRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $systemTemp = realpath(sys_get_temp_dir());
        $this->assertIsString($systemTemp);
        $this->storageRoot = $systemTemp.'/cms-legacy-file-materials-'.Str::uuid();
        File::makeDirectory($this->storageRoot, 0700);
        $canonicalRoot = realpath($this->storageRoot);
        $this->assertIsString($canonicalRoot);
        $this->storageRoot = $canonicalRoot;

        config()->set('protected_files.max_source_ingest_bytes', 2 * 1024 * 1024);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->storageRoot);

        parent::tearDown();
    }

    public function test_storage_root_dry_run_reports_transferable_pdf_without_database_writes(): void
    {
        $lesson = $this->createImportedLesson(platformPageId: 501, productId: 9001);
        $relativePath = $this->createPdf('chr/files/konspekt.pdf', 'Конспект');
        $sourcePath = $this->writeSourceJson([
            $this->sourceRow(
                platformPageId: 501,
                productId: 9001,
                occurrenceIndex: 0,
                fileId: 990099,
                relativePath: $relativePath,
                title: 'Конспект к уроку',
                declaredSizeBytes: filesize($this->storageRoot.'/'.$relativePath),
            ),
        ]);
        $reportPath = storage_path('app/legacy-file-materials-dry-run-report.json');

        $this->artisan('legacy:learning:file-materials:dry-run', [
            '--source-json' => $sourcePath,
            '--storage-root' => $this->storageRoot,
            '--report' => $reportPath,
        ])
            ->expectsOutputToContain('Dry-run completed: source_rows=1 occurrences=1 transferable=1 skipped=0 manual_review=0')
            ->assertSuccessful();

        $report = $this->readReport($reportPath);
        $item = $report['items'][0];

        $this->assertSame('dry-run', $report['mode']);
        $this->assertSame('storage-root', $report['physical_check_mode']);
        $this->assertSame(1, $report['stats']['transferable']);
        $this->assertSame(0, $report['stats']['skipped']);
        $this->assertSame(1, $report['stats']['distinct_legacy_file_ids']);
        $this->assertSame($lesson->id, $item['lesson_id']);
        $this->assertSame('pdf', $item['technical_format']);
        $this->assertSame('notes', $item['learning_role']);
        $this->assertSame('pdf', $item['lesson_material_kind_candidate']);
        $this->assertSame('transferable', $item['result']);
        $this->assertSame([], $item['reasons']);
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', $item['source_sha256']);
        $this->assertSame(0, LessonMaterial::query()->count());
        $this->assertSame(0, LessonMaterialFile::query()->count());
        $this->assertReportDoesNotLeakRawPayload($report, [
            $sourcePath,
            $this->storageRoot,
        ]);
    }

    public function test_dry_run_accepts_jsonl_source_that_starts_with_object_line(): void
    {
        $this->createImportedLesson(platformPageId: 504, productId: 9004);
        $relativePath = $this->createPdf('chr/files/jsonl.pdf', 'JSONL');
        $sourcePath = $this->writeSourceJsonLines([
            $this->sourceRow(
                platformPageId: 504,
                productId: 9004,
                occurrenceIndex: 0,
                fileId: 990104,
                relativePath: $relativePath,
                declaredSizeBytes: filesize($this->storageRoot.'/'.$relativePath),
            ),
        ]);
        $reportPath = storage_path('app/legacy-file-materials-jsonl-report.json');

        $this->artisan('legacy:learning:file-materials:dry-run', [
            '--source-json' => $sourcePath,
            '--storage-root' => $this->storageRoot,
            '--report' => $reportPath,
        ])
            ->expectsOutputToContain('Dry-run completed: source_rows=1 occurrences=1 transferable=1 skipped=0 manual_review=0')
            ->assertSuccessful();

        $report = $this->readReport($reportPath);

        $this->assertSame('transferable', $report['items'][0]['result']);
        $this->assertSame(0, LessonMaterial::query()->count());
        $this->assertSame(0, LessonMaterialFile::query()->count());
    }

    public function test_storage_root_dry_run_reports_skip_reasons_without_writes(): void
    {
        $this->createImportedLesson(platformPageId: 502);
        $ownerMismatch = $this->createPdf('chr/files/owner-mismatch.pdf', 'Owner mismatch');
        $unsafePdf = $this->createPdf('chr/files/unsafe.pdf', 'Unsafe').'.tmp';
        File::move($this->storageRoot.'/chr/files/unsafe.pdf', $this->storageRoot.'/'.$unsafePdf);
        file_put_contents($this->storageRoot.'/'.$unsafePdf, $this->pdfBytes('Unsafe')."\n/JavaScript");
        chmod($this->storageRoot.'/'.$unsafePdf, 0600);
        $textPath = 'chr/files/plain.txt';
        File::ensureDirectoryExists(dirname($this->storageRoot.'/'.$textPath));
        file_put_contents($this->storageRoot.'/'.$textPath, 'plain text');
        chmod($this->storageRoot.'/'.$textPath, 0600);

        $sourcePath = $this->writeSourceJson([
            $this->sourceRow(502, null, 0, 1001, $ownerMismatch, itemId: 999),
            $this->sourceRow(502, null, 1, 1002, null, fileRowFound: false),
            $this->sourceRow(502, null, 2, 1003, 'chr/files/missing.pdf'),
            $this->sourceRow(502, null, 3, 1004, $unsafePdf),
            $this->sourceRow(502, null, 4, 1005, $textPath, title: 'Дополнительный файл'),
        ]);
        $reportPath = storage_path('app/legacy-file-materials-skip-report.json');

        $this->artisan('legacy:learning:file-materials:dry-run', [
            '--source-json' => $sourcePath,
            '--storage-root' => $this->storageRoot,
            '--report' => $reportPath,
        ])
            ->expectsOutputToContain('Dry-run completed: source_rows=5 occurrences=5 transferable=2 skipped=3 manual_review=0')
            ->assertSuccessful();

        $report = $this->readReport($reportPath);

        $this->assertSame(3, $report['stats']['skipped']);
        $this->assertSame(2, $report['stats']['transferable']);
        $this->assertArrayNotHasKey('owner_mismatch', $report['stats']['skip_reasons']);
        $this->assertSame(3, $report['stats']['with_warnings']);
        $this->assertSame(1, $report['stats']['warning_reasons']['owner_mismatch']);
        $this->assertSame(1, $report['stats']['warning_reasons']['owner_unverified']);
        $this->assertSame(1, $report['stats']['warning_reasons']['pdf_delivery_review_required']);
        $this->assertSame('transferable', $report['items'][0]['result']);
        $this->assertSame(['owner_mismatch'], $report['items'][0]['warnings']);
        $this->assertSame(1, $report['stats']['skip_reasons']['missing_row']);
        $this->assertSame(1, $report['stats']['skip_reasons']['missing_file']);
        $this->assertSame(1, $report['stats']['skip_reasons']['unsupported_mime']);
        $this->assertSame('transferable', $report['items'][3]['result']);
        $this->assertSame(['/JavaScript'], $report['items'][3]['pdf_risk_markers']);
        $this->assertSame(['pdf_delivery_review_required'], $report['items'][3]['warnings']);
        $this->assertSame('delivery_review_required', $report['items'][3]['checks']['pdf_policy']);
        $this->assertSame(0, LessonMaterial::query()->count());
        $this->assertSame(0, LessonMaterialFile::query()->count());
    }

    public function test_manifest_dry_run_uses_exported_physical_metadata_when_storage_root_is_absent(): void
    {
        $this->createImportedLesson(platformPageId: 601, productId: 9601);
        $sourcePath = $this->writeSourceJson([
            [
                'platform_page_id' => 601,
                'product_id' => 9601,
                'occurrence_index' => 0,
                'byte_offset' => 120,
                'legacy_file_id' => 1601,
                'file' => [
                    'item_name' => 'platform_page',
                    'item_id' => 601,
                    'isRemoved' => null,
                    'title' => 'PDF материал',
                    'storage_relative_path' => 'chr/files/manifest.pdf',
                ],
                'storage' => [
                    'exists' => true,
                    'regular_file' => true,
                    'symlink' => false,
                    'size_bytes' => 512,
                    'mime_type' => 'application/pdf',
                    'sha256' => str_repeat('a', 64),
                    'header' => '%PDF-',
                ],
                'pdf_policy' => [
                    'checked' => true,
                    'risk_markers' => [],
                ],
            ],
        ]);
        $reportPath = storage_path('app/legacy-file-materials-manifest-report.json');

        $this->artisan('legacy:learning:file-materials:dry-run', [
            '--source-json' => $sourcePath,
            '--report' => $reportPath,
        ])
            ->expectsOutputToContain('Dry-run completed: source_rows=1 occurrences=1 transferable=1 skipped=0 manual_review=0')
            ->assertSuccessful();

        $report = $this->readReport($reportPath);

        $this->assertSame('manifest', $report['physical_check_mode']);
        $this->assertSame('transferable', $report['items'][0]['result']);
        $this->assertSame(str_repeat('a', 64), $report['items'][0]['source_sha256']);
        $this->assertSame(0, LessonMaterial::query()->count());
        $this->assertSame(0, LessonMaterialFile::query()->count());
    }

    public function test_manifest_dry_run_archives_pdf_when_pdf_policy_was_not_checked(): void
    {
        $this->createImportedLesson(platformPageId: 602, productId: 9602);
        $sourcePath = $this->writeSourceJson([
            [
                'platform_page_id' => 602,
                'product_id' => 9602,
                'occurrence_index' => 0,
                'legacy_file_id' => 1602,
                'file' => [
                    'item_name' => 'platform_page',
                    'item_id' => 602,
                    'isRemoved' => null,
                    'title' => 'PDF без policy check',
                    'storage_relative_path' => 'chr/files/unverified.pdf',
                ],
                'storage' => [
                    'exists' => true,
                    'regular_file' => true,
                    'symlink' => false,
                    'size_bytes' => 512,
                    'mime_type' => 'application/pdf',
                    'sha256' => str_repeat('c', 64),
                    'header' => '%PDF-',
                ],
                'pdf_policy' => [
                    'checked' => false,
                    'risk_markers' => [],
                ],
            ],
        ]);
        $reportPath = storage_path('app/legacy-file-materials-unverified-policy-report.json');

        $this->artisan('legacy:learning:file-materials:dry-run', [
            '--source-json' => $sourcePath,
            '--report' => $reportPath,
        ])
            ->expectsOutputToContain('Dry-run completed: source_rows=1 occurrences=1 transferable=1 skipped=0 manual_review=0')
            ->assertSuccessful();

        $report = $this->readReport($reportPath);

        $this->assertSame('transferable', $report['items'][0]['result']);
        $this->assertSame([], $report['items'][0]['reasons']);
        $this->assertSame(['pdf_delivery_review_required'], $report['items'][0]['warnings']);
        $this->assertSame(['pdf_policy_unverified'], $report['items'][0]['pdf_risk_markers']);
        $this->assertSame('delivery_review_required', $report['items'][0]['checks']['pdf_policy']);
        $this->assertSame(0, LessonMaterial::query()->count());
        $this->assertSame(0, LessonMaterialFile::query()->count());
    }

    public function test_storage_relative_path_prefers_legacy_local_name_for_source_filename(): void
    {
        $this->createImportedLesson(platformPageId: 605, productId: 9605);
        $relativePath = $this->createPdf('chr/Артрология_чек-лист.pdf', 'Checklist');
        $sourcePath = $this->writeSourceJson([
            [
                'platform_page_id' => 605,
                'product_id' => 9605,
                'occurrence_index' => 0,
                'legacy_file_id' => 1605,
                'file_row_found' => true,
                'file' => [
                    'item_name' => 'platform_page',
                    'item_id' => 605,
                    'isRemoved' => null,
                    'title' => 'Артрология чек-лист',
                    'dir' => '/Артрология чек-лист.pdf',
                    'local_name' => basename($relativePath),
                    'storage_relative_path' => 'chr/Артрология чек-лист.pdf',
                ],
            ],
        ]);
        $reportPath = storage_path('app/legacy-file-materials-local-name-report.json');

        $this->artisan('legacy:learning:file-materials:dry-run', [
            '--source-json' => $sourcePath,
            '--storage-root' => $this->storageRoot,
            '--report' => $reportPath,
        ])
            ->expectsOutputToContain('Dry-run completed: source_rows=1 occurrences=1 transferable=1 skipped=0 manual_review=0')
            ->assertSuccessful();

        $report = $this->readReport($reportPath);

        $this->assertSame($relativePath, $report['items'][0]['storage_relative_path']);
        $this->assertSame('transferable', $report['items'][0]['result']);
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', $report['items'][0]['source_sha256']);
    }

    public function test_manifest_dry_run_skips_unsafe_relative_path(): void
    {
        $this->createImportedLesson(platformPageId: 603, productId: 9603);
        $sourcePath = $this->writeSourceJson([
            [
                'platform_page_id' => 603,
                'product_id' => 9603,
                'occurrence_index' => 0,
                'legacy_file_id' => 1603,
                'file' => [
                    'item_name' => 'platform_page',
                    'item_id' => 603,
                    'isRemoved' => null,
                    'title' => 'PDF с unsafe path',
                    'storage_relative_path' => '../secret.pdf',
                ],
                'storage' => [
                    'exists' => true,
                    'regular_file' => true,
                    'symlink' => false,
                    'size_bytes' => 512,
                    'mime_type' => 'application/pdf',
                    'sha256' => str_repeat('d', 64),
                    'header' => '%PDF-',
                ],
                'pdf_policy' => [
                    'checked' => true,
                    'risk_markers' => [],
                ],
            ],
        ]);
        $reportPath = storage_path('app/legacy-file-materials-unsafe-path-report.json');

        $this->artisan('legacy:learning:file-materials:dry-run', [
            '--source-json' => $sourcePath,
            '--report' => $reportPath,
        ])
            ->expectsOutputToContain('Dry-run completed: source_rows=1 occurrences=1 transferable=0 skipped=1 manual_review=0')
            ->assertSuccessful();

        $report = $this->readReport($reportPath);

        $this->assertSame(['unsafe_path'], $report['items'][0]['reasons']);
        $this->assertSame('unsafe_path', $report['items'][0]['checks']['physical_file']);
        $this->assertSame(0, LessonMaterial::query()->count());
        $this->assertSame(0, LessonMaterialFile::query()->count());
    }

    public function test_manifest_dry_run_requires_explicit_regular_file_and_symlink_flags(): void
    {
        $this->createImportedLesson(platformPageId: 604, productId: 9604);
        $sourcePath = $this->writeSourceJson([
            [
                'platform_page_id' => 604,
                'product_id' => 9604,
                'occurrence_index' => 0,
                'legacy_file_id' => 1604,
                'file' => [
                    'item_name' => 'platform_page',
                    'item_id' => 604,
                    'isRemoved' => null,
                    'title' => 'PDF без file type proof',
                    'storage_relative_path' => 'chr/files/no-file-type-proof.pdf',
                ],
                'storage' => [
                    'exists' => true,
                    'size_bytes' => 512,
                    'mime_type' => 'application/pdf',
                    'sha256' => str_repeat('e', 64),
                    'header' => '%PDF-',
                ],
                'pdf_policy' => [
                    'checked' => true,
                    'risk_markers' => [],
                ],
            ],
        ]);
        $reportPath = storage_path('app/legacy-file-materials-missing-file-type-proof-report.json');

        $this->artisan('legacy:learning:file-materials:dry-run', [
            '--source-json' => $sourcePath,
            '--report' => $reportPath,
        ])
            ->expectsOutputToContain('Dry-run completed: source_rows=1 occurrences=1 transferable=0 skipped=1 manual_review=0')
            ->assertSuccessful();

        $report = $this->readReport($reportPath);

        $this->assertSame(['unsafe_path'], $report['items'][0]['reasons']);
        $this->assertSame('unsafe_path', $report['items'][0]['checks']['physical_file']);
        $this->assertSame(0, LessonMaterial::query()->count());
        $this->assertSame(0, LessonMaterialFile::query()->count());
    }

    public function test_unscoped_source_is_manual_review_when_legacy_page_maps_to_multiple_lessons(): void
    {
        $this->createImportedLesson(platformPageId: 701, productId: 9701, lessonSlug: 'first');
        $this->createImportedLesson(platformPageId: 701, productId: 9702, lessonSlug: 'second');
        $sourcePath = $this->writeSourceJson([
            [
                'platform_page_id' => 701,
                'occurrence_index' => 0,
                'legacy_file_id' => 1701,
                'file' => [
                    'item_name' => 'platform_page',
                    'item_id' => 701,
                    'isRemoved' => null,
                    'title' => 'PDF без product scope',
                    'storage_relative_path' => 'chr/files/unscoped.pdf',
                ],
                'storage' => [
                    'exists' => true,
                    'regular_file' => true,
                    'symlink' => false,
                    'size_bytes' => 512,
                    'mime_type' => 'application/pdf',
                    'sha256' => str_repeat('b', 64),
                    'header' => '%PDF-',
                ],
                'pdf_policy' => [
                    'checked' => true,
                    'risk_markers' => [],
                ],
            ],
        ]);
        $reportPath = storage_path('app/legacy-file-materials-ambiguous-report.json');

        $this->artisan('legacy:learning:file-materials:dry-run', [
            '--source-json' => $sourcePath,
            '--report' => $reportPath,
        ])
            ->expectsOutputToContain('Dry-run completed: source_rows=1 occurrences=1 transferable=0 skipped=1 manual_review=1')
            ->assertSuccessful();

        $report = $this->readReport($reportPath);

        $this->assertSame(['ambiguous_lesson'], $report['items'][0]['reasons']);
        $this->assertTrue($report['items'][0]['manual_review']);
        $this->assertSame(1, $report['stats']['skip_reasons']['ambiguous_lesson']);
        $this->assertSame(0, LessonMaterial::query()->count());
        $this->assertSame(0, LessonMaterialFile::query()->count());
    }

    private function createImportedLesson(
        int $platformPageId,
        ?int $productId = null,
        ?string $lessonSlug = null,
    ): Lesson {
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
            'slug' => $lessonSlug ?? "lesson-{$suffix}",
            'title' => 'Урок',
        ]);
        $content = LessonLearningContent::query()->create([
            'lesson_id' => $lesson->id,
            'status' => LessonLearningContent::STATUS_DRAFT,
            'format' => LessonLearningContent::FORMAT_LESSON_BLOCKS_V1,
            'version' => 1,
            'body' => [
                'blocks' => [],
                'toc' => [],
            ],
            'source_table' => 'platform_page',
            'source_id' => (string) $platformPageId,
            'source_fingerprint_hash' => hash('sha256', "platform-page-{$platformPageId}-{$suffix}"),
            'import_status' => LessonLearningContent::IMPORT_STATUS_IMPORTED,
            'metadata' => [
                'legacy_source_scope_type' => $productId === null
                    ? LegacyImportMapping::SOURCE_SCOPE_ID_ONLY
                    : LegacyImportMapping::SOURCE_SCOPE_LEGACY_PRODUCT,
                'legacy_source_scope_id' => $productId === null
                    ? LegacyImportMapping::SOURCE_SCOPE_ID_ONLY
                    : (string) $productId,
            ],
        ]);

        LegacyImportMapping::query()->create([
            'batch_key' => 'lesson_body_v1',
            'source_table' => 'platform_page',
            'source_id' => (string) $platformPageId,
            'source_scope_type' => $productId === null
                ? LegacyImportMapping::SOURCE_SCOPE_ID_ONLY
                : LegacyImportMapping::SOURCE_SCOPE_LEGACY_PRODUCT,
            'source_scope_id' => $productId === null
                ? LegacyImportMapping::SOURCE_SCOPE_ID_ONLY
                : (string) $productId,
            'target_domain' => 'learning_lesson_body',
            'target_type' => 'lesson_learning_content',
            'target_id' => $content->id,
            'mapping_status' => LegacyImportMapping::MAPPING_MAPPED,
            'validation_status' => LegacyImportMapping::VALIDATION_PASSED,
            'idempotency_key' => hash('sha256', "mapping-{$platformPageId}-{$productId}-{$suffix}"),
            'source_fingerprint_hash' => $content->source_fingerprint_hash,
        ]);

        return $lesson;
    }

    private function createPdf(string $relativePath, string $text): string
    {
        $path = $this->storageRoot.'/'.$relativePath;
        File::ensureDirectoryExists(dirname($path));
        file_put_contents($path, $this->pdfBytes($text));
        chmod($path, 0600);

        return $relativePath;
    }

    private function pdfBytes(string $text): string
    {
        $pdf = new \FPDF;
        $pdf->AddPage();
        $pdf->SetFont('Helvetica', '', 12);
        $pdf->Cell(40, 10, $text);

        return $pdf->Output('S');
    }

    private function writeSourceJson(array $rows): string
    {
        $path = storage_path('app/legacy-file-materials-source-'.Str::uuid().'.json');
        File::put($path, json_encode(['occurrences' => $rows], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $canonicalPath = realpath($path);
        $this->assertIsString($canonicalPath);

        return $canonicalPath;
    }

    private function writeSourceJsonLines(array $rows): string
    {
        $path = storage_path('app/legacy-file-materials-source-'.Str::uuid().'.jsonl');
        File::put(
            $path,
            collect($rows)
                ->map(fn (array $row): string => json_encode($row, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR))
                ->implode(PHP_EOL).PHP_EOL,
        );
        $canonicalPath = realpath($path);
        $this->assertIsString($canonicalPath);

        return $canonicalPath;
    }

    private function sourceRow(
        int $platformPageId,
        ?int $productId,
        int $occurrenceIndex,
        int $fileId,
        ?string $relativePath,
        ?string $title = 'PDF материал',
        ?int $declaredSizeBytes = null,
        ?int $itemId = null,
        bool $fileRowFound = true,
    ): array {
        return [
            'platform_page_id' => $platformPageId,
            'product_id' => $productId,
            'occurrence_index' => $occurrenceIndex,
            'byte_offset' => 100 + $occurrenceIndex,
            'legacy_file_id' => $fileId,
            'file_row_found' => $fileRowFound,
            'file' => $fileRowFound ? [
                'item_name' => 'platform_page',
                'item_id' => $itemId ?? $platformPageId,
                'isRemoved' => null,
                'title' => $title,
                'storage_relative_path' => $relativePath,
                'size_bytes' => $declaredSizeBytes,
            ] : null,
        ];
    }

    private function readReport(string $reportPath): array
    {
        $payload = json_decode((string) file_get_contents($reportPath), true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($payload);

        return $payload;
    }

    /** @param list<string|false> $forbidden */
    private function assertReportDoesNotLeakRawPayload(array $report, array $forbidden): void
    {
        $payload = json_encode($report, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        foreach ($forbidden as $value) {
            if (is_string($value) && $value !== '') {
                $this->assertStringNotContainsString($value, $payload);
            }
        }
    }
}
