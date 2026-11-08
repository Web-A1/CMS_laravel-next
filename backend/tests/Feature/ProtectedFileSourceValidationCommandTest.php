<?php

namespace Tests\Feature;

use App\Contracts\ProtectedFiles\ProtectedPdfSourceNormalizer;
use App\Contracts\ProtectedFiles\ProtectedPdfWatermarker;
use App\Exceptions\ProtectedFileGenerationException;
use App\Models\Course;
use App\Models\LegacyImportMapping;
use App\Models\Lesson;
use App\Models\LessonMaterial;
use App\Models\LessonMaterialFile;
use App\Models\ProtectedFileDelivery;
use App\Models\Subject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class ProtectedFileSourceValidationCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('protected-source');
        Storage::fake('protected-output');
        config()->set('protected_files.source_disk', 'protected-source');
        config()->set('protected_files.output_disk', 'protected-output');
    }

    public function test_default_source_validation_limit_matches_source_ingest_limit(): void
    {
        // Read the actual defaults without loading the developer's .env or changing this process.
        $process = new Process([PHP_BINARY, '-r', <<<'PHP'
            require 'vendor/autoload.php';
            echo json_encode(require 'config/protected_files.php', JSON_THROW_ON_ERROR);
            PHP,
        ], base_path(), ['PROTECTED_FILES_MAX_SOURCE_BYTES' => false]);
        $process->mustRun();
        $defaults = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(300 * 1024 * 1024, $defaults['max_source_bytes']);
        $this->assertSame(
            $defaults['max_source_ingest_bytes'],
            $defaults['max_source_bytes'],
        );
    }

    public function test_material_selection_validates_pdf_through_watermarker_without_delivery_or_output_writes(): void
    {
        $watermarker = new class implements ProtectedPdfWatermarker
        {
            public int $calls = 0;

            public function watermark(mixed $source, string $watermark, int $maxPages): string
            {
                $this->calls++;

                return "%PDF-validated {$watermark} {$maxPages}";
            }
        };
        $this->app->instance(ProtectedPdfWatermarker::class, $watermarker);
        $material = $this->createPdfMaterial();
        $reportPath = storage_path('app/protected-file-source-validation-success-report.json');

        $this->artisan('legacy:learning:materials:validate-pdf-sources', [
            '--material' => [$material->id],
            '--report' => $reportPath,
        ])
            ->expectsOutputToContain('Validation completed: sources=1 passed=1 failed=0 selected_materials=1')
            ->assertSuccessful();

        $report = $this->readReport($reportPath);
        $encodedReport = json_encode($report, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

        $this->assertSame(1, $watermarker->calls);
        $this->assertSame('passed', $report['items'][0]['status']);
        $this->assertStringNotContainsString('protected-sources/lesson/notes.pdf', $encodedReport);
        $this->assertStringNotContainsString(str_repeat('a', 64), $encodedReport);
        $this->assertSame(0, ProtectedFileDelivery::query()->count());
        $this->assertSame([], Storage::disk('protected-output')->allFiles());
    }

    public function test_source_validation_retries_with_normalized_pdf_when_parser_rejects_source_structure(): void
    {
        $watermarker = new class implements ProtectedPdfWatermarker
        {
            public int $calls = 0;

            public function watermark(mixed $source, string $watermark, int $maxPages): string
            {
                $this->calls++;
                if ($this->calls === 1) {
                    throw new ProtectedFileGenerationException(
                        ProtectedFileGenerationException::PDF_UNSUPPORTED,
                    );
                }

                return "%PDF-validated {$watermark} {$maxPages}";
            }
        };
        $normalizer = new class implements ProtectedPdfSourceNormalizer
        {
            public int $calls = 0;

            public function normalize(mixed $source): mixed
            {
                $this->calls++;
                $copy = tmpfile();
                if ($copy === false) {
                    throw new ProtectedFileGenerationException(
                        ProtectedFileGenerationException::PDF_UNSUPPORTED,
                    );
                }
                fwrite($copy, "%PDF-1.4\nnormalized");
                rewind($copy);

                return $copy;
            }
        };
        $this->app->instance(ProtectedPdfWatermarker::class, $watermarker);
        $this->app->instance(ProtectedPdfSourceNormalizer::class, $normalizer);
        $material = $this->createPdfMaterial(sourceBytes: "%PDF-1.4\nneeds-normalization\n%%EOF");
        $reportPath = storage_path('app/protected-file-source-validation-normalized-report.json');

        $this->artisan('legacy:learning:materials:validate-pdf-sources', [
            '--material' => [$material->id],
            '--report' => $reportPath,
        ])
            ->expectsOutputToContain('Validation completed: sources=1 passed=1 failed=0 selected_materials=1')
            ->assertSuccessful();

        $report = $this->readReport($reportPath);

        $this->assertSame(2, $watermarker->calls);
        $this->assertSame(1, $normalizer->calls);
        $this->assertSame('passed', $report['items'][0]['status']);
        $this->assertSame(0, ProtectedFileDelivery::query()->count());
        $this->assertSame([], Storage::disk('protected-output')->allFiles());
    }

    public function test_invalid_pdf_source_fails_closed_before_watermarker(): void
    {
        $watermarker = new class implements ProtectedPdfWatermarker
        {
            public int $calls = 0;

            public function watermark(mixed $source, string $watermark, int $maxPages): string
            {
                $this->calls++;

                return '%PDF-validated';
            }
        };
        $this->app->instance(ProtectedPdfWatermarker::class, $watermarker);
        $material = $this->createPdfMaterial(sourceBytes: 'not a pdf');
        $reportPath = storage_path('app/protected-file-source-validation-failed-report.json');

        $this->artisan('legacy:learning:materials:validate-pdf-sources', [
            '--material' => [$material->id],
            '--report' => $reportPath,
        ])
            ->expectsOutputToContain('Validation completed: sources=1 passed=0 failed=1 selected_materials=1')
            ->assertSuccessful();

        $report = $this->readReport($reportPath);

        $this->assertSame(0, $watermarker->calls);
        $this->assertSame('failed', $report['items'][0]['status']);
        $this->assertSame('source_integrity_failed', $report['items'][0]['error_code']);
        $this->assertSame(0, ProtectedFileDelivery::query()->count());
        $this->assertSame([], Storage::disk('protected-output')->allFiles());
    }

    public function test_source_report_selection_deduplicates_shared_pdf_source(): void
    {
        $watermarker = new class implements ProtectedPdfWatermarker
        {
            public int $calls = 0;

            public function watermark(mixed $source, string $watermark, int $maxPages): string
            {
                $this->calls++;

                return '%PDF-validated';
            }
        };
        $this->app->instance(ProtectedPdfWatermarker::class, $watermarker);
        $first = $this->createPdfMaterial(sortOrder: 1000, importSourceKey: hash('sha256', 'pdf-source-a'));
        $second = $this->createPdfMaterial(sortOrder: 1001, importSourceKey: hash('sha256', 'pdf-source-b'));
        $sourceReport = $this->writePdfSourceReport([
            $this->reportItemForMaterial($first),
            $this->reportItemForMaterial($second),
        ]);
        $reportPath = storage_path('app/protected-file-source-validation-dedup-report.json');

        $this->artisan('legacy:learning:materials:validate-pdf-sources', [
            '--source-report' => [$sourceReport],
            '--report' => $reportPath,
        ])
            ->expectsOutputToContain('Validation completed: sources=1 passed=1 failed=0 selected_materials=2')
            ->assertSuccessful();

        $report = $this->readReport($reportPath);
        $actualMaterialIds = $report['items'][0]['material_ids'];
        sort($actualMaterialIds);

        $this->assertSame(1, $watermarker->calls);
        $this->assertSame([$first->id, $second->id], $actualMaterialIds);
    }

    private function createLesson(): Lesson
    {
        $subject = Subject::query()->firstOrCreate(
            ['slug' => 'anatomy'],
            ['title' => 'Анатомия'],
        );
        $course = Course::query()->firstOrCreate(
            ['subject_id' => $subject->id, 'slug' => 'osteology'],
            ['title' => 'Остеология', 'price_cents' => 100000],
        );

        return Lesson::query()->create([
            'course_id' => $course->id,
            'slug' => 'bones-'.Str::uuid(),
            'title' => 'Кости',
        ]);
    }

    private function createPdfMaterial(
        string $sourceBytes = "%PDF-1.4\n1 0 obj\n<<>>\nendobj\n%%EOF",
        int $sortOrder = 1000,
        string $importSourceKey = '',
    ): LessonMaterial {
        $lesson = $this->createLesson();
        $material = LessonMaterial::query()->create([
            'lesson_id' => $lesson->id,
            'kind' => LessonMaterial::KIND_PDF,
            'title' => 'Конспект',
            'sort_order' => $sortOrder,
            'is_published' => false,
        ]);
        $objectKey = 'protected-sources/lesson/notes.pdf';
        $sourceSha256 = hash('sha256', $sourceBytes);
        Storage::disk('protected-source')->put($objectKey, $sourceBytes);

        LessonMaterialFile::query()->create([
            'lesson_material_id' => $material->id,
            'source_object_key' => $objectKey,
            'source_sha256' => $sourceSha256,
            'source_size_bytes' => strlen($sourceBytes),
            'source_mime_type' => 'application/pdf',
            'download_filename' => 'notes.pdf',
        ]);

        $importSourceKey = $importSourceKey === '' ? hash('sha256', 'pdf-source-'.$material->id) : $importSourceKey;
        LegacyImportMapping::query()->create([
            'batch_key' => 'lesson_file_material_v1',
            'source_table' => 'platform_page_file_shortcode',
            'source_id' => $importSourceKey,
            'source_scope_type' => LegacyImportMapping::SOURCE_SCOPE_ID_ONLY,
            'source_scope_id' => LegacyImportMapping::SOURCE_SCOPE_ID_ONLY,
            'target_domain' => 'learning_lesson_material',
            'target_type' => 'lesson_material',
            'target_id' => $material->id,
            'mapping_status' => LegacyImportMapping::MAPPING_MAPPED,
            'validation_status' => LegacyImportMapping::VALIDATION_PENDING,
            'idempotency_key' => hash('sha256', 'mapping-'.$material->id),
            'source_fingerprint_hash' => hash('sha256', 'fingerprint-'.$material->id),
            'metadata' => [
                'dry_run_occurrence_key' => hash('sha256', 'occurrence-'.$material->id),
            ],
        ]);

        return $material;
    }

    /**
     * @return array<string, mixed>
     */
    private function reportItemForMaterial(LessonMaterial $material): array
    {
        $mapping = LegacyImportMapping::query()
            ->where('target_id', $material->id)
            ->sole();
        $source = LessonMaterialFile::query()
            ->where('lesson_material_id', $material->id)
            ->sole();

        return [
            'kind' => LessonMaterial::KIND_PDF,
            'lesson_id' => $material->lesson_id,
            'import_source_key' => $mapping->source_id,
            'source_scope_type' => $mapping->source_scope_type,
            'source_scope_id' => $mapping->source_scope_id,
            'title' => $material->title,
            'sort_order' => $material->sort_order,
            'source_fingerprint_hash' => $mapping->source_fingerprint_hash,
            'manual_review' => false,
            'manual_review_reasons' => [],
            'source_sha256' => $source->source_sha256,
            'source_size_bytes' => (int) $source->source_size_bytes,
            'source_mime_type' => $source->source_mime_type,
            'download_filename' => $source->download_filename,
            'metadata' => [
                'dry_run_occurrence_key' => $mapping->metadata['dry_run_occurrence_key'],
            ],
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     */
    private function writePdfSourceReport(array $items): string
    {
        $path = storage_path('app/protected-file-source-validation-report-'.Str::uuid().'.json');
        File::put($path, json_encode([
            'mode' => 'dry-run',
            'source_table' => 'platform_page_file_shortcode',
            'items' => $items,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $canonicalPath = realpath($path);
        $this->assertIsString($canonicalPath);

        return $canonicalPath;
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
