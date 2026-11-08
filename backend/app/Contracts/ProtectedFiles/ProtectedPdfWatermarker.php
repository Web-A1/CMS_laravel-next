<?php

namespace App\Contracts\ProtectedFiles;

interface ProtectedPdfWatermarker
{
    /** @param resource $source */
    public function watermark(mixed $source, string $watermark, int $maxPages): string;
}
