<?php

namespace App\Services\ProtectedFiles;

use App\Exceptions\ProtectedFileGenerationException;
use App\Models\ProtectedFileDelivery;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class ProtectedFileGenerator
{
    public function __construct(private readonly ProtectedPdfWatermarkRenderer $renderer) {}

    public function generate(int $deliveryId): void
    {
        $lease = $this->acquireLease($deliveryId);
        if ($lease === null) {
            return;
        }

        $outputKey = null;

        try {
            $delivery = ProtectedFileDelivery::query()
                ->with('source')
                ->find($deliveryId);
            if (! $delivery || ! $delivery->source) {
                throw new ProtectedFileGenerationException(
                    ProtectedFileGenerationException::SOURCE_UNAVAILABLE,
                );
            }

            $source = $delivery->source;
            if (! hash_equals((string) $delivery->source_sha256, (string) $source->source_sha256)) {
                throw new ProtectedFileGenerationException(
                    ProtectedFileGenerationException::SOURCE_INTEGRITY_FAILED,
                );
            }
            $this->validateSourceMetadata($source->source_mime_type, $source->download_filename);
            if (! $this->isSafeObjectKey($source->source_object_key)) {
                throw new ProtectedFileGenerationException(
                    ProtectedFileGenerationException::SOURCE_METADATA_INVALID,
                );
            }
            $sourceStream = $this->verifiedSourceStream(
                $source->source_object_key,
                $source->source_sha256,
                (int) $source->source_size_bytes,
            );

            try {
                $brand = trim((string) config('protected_files.watermark_brand', 'Christmedschool'));
                if (! preg_match('/\A[A-Za-z0-9 ._-]{1,64}\z/', $brand)) {
                    $brand = 'Christmedschool';
                }
                $output = $this->renderer->watermark(
                    $sourceStream,
                    "{$brand} | {$delivery->copy_code}",
                    $this->boundedInt('max_pages', 1, 1000, 500),
                );
            } finally {
                fclose($sourceStream);
            }
            if (
                strlen($output) > $this->boundedInt(
                    'max_output_bytes',
                    1024,
                    400 * 1024 * 1024,
                    400 * 1024 * 1024,
                )
            ) {
                throw new ProtectedFileGenerationException(
                    ProtectedFileGenerationException::OUTPUT_TOO_LARGE,
                );
            }

            $outputKey = $this->outputKey($delivery, $lease);
            $outputDisk = (string) config('protected_files.output_disk', 'local');
            try {
                $written = Storage::disk($outputDisk)->put($outputKey, $output, [
                    'visibility' => 'private',
                    'ContentType' => 'application/pdf',
                ]);
            } catch (Throwable) {
                throw new ProtectedFileGenerationException(
                    ProtectedFileGenerationException::OUTPUT_WRITE_FAILED,
                );
            }
            if (! $written) {
                throw new ProtectedFileGenerationException(
                    ProtectedFileGenerationException::OUTPUT_WRITE_FAILED,
                );
            }

            try {
                $stored = Storage::disk($outputDisk)->get($outputKey);
            } catch (Throwable) {
                throw new ProtectedFileGenerationException(
                    ProtectedFileGenerationException::OUTPUT_INTEGRITY_FAILED,
                );
            }
            if ($stored !== $output || ! str_starts_with($stored, '%PDF-')) {
                throw new ProtectedFileGenerationException(
                    ProtectedFileGenerationException::OUTPUT_INTEGRITY_FAILED,
                );
            }

            if (! $this->markReady($deliveryId, $lease, $outputKey, $stored)) {
                Storage::disk($outputDisk)->delete($outputKey);
            }
        } catch (Throwable $exception) {
            if ($outputKey !== null) {
                try {
                    Storage::disk((string) config('protected_files.output_disk', 'local'))->delete($outputKey);
                } catch (Throwable) {
                    // Ошибка cleanup не должна скрывать исходную причину отказа.
                }
            }

            $failure = $exception instanceof ProtectedFileGenerationException
                ? $exception
                : new ProtectedFileGenerationException(
                    ProtectedFileGenerationException::SOURCE_UNAVAILABLE,
                );
            $this->markFailed($deliveryId, $lease, $failure->errorCode);

            throw $failure;
        }
    }

    private function acquireLease(int $deliveryId): ?string
    {
        return DB::transaction(function () use ($deliveryId): ?string {
            $delivery = ProtectedFileDelivery::query()->lockForUpdate()->find($deliveryId);
            if (! $delivery || $delivery->isReady()) {
                return null;
            }

            if (
                $delivery->status === ProtectedFileDelivery::STATUS_PROCESSING
                && $delivery->lease_expires_at?->isFuture()
            ) {
                return null;
            }

            $maxAttempts = $this->boundedInt('max_attempts', 1, 10, 3);
            if ($delivery->attempt_count >= $maxAttempts) {
                return null;
            }

            $lease = (string) Str::uuid();
            $delivery->forceFill([
                'status' => ProtectedFileDelivery::STATUS_PROCESSING,
                'attempt_count' => $delivery->attempt_count + 1,
                'lease_token' => $lease,
                'lease_expires_at' => now()->addSeconds(
                    $this->boundedInt('lease_ttl_seconds', 300, 900, 300),
                ),
                'last_error_code' => null,
                'failed_at' => null,
            ])->save();

            return $lease;
        });
    }

    /** @return resource */
    private function verifiedSourceStream(string $objectKey, string $expectedHash, int $expectedSize): mixed
    {
        $maxBytes = $this->boundedInt('max_source_bytes', 1024, 300 * 1024 * 1024, 300 * 1024 * 1024);
        if ($expectedSize < 1 || $expectedSize > $maxBytes) {
            throw new ProtectedFileGenerationException(
                ProtectedFileGenerationException::SOURCE_TOO_LARGE,
            );
        }
        if (! preg_match('/\A[a-f0-9]{64}\z/', $expectedHash)) {
            throw new ProtectedFileGenerationException(
                ProtectedFileGenerationException::SOURCE_METADATA_INVALID,
            );
        }

        $input = Storage::disk((string) config('protected_files.source_disk', 'local'))
            ->readStream($objectKey);
        if (! is_resource($input)) {
            throw new ProtectedFileGenerationException(
                ProtectedFileGenerationException::SOURCE_UNAVAILABLE,
            );
        }

        $copy = tmpfile();
        if ($copy === false) {
            fclose($input);
            throw new ProtectedFileGenerationException(
                ProtectedFileGenerationException::SOURCE_UNAVAILABLE,
            );
        }

        $hash = hash_init('sha256');
        $size = 0;
        try {
            while (! feof($input)) {
                $chunk = fread($input, 8192);
                if ($chunk === false) {
                    throw new ProtectedFileGenerationException(
                        ProtectedFileGenerationException::SOURCE_UNAVAILABLE,
                    );
                }
                if ($chunk === '') {
                    continue;
                }

                $size += strlen($chunk);
                if ($size > $maxBytes) {
                    throw new ProtectedFileGenerationException(
                        ProtectedFileGenerationException::SOURCE_TOO_LARGE,
                    );
                }
                hash_update($hash, $chunk);
                if (fwrite($copy, $chunk) !== strlen($chunk)) {
                    throw new ProtectedFileGenerationException(
                        ProtectedFileGenerationException::SOURCE_UNAVAILABLE,
                    );
                }
            }
        } catch (Throwable $exception) {
            fclose($copy);
            throw $exception;
        } finally {
            fclose($input);
        }

        rewind($copy);
        $header = fread($copy, 5);
        $actualHash = hash_final($hash);
        if ($size !== $expectedSize || ! hash_equals($expectedHash, $actualHash) || $header !== '%PDF-') {
            fclose($copy);
            throw new ProtectedFileGenerationException(
                ProtectedFileGenerationException::SOURCE_INTEGRITY_FAILED,
            );
        }

        rewind($copy);

        return $copy;
    }

    private function validateSourceMetadata(string $mimeType, string $filename): void
    {
        if (
            $mimeType !== 'application/pdf'
            || $filename === ''
            || strlen($filename) > 255
            || basename($filename) !== $filename
            || str_contains($filename, '\\')
            || preg_match('/[\x00-\x1F\x7F]/', $filename)
            || ! str_ends_with(strtolower($filename), '.pdf')
        ) {
            throw new ProtectedFileGenerationException(
                ProtectedFileGenerationException::SOURCE_METADATA_INVALID,
            );
        }
    }

    private function outputKey(ProtectedFileDelivery $delivery, string $lease): string
    {
        $prefix = trim((string) config('protected_files.output_prefix', 'protected-copies'), '/');
        if (! $this->isSafeObjectKey($prefix)) {
            throw new ProtectedFileGenerationException(
                ProtectedFileGenerationException::OUTPUT_WRITE_FAILED,
            );
        }

        return "{$prefix}/{$delivery->public_id}/{$lease}.pdf";
    }

    private function isSafeObjectKey(string $objectKey): bool
    {
        if (
            $objectKey === ''
            || strlen($objectKey) > 1024
            || str_starts_with($objectKey, '/')
            || str_contains($objectKey, '\\')
            || str_contains($objectKey, "\0")
        ) {
            return false;
        }

        foreach (explode('/', $objectKey) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return false;
            }
        }

        return true;
    }

    private function markReady(
        int $deliveryId,
        string $lease,
        string $outputKey,
        string $output,
    ): bool {
        return DB::transaction(function () use ($deliveryId, $lease, $outputKey, $output): bool {
            $delivery = ProtectedFileDelivery::query()->lockForUpdate()->find($deliveryId);
            if (! $delivery || ! hash_equals((string) $delivery->lease_token, $lease)) {
                return false;
            }

            $delivery->forceFill([
                'status' => ProtectedFileDelivery::STATUS_READY,
                'lease_token' => null,
                'lease_expires_at' => null,
                'output_object_key' => $outputKey,
                'output_sha256' => hash('sha256', $output),
                'output_size_bytes' => strlen($output),
                'last_error_code' => null,
                'ready_at' => now(),
                'failed_at' => null,
            ])->save();

            return true;
        });
    }

    private function markFailed(int $deliveryId, string $lease, string $errorCode): void
    {
        DB::transaction(function () use ($deliveryId, $lease, $errorCode): void {
            $delivery = ProtectedFileDelivery::query()->lockForUpdate()->find($deliveryId);
            if (! $delivery || ! hash_equals((string) $delivery->lease_token, $lease)) {
                return;
            }

            $delivery->forceFill([
                'status' => ProtectedFileDelivery::STATUS_FAILED,
                'lease_token' => null,
                'lease_expires_at' => null,
                'output_object_key' => null,
                'output_sha256' => null,
                'output_size_bytes' => null,
                'last_error_code' => $errorCode,
                'failed_at' => now(),
            ])->save();
        });
    }

    private function boundedInt(string $key, int $minimum, int $maximum, int $fallback): int
    {
        $value = config("protected_files.{$key}", $fallback);
        if (! is_int($value) || $value < $minimum || $value > $maximum) {
            return $fallback;
        }

        return $value;
    }
}
