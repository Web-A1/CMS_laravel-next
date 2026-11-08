<?php

namespace App\Support;

use RuntimeException;
use Throwable;

class PrivateReportFile
{
    public static function write(string $path, string $payload): void
    {
        $directory = dirname($path);
        if ($directory === '' || ! is_dir($directory)) {
            throw new RuntimeException("Report could not be written: {$path}");
        }

        $temporaryPath = tempnam($directory, '.report-');
        if ($temporaryPath === false) {
            throw new RuntimeException("Report could not be written: {$path}");
        }

        try {
            $written = file_put_contents($temporaryPath, $payload, LOCK_EX);
            if ($written === false || $written !== strlen($payload)) {
                throw new RuntimeException("Report could not be written: {$path}");
            }
            if (! chmod($temporaryPath, 0600)) {
                throw new RuntimeException("Report could not be written: {$path}");
            }
            if (! rename($temporaryPath, $path)) {
                throw new RuntimeException("Report could not be written: {$path}");
            }
            if (! chmod($path, 0600)) {
                throw new RuntimeException("Report could not be written: {$path}");
            }
        } catch (Throwable $exception) {
            if (is_file($temporaryPath)) {
                @unlink($temporaryPath);
            }

            if ($exception instanceof RuntimeException && $exception->getMessage() === "Report could not be written: {$path}") {
                throw $exception;
            }

            throw new RuntimeException("Report could not be written: {$path}", previous: $exception);
        }
    }
}
