<?php

namespace App\Services\ProtectedFiles;

use App\Contracts\ProtectedFiles\ProtectedPdfSourceNormalizer;
use App\Contracts\ProtectedFiles\ProtectedPdfWatermarker;
use App\Exceptions\ProtectedFileGenerationException;

class ProtectedPdfWatermarkRenderer
{
    public function __construct(
        private readonly ProtectedPdfWatermarker $watermarker,
        private readonly ProtectedPdfSourceNormalizer $normalizer,
    ) {}

    public function watermark(mixed $source, string $watermark, int $maxPages): string
    {
        try {
            return $this->watermarker->watermark($source, $watermark, $maxPages);
        } catch (ProtectedFileGenerationException $exception) {
            if ($exception->errorCode !== ProtectedFileGenerationException::PDF_UNSUPPORTED) {
                throw $exception;
            }
        }

        $normalized = $this->normalizer->normalize($source);
        try {
            return $this->watermarker->watermark($normalized, $watermark, $maxPages);
        } finally {
            if (is_resource($normalized)) {
                fclose($normalized);
            }
        }
    }
}
