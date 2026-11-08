<?php

namespace App\Services\Articles;

use RuntimeException;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

final class ArticleImageProcessor
{
    private float $started;

    private string $directory;

    private const FORMATS = ['image/jpeg' => 'JPEG', 'image/png' => 'PNG', 'image/webp' => 'WEBP', 'image/avif' => 'AVIF'];

    public function variants(string $source, string $mime, string $directory): array
    {
        if (! isset(self::FORMATS[$mime])) {
            return ['variants' => [], 'width' => null, 'height' => null, 'passthrough' => true];
        }
        $this->started = microtime(true);
        $this->directory = $directory;
        $header = file_get_contents($source, false, null, 0, 1048576);
        if (($mime === 'image/png' && str_contains($header, 'acTL')) || ($mime === 'image/webp' && str_contains($header, 'ANIM')) || ($mime === 'image/avif' && str_contains(substr($header, 0, 256), 'avis'))) {
            return ['variants' => [], 'width' => null, 'height' => null, 'passthrough' => true];
        }
        $frames = array_filter(explode("\n", trim($this->run(['identify', '-ping', '-format', "%n\n", self::FORMATS[$mime].':'.$source]))));
        if (max(array_map('intval', $frames)) > 1) {
            return ['variants' => [], 'width' => null, 'height' => null, 'passthrough' => true];
        }
        [$inputWidth, $inputHeight] = $this->dimensions(self::FORMATS[$mime].':'.$source.'[0]');
        // JPEG supports reduced-resolution decoding; other formats retain the full-decode ceiling.
        $pixelLimit = $mime === 'image/jpeg' ? max(config('articles.image_max_pixels'), 100000000) : config('articles.image_max_pixels');
        if (min($inputWidth, $inputHeight) < 1 || max($inputWidth, $inputHeight) > config('articles.image_max_edge') || $inputWidth * $inputHeight > $pixelLimit) {
            throw new RuntimeException('Article image exceeds processing limits.');
        }
        $orientation = trim($this->run(['identify', '-ping', '-format', '%[orientation]', self::FORMATS[$mime].':'.$source.'[0]']));
        [$width, $height] = in_array($orientation, ['LeftTop', 'RightTop', 'RightBottom', 'LeftBottom'], true) ? [$inputHeight, $inputWidth] : [$inputWidth, $inputHeight];
        $configured = config('articles.image_widths');
        $maximum = min($width, max($configured));
        $decode = [];
        if ($mime === 'image/jpeg') {
            // libjpeg decodes in eighths: round up so the no-upscale resize can reach the target.
            $scale = min(1, ceil(($maximum / $width) * 8) / 8);
            $decode = ['-define', 'jpeg:size='.((int) ceil($inputWidth * $scale)).'x'.((int) ceil($inputHeight * $scale))];
        }
        $normalized = $directory.'/normalized.miff';
        // Subsampled JPEG dimensions can round independently; retain the original aspect ratio.
        $normalizedHeight = max(1, (int) round($height * $maximum / $width));
        $this->run([...$decode, self::FORMATS[$mime].':'.$source.'[0]', '-auto-orient', '-resize', $maximum.'x'.$normalizedHeight.'!', '-colorspace', 'sRGB', '-strip', '-depth', '8', 'MIFF:'.$normalized]);
        // Full-resolution bytes remain in the original; responsive encodes stop at the viewport profile.
        $sizes = array_values(array_unique([...array_filter($configured, fn ($size) => $size < $maximum), $maximum]));
        $result = [];
        foreach ($sizes as $size) {
            foreach (['avif', 'webp'] as $format) {
                $file = $directory.'/'.$size.'.'.$format;
                $this->run([$normalized, '-resize', $size.'x>', '-quality', $format === 'avif' ? '80' : '88', '-define', 'heic:speed=6', '-define', 'webp:method=5', $format.':'.$file]);
                [$actualWidth,$actualHeight] = $this->dimensions($format.':'.$file, true);
                $actualMime = (new \finfo(FILEINFO_MIME_TYPE))->file($file);
                if ($actualMime !== 'image/'.$format || $actualWidth !== $size || abs($actualHeight - ($height * $size / $width)) > 1 || filesize($file) < 1) {
                    throw new RuntimeException('Article variant verification failed.');
                }
                $result[] = ['file' => $file, 'format' => $format, 'width' => $actualWidth, 'height' => $actualHeight, 'bytes' => filesize($file), 'sha256' => hash_file('sha256', $file), 'mime' => $actualMime];
            }
        }

        return ['variants' => $result, 'width' => $width, 'height' => $height, 'passthrough' => false];
    }

    private function dimensions(string $path, bool $decode = false): array
    {
        return array_map('intval', explode(' ', trim($this->run($decode ? [$path, '-format', '%w %h', 'info:'] : ['identify', '-ping', '-format', '%w %h', $path]))));
    }

    private function run(array $arguments): string
    {
        $binary = config('articles.image_binary') ?: ((new ExecutableFinder)->find('magick') ?? (new ExecutableFinder)->find('convert'));
        if (! $binary) {
            throw new RuntimeException('ImageMagick is required for article images.');
        }
        $command = [$binary];
        if (($arguments[0] ?? null) === 'identify') {
            array_shift($arguments);
            $command = basename($binary) === 'convert' ? [dirname($binary).'/identify'] : [$binary, 'identify'];
        }
        $remaining = config('articles.image_timeout') - (microtime(true) - $this->started);
        if ($remaining <= 0) {
            throw new RuntimeException('Article image processing timed out.');
        }
        $process = new Process([...$command, '-limit', 'memory', '128MiB', '-limit', 'map', '256MiB', '-limit', 'disk', '3GiB', '-limit', 'thread', '1', ...$arguments]);
        $process->setEnv(['MAGICK_TEMPORARY_PATH' => $this->directory]);
        $process->setTimeout($remaining);
        $process->mustRun();

        return $process->getOutput();
    }
}
