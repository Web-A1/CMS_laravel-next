<?php

namespace App\Contracts\ProtectedFiles;

use DateTimeInterface;

interface TemporaryDownloadUrlIssuer
{
    public function issue(
        string $disk,
        string $path,
        DateTimeInterface $expiresAt,
        string $filename,
    ): string;
}
