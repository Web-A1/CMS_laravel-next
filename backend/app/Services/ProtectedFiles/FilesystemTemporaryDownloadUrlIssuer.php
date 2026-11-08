<?php

namespace App\Services\ProtectedFiles;

use App\Contracts\ProtectedFiles\TemporaryDownloadUrlIssuer;
use DateTimeInterface;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\HeaderUtils;
use UnexpectedValueException;

class FilesystemTemporaryDownloadUrlIssuer implements TemporaryDownloadUrlIssuer
{
    public function issue(
        string $disk,
        string $path,
        DateTimeInterface $expiresAt,
        string $filename,
    ): string {
        $filesystem = Storage::disk($disk);
        if (! $filesystem->exists($path)) {
            throw new UnexpectedValueException('Protected file is not available.');
        }

        $url = $filesystem->temporaryUrl($path, $expiresAt, [
            'ResponseContentDisposition' => HeaderUtils::makeDisposition(
                'attachment',
                $filename,
                $this->asciiFallbackFilename($filename),
            ),
            'ResponseContentType' => 'application/pdf',
        ]);

        $scheme = parse_url($url, PHP_URL_SCHEME);
        if (! is_string($scheme) || ! in_array($scheme, ['http', 'https'], true)) {
            throw new UnexpectedValueException('Temporary URL has an unsupported scheme.');
        }
        if (! app()->environment(['local', 'testing']) && $scheme !== 'https') {
            throw new UnexpectedValueException('Temporary URL must use HTTPS.');
        }

        return $url;
    }

    private function asciiFallbackFilename(string $filename): string
    {
        $fallback = Str::ascii($filename);
        $fallback = preg_replace('/[^\x20-\x7E]/', '', $fallback) ?? '';
        $fallback = str_replace(['%', '/', '\\'], '-', $fallback);
        $fallback = trim($fallback);

        if ($fallback === '' || $fallback === '.' || $fallback === '..') {
            return 'download.pdf';
        }

        return str_ends_with(strtolower($fallback), '.pdf') ? $fallback : $fallback.'.pdf';
    }
}
