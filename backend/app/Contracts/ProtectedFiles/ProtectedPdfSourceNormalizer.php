<?php

namespace App\Contracts\ProtectedFiles;

interface ProtectedPdfSourceNormalizer
{
    /** @param resource $source
     * @return resource
     */
    public function normalize(mixed $source): mixed;
}
