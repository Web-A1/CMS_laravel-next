<?php

namespace App\Services\LegacyUsers\Readiness;

use RuntimeException;

final class ReadinessFiles
{
    public static function directory(string $path): string
    {
        clearstatcache();
        if ($path === '' || $path[0] !== '/' || realpath($path) !== $path
            || ! is_dir($path) || is_link($path) || (fileperms($path) & 0777) !== 0700) {
            throw new RuntimeException('Invalid private directory.');
        }

        return $path;
    }

    public static function file(string $path, ?string $sha256 = null): string
    {
        self::directory(dirname($path));
        if (realpath($path) !== $path || ! is_file($path) || is_link($path)
            || (fileperms($path) & 0777) !== 0600) {
            throw new RuntimeException('Invalid private file.');
        }
        $hash = @hash_file('sha256', $path);
        if (! is_string($hash) || ($sha256 !== null && (! self::digest($sha256) || ! hash_equals($sha256, $hash)))) {
            throw new RuntimeException('Input integrity failure.');
        }

        return $hash;
    }

    public static function digest(mixed $value): bool
    {
        return is_string($value) && preg_match('/\A[a-f0-9]{64}\z/', $value) === 1;
    }

    public static function keys(array $value, array $expected): void
    {
        $actual = array_keys($value);
        sort($actual);
        sort($expected);
        if ($actual !== $expected) {
            throw new RuntimeException('Unexpected input shape.');
        }
    }

    public static function json(mixed $value): string
    {
        return json_encode(self::ordered($value), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    private static function ordered(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value);
        }
        foreach ($value as &$item) {
            $item = self::ordered($item);
        }

        return $value;
    }

    /** Reports live outside Git working trees, including private ignored directories. */
    public static function reportPath(string $path, array $bundleDirectories): string
    {
        $parent = self::directory(dirname($path));
        if ($path !== $parent.'/'.basename($path) || file_exists($path) || is_link($path)
            || in_array(basename($path), ['', '.', '..'], true) || ! is_writable($parent)) {
            throw new RuntimeException('Unsafe report destination.');
        }
        foreach ($bundleDirectories as $directory) {
            if (str_starts_with($path, $directory.'/')) {
                throw new RuntimeException('Report overlaps source bundle.');
            }
        }
        for ($ancestor = $parent; $ancestor !== '/'; $ancestor = dirname($ancestor)) {
            if (file_exists($ancestor.'/.git') || in_array(basename($ancestor), ['public', 'www', 'wwwroot'], true)) {
                throw new RuntimeException('Report must be outside public and Git trees.');
            }
        }

        return $path;
    }

    public static function write(string $path, string $bytes): void
    {
        $mask = umask(0077);
        $file = @fopen($path, 'xb');
        umask($mask);
        if ($file === false) {
            throw new RuntimeException('Cannot create private report.');
        }
        $complete = false;
        try {
            if (! @chmod($path, 0600) || @fwrite($file, $bytes) !== strlen($bytes) || ! @fflush($file)) {
                throw new RuntimeException('Report write failed.');
            }
            $complete = true;
        } finally {
            fclose($file);
            if (! $complete) {
                @unlink($path);
            }
        }
    }
}
