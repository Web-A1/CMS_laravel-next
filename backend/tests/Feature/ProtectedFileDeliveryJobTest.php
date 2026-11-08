<?php

namespace Tests\Feature;

use App\Contracts\ProtectedFiles\ProtectedPdfSourceNormalizer;
use App\Contracts\ProtectedFiles\ProtectedPdfWatermarker;
use App\Exceptions\ProtectedFileGenerationException;
use App\Models\Course;
use App\Models\Lesson;
use App\Models\LessonMaterial;
use App\Models\LessonMaterialFile;
use App\Models\ProtectedFileDelivery;
use App\Models\Subject;
use App\Models\User;
use App\Services\ProtectedFiles\ProtectedFileGenerator;
use App\Services\ProtectedFiles\ProtectedPdfWatermarkRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

class ProtectedFileDeliveryJobTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('protected-source');
        Storage::fake('protected-output');
        config()->set('protected_files.source_disk', 'protected-source');
        config()->set('protected_files.output_disk', 'protected-output');
        config()->set('protected_files.max_source_bytes', 1024 * 1024);
        config()->set('protected_files.max_pages', 10);
        config()->set('protected_files.max_attempts', 3);
        config()->set('protected_files.lease_ttl_seconds', 300);
    }

    public function test_job_generates_private_copy_and_marks_delivery_ready_without_mutating_source(): void
    {
        $sourceBytes = $this->sourcePdf();
        [$source, $delivery] = $this->createDelivery($sourceBytes);
        $watermarked = "%PDF-1.4\nwatermarked {$delivery->copy_code}";

        $watermarker = Mockery::mock(ProtectedPdfWatermarker::class);
        $watermarker->shouldReceive('watermark')
            ->once()
            ->with(Mockery::type('resource'), "Christmedschool | {$delivery->copy_code}", 10)
            ->andReturn($watermarked);

        $this->generator($watermarker)->generate($delivery->id);

        $delivery->refresh();
        $this->assertSame(ProtectedFileDelivery::STATUS_READY, $delivery->status);
        $this->assertSame(1, $delivery->attempt_count);
        $this->assertNull($delivery->lease_token);
        $this->assertNull($delivery->last_error_code);
        $this->assertSame(hash('sha256', $watermarked), $delivery->output_sha256);
        $this->assertSame(strlen($watermarked), $delivery->output_size_bytes);
        $this->assertNotNull($delivery->ready_at);
        $this->assertStringContainsString($delivery->public_id, (string) $delivery->output_object_key);
        Storage::disk('protected-output')->assertExists((string) $delivery->output_object_key);
        $this->assertSame(
            $watermarked,
            Storage::disk('protected-output')->get((string) $delivery->output_object_key),
        );
        $this->assertSame($sourceBytes, Storage::disk('protected-source')->get($source->source_object_key));
    }

    public function test_job_retries_with_normalized_pdf_when_parser_rejects_source_structure(): void
    {
        $sourceBytes = $this->sourcePdf();
        [$source, $delivery] = $this->createDelivery($sourceBytes);
        $watermarked = "%PDF-1.4\nwatermarked normalized {$delivery->copy_code}";
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

        $watermarker = Mockery::mock(ProtectedPdfWatermarker::class);
        $watermarker->shouldReceive('watermark')
            ->once()
            ->with(Mockery::type('resource'), "Christmedschool | {$delivery->copy_code}", 10)
            ->andThrow(new ProtectedFileGenerationException(
                ProtectedFileGenerationException::PDF_UNSUPPORTED,
            ))
            ->ordered();
        $watermarker->shouldReceive('watermark')
            ->once()
            ->with(Mockery::type('resource'), "Christmedschool | {$delivery->copy_code}", 10)
            ->andReturn($watermarked)
            ->ordered();

        $this->generator($watermarker, $normalizer)->generate($delivery->id);

        $delivery->refresh();
        $this->assertSame(1, $normalizer->calls);
        $this->assertSame(ProtectedFileDelivery::STATUS_READY, $delivery->status);
        $this->assertSame(hash('sha256', $watermarked), $delivery->output_sha256);
        $this->assertSame($sourceBytes, Storage::disk('protected-source')->get($source->source_object_key));
    }

    public function test_source_hash_mismatch_fails_closed_before_parser_and_leaves_no_output(): void
    {
        $sourceBytes = $this->sourcePdf();
        [, $delivery] = $this->createDelivery($sourceBytes, str_repeat('a', 64));
        $watermarker = Mockery::mock(ProtectedPdfWatermarker::class);
        $watermarker->shouldNotReceive('watermark');

        $exception = $this->captureFailure(
            fn () => $this->generator($watermarker)->generate($delivery->id),
        );

        $this->assertSame(ProtectedFileGenerationException::SOURCE_INTEGRITY_FAILED, $exception->errorCode);
        $delivery->refresh();
        $this->assertSame(ProtectedFileDelivery::STATUS_FAILED, $delivery->status);
        $this->assertSame('source_integrity_failed', $delivery->last_error_code);
        $this->assertNull($delivery->lease_token);
        $this->assertNull($delivery->output_object_key);
        $this->assertSame([], Storage::disk('protected-output')->allFiles());
    }

    public function test_delivery_source_version_snapshot_cannot_be_silently_replaced(): void
    {
        $sourceBytes = $this->sourcePdf();
        [$source, $delivery] = $this->createDelivery($sourceBytes);
        $replacementBytes = $this->sourcePdf(2);
        Storage::disk('protected-source')->put($source->source_object_key, $replacementBytes);
        $source->update([
            'source_sha256' => hash('sha256', $replacementBytes),
            'source_size_bytes' => strlen($replacementBytes),
        ]);
        $watermarker = Mockery::mock(ProtectedPdfWatermarker::class);
        $watermarker->shouldNotReceive('watermark');

        $exception = $this->captureFailure(
            fn () => $this->generator($watermarker)->generate($delivery->id),
        );

        $this->assertSame(ProtectedFileGenerationException::SOURCE_INTEGRITY_FAILED, $exception->errorCode);
        $this->assertSame('source_integrity_failed', $delivery->fresh()->last_error_code);
        $this->assertSame([], Storage::disk('protected-output')->allFiles());
    }

    public function test_oversize_metadata_fails_before_source_read_or_parser(): void
    {
        $sourceBytes = $this->sourcePdf().str_repeat('x', 2048);
        config()->set('protected_files.max_source_bytes', 1024);
        [, $delivery] = $this->createDelivery($sourceBytes);
        $watermarker = Mockery::mock(ProtectedPdfWatermarker::class);
        $watermarker->shouldNotReceive('watermark');

        $exception = $this->captureFailure(
            fn () => $this->generator($watermarker)->generate($delivery->id),
        );

        $this->assertSame(ProtectedFileGenerationException::SOURCE_TOO_LARGE, $exception->errorCode);
        $this->assertSame('source_too_large', $delivery->fresh()->last_error_code);
    }

    public function test_three_hundred_mib_source_ceiling_is_enforced_before_storage_or_rendering(): void
    {
        config()->set('protected_files.max_source_bytes', 300 * 1024 * 1024);
        [$source, $delivery] = $this->createDelivery($this->sourcePdf());
        $source->update(['source_size_bytes' => 300 * 1024 * 1024 + 1]);
        $watermarker = Mockery::mock(ProtectedPdfWatermarker::class);
        $watermarker->shouldNotReceive('watermark');
        $exception = $this->captureFailure(fn () => $this->generator($watermarker)->generate($delivery->id));
        $this->assertSame(ProtectedFileGenerationException::SOURCE_TOO_LARGE, $exception->errorCode);
        $this->assertSame([], Storage::disk('protected-output')->allFiles());
    }

    public function test_unsafe_source_object_key_fails_before_storage_read_or_parser(): void
    {
        $sourceBytes = $this->sourcePdf();
        [$source, $delivery] = $this->createDelivery($sourceBytes);
        $source->update(['source_object_key' => '../private/notes.pdf']);
        $watermarker = Mockery::mock(ProtectedPdfWatermarker::class);
        $watermarker->shouldNotReceive('watermark');

        $exception = $this->captureFailure(
            fn () => $this->generator($watermarker)->generate($delivery->id),
        );

        $this->assertSame(ProtectedFileGenerationException::SOURCE_METADATA_INVALID, $exception->errorCode);
        $this->assertSame('source_metadata_invalid', $delivery->fresh()->last_error_code);
        $this->assertSame([], Storage::disk('protected-output')->allFiles());
    }

    public function test_active_lease_is_not_stolen_and_does_not_increment_attempts(): void
    {
        $sourceBytes = $this->sourcePdf();
        [, $delivery] = $this->createDelivery($sourceBytes, status: ProtectedFileDelivery::STATUS_PROCESSING);
        $delivery->update([
            'attempt_count' => 1,
            'lease_token' => (string) str()->uuid(),
            'lease_expires_at' => now()->addMinute(),
        ]);
        $watermarker = Mockery::mock(ProtectedPdfWatermarker::class);
        $watermarker->shouldNotReceive('watermark');

        $this->generator($watermarker)->generate($delivery->id);

        $delivery->refresh();
        $this->assertSame(ProtectedFileDelivery::STATUS_PROCESSING, $delivery->status);
        $this->assertSame(1, $delivery->attempt_count);
        $this->assertSame([], Storage::disk('protected-output')->allFiles());
    }

    public function test_stale_lease_is_recovered_with_same_public_id_and_copy_code(): void
    {
        $sourceBytes = $this->sourcePdf();
        [, $delivery] = $this->createDelivery($sourceBytes, status: ProtectedFileDelivery::STATUS_PROCESSING);
        $publicId = $delivery->public_id;
        $copyCode = $delivery->copy_code;
        $delivery->update([
            'attempt_count' => 1,
            'lease_token' => (string) str()->uuid(),
            'lease_expires_at' => now()->subMinute(),
        ]);
        $watermarker = Mockery::mock(ProtectedPdfWatermarker::class);
        $watermarker->shouldReceive('watermark')->once()->andReturn("%PDF-1.4\nretry {$copyCode}");

        $this->generator($watermarker)->generate($delivery->id);

        $delivery->refresh();
        $this->assertSame(ProtectedFileDelivery::STATUS_READY, $delivery->status);
        $this->assertSame(2, $delivery->attempt_count);
        $this->assertSame($publicId, $delivery->public_id);
        $this->assertSame($copyCode, $delivery->copy_code);
    }

    public function test_late_worker_cannot_finalize_or_leave_its_output_after_lease_changes(): void
    {
        $sourceBytes = $this->sourcePdf();
        [, $delivery] = $this->createDelivery($sourceBytes);
        $replacementLease = (string) str()->uuid();
        $watermarker = Mockery::mock(ProtectedPdfWatermarker::class);
        $watermarker->shouldReceive('watermark')
            ->once()
            ->andReturnUsing(function () use ($delivery, $replacementLease): string {
                ProtectedFileDelivery::query()->whereKey($delivery->id)->update([
                    'lease_token' => $replacementLease,
                    'lease_expires_at' => now()->addMinutes(5),
                ]);

                return '%PDF-1.4 late worker';
            });

        $this->generator($watermarker)->generate($delivery->id);

        $delivery->refresh();
        $this->assertSame(ProtectedFileDelivery::STATUS_PROCESSING, $delivery->status);
        $this->assertSame($replacementLease, $delivery->lease_token);
        $this->assertNull($delivery->output_object_key);
        $this->assertSame([], Storage::disk('protected-output')->allFiles());
    }

    public function test_terminal_attempt_limit_is_not_reset_by_another_job(): void
    {
        $sourceBytes = $this->sourcePdf();
        [, $delivery] = $this->createDelivery($sourceBytes, status: ProtectedFileDelivery::STATUS_FAILED);
        $delivery->update([
            'attempt_count' => 3,
            'last_error_code' => 'pdf_unsupported',
            'failed_at' => now(),
        ]);
        $watermarker = Mockery::mock(ProtectedPdfWatermarker::class);
        $watermarker->shouldNotReceive('watermark');

        $this->generator($watermarker)->generate($delivery->id);

        $delivery->refresh();
        $this->assertSame(ProtectedFileDelivery::STATUS_FAILED, $delivery->status);
        $this->assertSame(3, $delivery->attempt_count);
        $this->assertSame('pdf_unsupported', $delivery->last_error_code);
    }

    public function test_output_storage_failure_is_sanitized_and_clears_lease(): void
    {
        $sourceBytes = $this->sourcePdf();
        [, $delivery] = $this->createDelivery($sourceBytes);
        config()->set('protected_files.output_disk', 'missing-output-disk');
        $watermarker = Mockery::mock(ProtectedPdfWatermarker::class);
        $watermarker->shouldReceive('watermark')->once()->andReturn('%PDF-1.4 generated');

        $exception = $this->captureFailure(
            fn () => $this->generator($watermarker)->generate($delivery->id),
        );

        $this->assertSame(ProtectedFileGenerationException::OUTPUT_WRITE_FAILED, $exception->errorCode);
        $this->assertNull($exception->getPrevious());
        $delivery->refresh();
        $this->assertSame(ProtectedFileDelivery::STATUS_FAILED, $delivery->status);
        $this->assertSame('output_write_failed', $delivery->last_error_code);
        $this->assertNull($delivery->lease_token);
        $this->assertNull($delivery->output_object_key);
    }

    public function test_oversize_generated_output_is_rejected_before_storage_write(): void
    {
        $sourceBytes = $this->sourcePdf();
        [, $delivery] = $this->createDelivery($sourceBytes);
        config()->set('protected_files.max_output_bytes', 1024);
        $watermarker = Mockery::mock(ProtectedPdfWatermarker::class);
        $watermarker->shouldReceive('watermark')
            ->once()
            ->andReturn('%PDF-1.4 '.str_repeat('x', 2048));

        $exception = $this->captureFailure(
            fn () => $this->generator($watermarker)->generate($delivery->id),
        );

        $this->assertSame(ProtectedFileGenerationException::OUTPUT_TOO_LARGE, $exception->errorCode);
        $this->assertSame('output_too_large', $delivery->fresh()->last_error_code);
        $this->assertSame([], Storage::disk('protected-output')->allFiles());
    }

    /** @return array{LessonMaterialFile, ProtectedFileDelivery} */
    private function createDelivery(
        string $sourceBytes,
        ?string $sourceHash = null,
        string $status = ProtectedFileDelivery::STATUS_PENDING,
    ): array {
        $subject = Subject::query()->create(['slug' => 'anatomy', 'title' => 'Анатомия']);
        $course = Course::query()->create([
            'subject_id' => $subject->id,
            'slug' => 'osteology',
            'title' => 'Остеология',
            'price_cents' => 100000,
        ]);
        $lesson = Lesson::query()->create([
            'course_id' => $course->id,
            'slug' => 'bones',
            'title' => 'Кости',
        ]);
        $material = LessonMaterial::query()->create([
            'lesson_id' => $lesson->id,
            'kind' => LessonMaterial::KIND_PDF,
            'title' => 'Конспект',
            'is_published' => true,
        ]);
        $key = "protected-sources/{$material->id}/notes.pdf";
        Storage::disk('protected-source')->put($key, $sourceBytes);
        $source = LessonMaterialFile::query()->create([
            'lesson_material_id' => $material->id,
            'source_object_key' => $key,
            'source_sha256' => $sourceHash ?? hash('sha256', $sourceBytes),
            'source_size_bytes' => strlen($sourceBytes),
            'source_mime_type' => 'application/pdf',
            'download_filename' => 'notes.pdf',
        ]);
        $user = User::factory()->create();
        $delivery = ProtectedFileDelivery::query()->create([
            'public_id' => (string) str()->uuid(),
            'user_id' => $user->id,
            'lesson_material_file_id' => $source->id,
            'source_sha256' => $source->source_sha256,
            'copy_code' => 'COPY-'.strtoupper(str()->random(16)),
            'status' => $status,
        ]);

        return [$source, $delivery];
    }

    private function sourcePdf(int $pages = 1): string
    {
        $pdf = new \FPDF;
        for ($page = 1; $page <= $pages; $page++) {
            $pdf->AddPage();
            $pdf->SetFont('Helvetica', '', 12);
            $pdf->Cell(40, 10, "Source page {$page}");
        }

        return $pdf->Output('S');
    }

    private function generator(
        ProtectedPdfWatermarker $watermarker,
        ?ProtectedPdfSourceNormalizer $normalizer = null,
    ): ProtectedFileGenerator {
        $normalizer ??= new class implements ProtectedPdfSourceNormalizer
        {
            public function normalize(mixed $source): mixed
            {
                throw new ProtectedFileGenerationException(
                    ProtectedFileGenerationException::PDF_UNSUPPORTED,
                );
            }
        };

        return new ProtectedFileGenerator(new ProtectedPdfWatermarkRenderer($watermarker, $normalizer));
    }

    private function captureFailure(callable $callback): ProtectedFileGenerationException
    {
        try {
            $callback();
        } catch (ProtectedFileGenerationException $exception) {
            return $exception;
        }

        $this->fail('Expected protected file generation to fail.');
    }
}
