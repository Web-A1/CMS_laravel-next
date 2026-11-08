<?php

namespace App\Services\ProtectedFiles;

use App\Contracts\ProtectedFiles\ProtectedPdfSourceNormalizer;
use App\Exceptions\ProtectedFileGenerationException;
use Symfony\Component\Process\Process;
use Throwable;

class QpdfProtectedPdfSourceNormalizer implements ProtectedPdfSourceNormalizer
{
    /** @return resource */
    public function normalize(mixed $source): mixed
    {
        if (! is_resource($source)) {
            throw new ProtectedFileGenerationException(
                ProtectedFileGenerationException::SOURCE_METADATA_INVALID,
            );
        }

        $binary = $this->binary();
        $input = tmpfile();
        if ($input === false) {
            throw new ProtectedFileGenerationException(
                ProtectedFileGenerationException::PDF_UNSUPPORTED,
            );
        }

        $outputPath = tempnam(sys_get_temp_dir(), 'cms-qpdf-');
        if ($outputPath === false) {
            fclose($input);
            throw new ProtectedFileGenerationException(
                ProtectedFileGenerationException::PDF_UNSUPPORTED,
            );
        }

        try {
            rewind($source);
            if (stream_copy_to_stream($source, $input) === false) {
                throw new ProtectedFileGenerationException(
                    ProtectedFileGenerationException::PDF_UNSUPPORTED,
                );
            }
            fflush($input);

            $inputPath = stream_get_meta_data($input)['uri'] ?? null;
            if (! is_string($inputPath) || $inputPath === '') {
                throw new ProtectedFileGenerationException(
                    ProtectedFileGenerationException::PDF_UNSUPPORTED,
                );
            }

            $this->runQpdf($binary, $inputPath, $outputPath);

            return $this->verifiedOutputStream($outputPath);
        } finally {
            fclose($input);
            if (is_file($outputPath)) {
                @unlink($outputPath);
            }
        }
    }

    private function binary(): string
    {
        if ((string) config('protected_files.pdf_source_normalizer', 'qpdf') !== 'qpdf') {
            throw new ProtectedFileGenerationException(
                ProtectedFileGenerationException::PDF_UNSUPPORTED,
            );
        }

        $binary = trim((string) config('protected_files.qpdf_binary', 'qpdf'));
        if ($binary === '' || str_contains($binary, "\0") || preg_match('/\s/', $binary)) {
            throw new ProtectedFileGenerationException(
                ProtectedFileGenerationException::PDF_UNSUPPORTED,
            );
        }

        return $binary;
    }

    private function runQpdf(string $binary, string $inputPath, string $outputPath): void
    {
        try {
            $process = new Process([
                $binary,
                '--object-streams=disable',
                '--stream-data=preserve',
                '--normalize-content=n',
                $inputPath,
                $outputPath,
            ]);
            $process->setTimeout($this->boundedInt('qpdf_timeout_seconds', 1, 600, 120));
            $process->run();
        } catch (Throwable) {
            throw new ProtectedFileGenerationException(
                ProtectedFileGenerationException::PDF_UNSUPPORTED,
            );
        }

        if (! in_array($process->getExitCode(), [0, 3], true)) {
            throw new ProtectedFileGenerationException(
                ProtectedFileGenerationException::PDF_UNSUPPORTED,
            );
        }
    }

    /** @return resource */
    private function verifiedOutputStream(string $path): mixed
    {
        $input = @fopen($path, 'rb');
        if (! is_resource($input)) {
            throw new ProtectedFileGenerationException(
                ProtectedFileGenerationException::PDF_UNSUPPORTED,
            );
        }

        $copy = tmpfile();
        if ($copy === false) {
            fclose($input);
            throw new ProtectedFileGenerationException(
                ProtectedFileGenerationException::PDF_UNSUPPORTED,
            );
        }

        $maxBytes = $this->boundedInt('max_source_bytes', 1024, 300 * 1024 * 1024, 300 * 1024 * 1024);
        $size = 0;
        try {
            while (! feof($input)) {
                $chunk = fread($input, 8192);
                if ($chunk === false) {
                    throw new ProtectedFileGenerationException(
                        ProtectedFileGenerationException::PDF_UNSUPPORTED,
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
                if (fwrite($copy, $chunk) !== strlen($chunk)) {
                    throw new ProtectedFileGenerationException(
                        ProtectedFileGenerationException::PDF_UNSUPPORTED,
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
        if ($size < 1 || $header !== '%PDF-') {
            fclose($copy);
            throw new ProtectedFileGenerationException(
                ProtectedFileGenerationException::OUTPUT_INTEGRITY_FAILED,
            );
        }

        rewind($copy);

        return $copy;
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
