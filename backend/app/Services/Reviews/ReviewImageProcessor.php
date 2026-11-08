<?php

namespace App\Services\Reviews;

use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\Process\Exception\ExceptionInterface;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

final class ReviewImageProcessor
{
    private float $started;

    private ?string $temporaryDirectory = null;

    private const FORMATS = [
        'image/jpeg' => 'JPEG', 'image/png' => 'PNG', 'image/webp' => 'WEBP',
        'image/avif' => 'AVIF', 'image/heic' => 'HEIC', 'image/heif' => 'HEIC',
    ];

    public function checkRuntime(): void
    {
        $this->temporaryDirectory = null;
        $this->started = microtime(true);
        $formats = $this->run(['identify', '-list', 'format']);
        foreach (['JPEG' => 'r', 'PNG' => 'r', 'HEIC' => 'r', 'AVIF' => 'rw', 'WEBP' => 'rw'] as $format => $mode) {
            // ImageMagick 6 can leave the module column empty for HEIC/AVIF.
            if (! preg_match('/^[ \t]*'.$format.'\*?[ \t]+(?:\S+[ \t]+)?'.($mode === 'rw' ? 'rw' : 'r[w-]').'[+-](?:[ \t]|$)/m', $formats)) {
                throw new RuntimeException('Обработчик изображений не поддерживает '.$format.'.');
            }
        }
    }

    public function inspect(string $path, string $mime, bool $rejectAnimation = false, ?string $temporaryDirectory = null): array
    {
        $this->temporaryDirectory = $temporaryDirectory;
        if (! isset(self::FORMATS[$mime])) {
            throw new InvalidArgumentException('Загрузите изображение JPEG, PNG, WebP, AVIF или HEIC.');
        }
        $this->started = microtime(true);
        try {
            $result = $this->run(['identify', '-ping', '-format', '%w %h', self::FORMATS[$mime].':'.$path.'[0]']);
        } catch (ExceptionInterface $exception) {
            throw new InvalidArgumentException('Не удалось прочитать изображение. Проверьте файл и доступность серверного обработчика.', previous: $exception);
        }
        [$width, $height] = array_map('intval', explode(' ', trim($result)));
        if ($rejectAnimation && (int) trim($this->run(['identify', '-ping', '-format', '%n', self::FORMATS[$mime].':'.$path])) > 1) {
            throw new InvalidArgumentException('Анимированные изображения не принимаются. Отправьте неподвижный кадр.');
        }
        if ($width < 1 || $height < 1 || max($width, $height) > config('review-media.max_edge')
            || $width * $height > config('review-media.max_pixels')) {
            throw new InvalidArgumentException('Изображение слишком большое: допустимо до 60 мегапикселей и 20 000 пикселей по стороне.');
        }

        return [$width, $height];
    }

    /** @return list<array{file:string,format:string,width:int,height:int,bytes:int}> */
    public function variants(string $source, string $mime, string $kind, string $directory, ?string $temporaryDirectory = null): array
    {
        $this->inspect($source, $mime, temporaryDirectory: $temporaryDirectory);
        $normalized = $directory.'/normalized.miff';
        $this->run([self::FORMATS[$mime].':'.$source.'[0]', '-auto-orient', '-colorspace', 'sRGB', '-strip', '-depth', '8', 'MIFF:'.$normalized]);
        [$width, $height] = array_map('intval', explode(' ', trim($this->run(['identify', '-ping', '-format', '%w %h', $normalized]))));
        $maximum = $kind === 'avatar' ? min($width, $height, 192) : $width;
        $sizes = $kind === 'avatar' ? [64, 128, 192] : [96, 192, 320, 640, 960, 1600];
        $sizes = array_unique([...array_filter($sizes, fn (int $size): bool => $size < $maximum), $maximum]);
        $variants = [];
        foreach ($sizes as $size) {
            foreach (['webp', 'avif'] as $format) {
                $file = $directory.'/'.$size.'.'.$format;
                $resize = $kind === 'avatar'
                    ? ['-resize', $size.'x'.$size.'^', '-gravity', 'center', '-extent', $size.'x'.$size]
                    : ['-resize', $size.'x>'];
                $quality = $kind === 'avatar' ? ($format === 'avif' ? 70 : 82) : ($format === 'avif' ? 85 : 90);
                $this->run([$normalized, ...$resize, '-quality', (string) $quality,
                    '-define', 'heic:speed=6', '-define', 'webp:method=5', $format.':'.$file]);
                // Decode the output under the same resource limits; header checks alone are insufficient.
                [$actualWidth, $actualHeight] = array_map('intval', explode(' ', trim($this->run([$format.':'.$file, '-format', '%w %h', 'info:']))));
                if (! is_file($file) || filesize($file) < 1 || $actualWidth !== $size
                    || ($kind === 'avatar' && $actualHeight !== $size)) {
                    throw new RuntimeException('Не удалось проверить подготовленное изображение.');
                }
                $variants[] = ['file' => $file, 'format' => $format, 'width' => $actualWidth,
                    'height' => $actualHeight, 'bytes' => filesize($file)];
            }
        }

        return $variants;
    }

    private function run(array $arguments): string
    {
        $binary = config('review-media.binary') ?: ((new ExecutableFinder)->find('magick') ?? (new ExecutableFinder)->find('convert'));
        if (! $binary) {
            throw new RuntimeException('На сервере не установлен обработчик изображений ImageMagick.');
        }
        $command = [$binary];
        if (($arguments[0] ?? null) === 'identify') {
            array_shift($arguments);
            if (basename($binary) === 'convert') {
                $command = [dirname($binary).'/identify'];
            } else {
                $command[] = 'identify';
            }
        }
        $environment = $this->temporaryDirectory === null ? null : array_fill_keys(['MAGICK_TEMPORARY_PATH', 'TMPDIR', 'TMP', 'TEMP'], $this->temporaryDirectory);
        $process = new Process([...$command, '-limit', 'memory', '128MiB', '-limit', 'map', '256MiB',
            '-limit', 'disk', '512MiB', '-limit', 'thread', '1', ...$arguments], env: $environment);
        $remaining = config('review-media.timeout') - (microtime(true) - $this->started);
        if ($remaining <= 0) {
            throw new RuntimeException('Подготовка изображения заняла слишком много времени. Уменьшите файл и повторите загрузку.');
        }
        $process->setTimeout($remaining);
        $process->mustRun();

        return $process->getOutput();
    }
}
