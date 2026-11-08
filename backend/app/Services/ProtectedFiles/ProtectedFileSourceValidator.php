<?php

namespace App\Services\ProtectedFiles;

use App\Exceptions\ProtectedFileGenerationException;
use Illuminate\Support\Facades\Storage;
use Throwable;

class ProtectedFileSourceValidator
{
    public function __construct(private readonly ProtectedPdfWatermarkRenderer $renderer) {}

    /**
     * @return array{output_sha256: string, output_size_bytes: int}
     */
    public function validate(
        string $objectKey,
        string $expectedHash,
        int $expectedSize,
        string $mimeType,
        string $filename,
    ): array {
        $this->validateSourceMetadata($mimeType, $filename);
        if (! $this->isSafeObjectKey($objectKey)) {
            throw new ProtectedFileGenerationException(
                ProtectedFileGenerationException::SOURCE_METADATA_INVALID,
            );
        }

        $sourceStream = $this->verifiedSourceStream($objectKey, $expectedHash, $expectedSize);
        try {
            $brand = trim((string) config('protected_files.watermark_brand', 'Christmedschool'));
            if (! preg_match('/\A[A-Za-z0-9 ._-]{1,64}\z/', $brand)) {
                $brand = 'Christmedschool';
            }
            $output = $this->renderer->watermark(
                $sourceStream,
                "{$brand} | VALIDATION",
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
        if (! str_starts_with($output, '%PDF-')) {
            throw new ProtectedFileGenerationException(
                ProtectedFileGenerationException::OUTPUT_INTEGRITY_FAILED,
            );
        }

        return [
            'output_sha256' => hash('sha256', $output),
            'output_size_bytes' => strlen($output),
        ];
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

        try {
            $input = Storage::disk((string) config('protected_files.source_disk', 'local'))
                ->readStream($objectKey);
        } catch (Throwable) {
            throw new ProtectedFileGenerationException(
                ProtectedFileGenerationException::SOURCE_UNAVAILABLE,
            );
        }
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

    private function boundedInt(string $key, int $minimum, int $maximum, int $fallback): int
    {
        $value = config("protected_files.{$key}", $fallback);
        if (! is_int($value) || $value < $minimum || $value > $maximum) {
            return $fallback;
        }

        return $value;
    }
}
