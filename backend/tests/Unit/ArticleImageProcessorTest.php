<?php

namespace Tests\Unit;

use App\Services\Articles\ArticleImageProcessor;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class ArticleImageProcessorTest extends TestCase
{
    public static function imageDimensions(): array
    {
        return [[2160, 1289, 'jpeg'], [7596, 3012, 'jpeg'], [2226, 1143, 'jpeg'], [7212, 3124, 'png'], [7500, 8000, 'png']];
    }

    #[DataProvider('imageDimensions')]
    public function test_supported_large_images_preserve_requested_variant_dimensions(int $width, int $height, string $format): void
    {
        config(['articles.image_widths' => [320, 1920]]);
        $directory = sys_get_temp_dir().'/article-image-test-'.bin2hex(random_bytes(12));
        File::makeDirectory($directory);

        try {
            $source = $directory.'/original.'.$format;
            (new Process(['magick', '-limit', 'disk', '3GiB', '-size', $width.'x'.$height, 'gradient:#14324e-#c1d3e4', '-alpha', 'set', $source]))->mustRun();
            $originalHash = hash_file('sha256', $source);
            $result = app(ArticleImageProcessor::class)->variants($source, 'image/'.$format, $directory);

            $this->assertFalse($result['passthrough']);
            $this->assertSame([$width, $height], [$result['width'], $result['height']]);
            $this->assertCount(4, $result['variants']);
            foreach ($result['variants'] as $index => $variant) {
                $target = $index < 2 ? 320 : 1920;
                $this->assertSame($target, $variant['width']);
                $this->assertEqualsWithDelta($height * $target / $width, $variant['height'], 1);
                $this->assertSame($variant['sha256'], hash_file('sha256', $variant['file']));
            }
            $this->assertSame($originalHash, hash_file('sha256', $source));
        } finally {
            File::deleteDirectory($directory);
        }
    }
}
