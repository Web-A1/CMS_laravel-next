<?php

namespace Tests\Feature;

use App\Models\Review;
use App\Models\User;
use App\Services\Reviews\ReviewImageAssets;
use App\Services\Reviews\ReviewImageProcessor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class ReviewImageAssetsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Storage::fake('public');
        Queue::fake();
    }

    public function test_prepares_variants_once_and_removes_the_temporary_original(): void
    {
        $asset = app(ReviewImageAssets::class)->stage(UploadedFile::fake()->image('review.png', 400, 700), 'screenshot', User::factory()->create());
        $source = $asset->source_path;
        $this->assertSame('pending', $asset->status);
        Storage::disk('local')->assertExists($source);
        app(ReviewImageAssets::class)->process($asset->id);
        $asset->refresh();
        $this->assertSame('ready', $asset->status);
        $this->assertNull($asset->source_path);
        Storage::disk('local')->assertMissing($source);
        $this->assertCount(8, $asset->variants);
        foreach ($asset->variants as $variant) {
            $size = getimagesizefromstring(Storage::disk('public')->get($variant['path']));
            $this->assertSame($variant['width'], $size[0]);
            $this->assertSame($variant['height'], $size[1]);
            $this->assertSame('image/'.$variant['format'], $size['mime']);
        }
        $this->assertSame(400, $asset->width);
        $this->assertSame(700, $asset->height);
        $manifest = $asset->variants;
        app(ReviewImageAssets::class)->process($asset->id);
        $this->assertSame($manifest, $asset->fresh()->variants);
    }

    public function test_runtime_accepts_imagemagick_six_without_module_columns_and_requires_heic_reading(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'review-runtime-');
        $formats = "AVIF rw+ AV1\nHEIC r-- Apple\nJPEG* JPEG rw- JPEG\nPNG* PNG rw- PNG\nWEBP* WEBP rw+ WebP\n";
        try {
            file_put_contents($path, "#!/bin/sh\ncat <<'FORMATS'\n".$formats."FORMATS\n");
            chmod($path, 0700);
            config(['review-media.binary' => $path]);
            app(ReviewImageProcessor::class)->checkRuntime();
            $this->addToAssertionCount(1);
            file_put_contents($path, "#!/bin/sh\ncat <<'FORMATS'\n".str_replace('HEIC r--', 'HEIC -w-', $formats)."FORMATS\n");
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('HEIC');
            app(ReviewImageProcessor::class)->checkRuntime();
        } finally {
            unlink($path);
        }
    }

    public function test_small_avatar_is_cropped_without_upscaling(): void
    {
        $asset = app(ReviewImageAssets::class)->stage(UploadedFile::fake()->image('photo.jpg', 40, 80), 'avatar', User::factory()->create());
        app(ReviewImageAssets::class)->process($asset->id);
        $asset->refresh();
        $this->assertSame([40, 40], [$asset->width, $asset->height]);
        $this->assertCount(2, $asset->variants);
    }

    public function test_corrupt_upload_is_rejected_before_queuing(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        app(ReviewImageAssets::class)->stage(UploadedFile::fake()->createWithContent('photo.heic', 'not an image'), 'avatar', User::factory()->create());
    }

    public function test_server_rejects_oversized_input_even_without_browser_validation(): void
    {
        $base = UploadedFile::fake()->image('large.png');
        $file = UploadedFile::fake()->createWithContent('large.png', $base->get().str_repeat('x', 25 * 1024 * 1024));
        $this->expectException(\InvalidArgumentException::class);
        app(ReviewImageAssets::class)->stage($file, 'screenshot', User::factory()->create());
    }

    public function test_excessive_pixel_count_is_rejected_before_full_decode(): void
    {
        $png = UploadedFile::fake()->image('pixel.png', 1, 1)->get();
        $png = substr_replace($png, pack('NN', 6000, 12000), 16, 8);
        $png = substr_replace($png, pack('N', crc32(substr($png, 12, 17))), 29, 4);
        try {
            app(ReviewImageAssets::class)->stage(UploadedFile::fake()->createWithContent('large.png', $png), 'screenshot', User::factory()->create());
            $this->fail('Pixel limit must be checked before decoding.');
        } catch (\InvalidArgumentException $exception) {
            $this->assertStringContainsString('60 мегапикселей', $exception->getMessage());
            $this->assertSame([], Storage::disk('public')->allFiles());
        }
    }

    public function test_storage_disk_is_persisted_per_asset_and_public_urls_use_that_disk(): void
    {
        config(['filesystems.disks.review-test' => ['driver' => 'local', 'url' => 'https://media.example.test'],
            'review-media.disk' => 'review-test']);
        Storage::fake('review-test', ['url' => 'https://media.example.test']);
        $asset = app(ReviewImageAssets::class)->stage(UploadedFile::fake()->image('photo.png', 40, 40), 'avatar', User::factory()->create());
        app(ReviewImageAssets::class)->process($asset->id);
        $asset->refresh();
        config(['review-media.disk' => 'public']);
        $this->assertSame('review-test', $asset->disk);
        $this->assertStringStartsWith('https://media.example.test/', $asset->publicVariants()[0]['url']);
        Storage::disk('public')->assertMissing($asset->primary_path);
        Storage::disk('review-test')->assertExists($asset->primary_path);
    }

    public function test_real_heic_input_is_converted_and_original_is_removed(): void
    {
        $path = sys_get_temp_dir().'/review-test-'.Str::uuid().'.heic';
        try {
            (new Process(['magick', '-size', '80x120', 'gradient:#184b40-#f0f6eb', $path]))->mustRun();
            $asset = app(ReviewImageAssets::class)->stage(UploadedFile::fake()->createWithContent('phone.HEIC', file_get_contents($path)), 'avatar', User::factory()->create());
            $this->assertContains($asset->source_mime, ['image/heic', 'image/heif']);
            $source = $asset->source_path;
            app(ReviewImageAssets::class)->process($asset->id);
            $asset->refresh();
            $this->assertSame('ready', $asset->status);
            $this->assertSame([80, 80], [$asset->width, $asset->height]);
            Storage::disk('local')->assertMissing($source);
            $this->assertSame(['webp', 'avif'], array_values(array_unique(array_column($asset->variants, 'format'))));
        } finally {
            @unlink($path);
        }
    }

    public function test_failed_encoding_keeps_private_input_for_retry_and_does_not_publish_a_partial_manifest(): void
    {
        $asset = app(ReviewImageAssets::class)->stage(UploadedFile::fake()->image('review.png', 80, 120), 'screenshot', User::factory()->create());
        config(['review-media.binary' => '/missing/image-handler']);
        try {
            app(ReviewImageAssets::class)->process($asset->id);
            $this->fail('Missing decoder must fail.');
        } catch (\InvalidArgumentException) {
            $this->assertSame('failed', $asset->fresh()->status);
            $this->assertSame([], $asset->fresh()->publicVariants());
            Storage::disk('local')->assertExists($asset->source_path);
        }
        config(['review-media.binary' => null]);
        app(ReviewImageAssets::class)->process($asset->id);
        $this->assertSame('ready', $asset->fresh()->status);
    }

    public function test_partial_storage_failure_keeps_the_original_until_a_complete_retry(): void
    {
        $asset = app(ReviewImageAssets::class)->stage(UploadedFile::fake()->image('review.png', 200, 400), 'screenshot', User::factory()->create());
        $source = $asset->source_path;
        $disk = Storage::disk('public');
        $writes = 0;
        $failingDisk = \Mockery::mock($disk);
        $failingDisk->shouldReceive('put')->andReturnUsing(function (...$arguments) use ($disk, &$writes): bool {
            return ++$writes === 2 ? false : $disk->put(...$arguments);
        });
        Storage::set('public', $failingDisk);
        try {
            app(ReviewImageAssets::class)->process($asset->id);
            $this->fail('A partial upload must not become ready.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('сохранить готовое', $exception->getMessage());
            $this->assertSame('failed', $asset->fresh()->status);
            $this->assertNull($asset->fresh()->variants);
            $this->assertSame([], $asset->fresh()->publicVariants());
            Storage::disk('local')->assertExists($source);
            $this->assertCount(1, $disk->allFiles());
        } finally {
            Storage::set('public', $disk);
        }
        app(ReviewImageAssets::class)->process($asset->id);
        $this->assertSame('ready', $asset->fresh()->status);
        $this->assertCount(6, $asset->fresh()->variants);
        Storage::disk('local')->assertMissing($source);
    }

    public function test_orientation_alpha_and_existing_avif_webp_are_handled(): void
    {
        $image = imagecreatetruecolor(80, 120);
        imagealphablending($image, false);
        imagesavealpha($image, true);
        imagefill($image, 0, 0, imagecolorallocatealpha($image, 0, 0, 0, 127));
        imagefilledrectangle($image, 20, 20, 60, 80, imagecolorallocatealpha($image, 20, 100, 80, 0));
        $owner = User::factory()->create();
        foreach (['png', 'webp', 'avif'] as $format) {
            ob_start();
            match ($format) {
                'webp' => imagewebp($image, null, 90), 'avif' => imageavif($image, null, 85), default => imagepng($image),
            };
            $asset = app(ReviewImageAssets::class)->stage(UploadedFile::fake()->createWithContent('alpha.'.$format, ob_get_clean()), 'screenshot', $owner);
            app(ReviewImageAssets::class)->process($asset->id);
            $output = imagecreatefromstring(Storage::disk('public')->get($asset->primary_path));
            $this->assertSame(127, imagecolorsforindex($output, imagecolorat($output, 0, 0))['alpha']);
        }
        $jpeg = UploadedFile::fake()->image('phone.jpg', 80, 40)->get();
        $tiff = "II\x2a\x00\x08\x00\x00\x00".pack('v', 1).pack('vvVv', 0x0112, 3, 1, 6)."\0\0".pack('V', 0);
        $exif = "Exif\0\0".$tiff;
        $jpeg = substr($jpeg, 0, 2)."\xff\xe1".pack('n', strlen($exif) + 2).$exif.substr($jpeg, 2);
        $asset = app(ReviewImageAssets::class)->stage(UploadedFile::fake()->createWithContent('phone.PNG', $jpeg), 'screenshot', $owner);
        app(ReviewImageAssets::class)->process($asset->id);
        $asset->refresh();
        $this->assertSame([40, 80], [$asset->width, $asset->height]);
    }

    public function test_cleanup_removes_abandoned_and_detached_outputs_but_keeps_attached_assets(): void
    {
        $owner = User::factory()->create();
        $asset = app(ReviewImageAssets::class)->stage(UploadedFile::fake()->image('review.png', 40, 80), 'screenshot', $owner);
        app(ReviewImageAssets::class)->process($asset->id);
        $asset->refresh();
        $review = Review::create(['source' => 'admin', 'display_name' => 'Анна', 'text' => 'Полезный курс']);
        app(ReviewImageAssets::class)->attach($owner, $review, [$asset->primary_path]);
        $asset->refresh()->update(['updated_at' => now()->subDays(2)]);
        $this->assertSame(0, app(ReviewImageAssets::class)->cleanup());
        // attach alone never detaches imported assets; explicit removals are handled after a save.
        app(ReviewImageAssets::class)->attach($owner, $review, []);
        $this->assertSame($review->id, $asset->fresh()->review_id);
        $asset->update(['review_id' => null]);
        $asset->refresh()->update(['updated_at' => now()->subDays(2)]);
        $this->assertSame(1, app(ReviewImageAssets::class)->cleanup());
        Storage::disk('public')->assertMissing($asset->primary_path);
        $this->assertSame([], Storage::disk('public')->allFiles());
    }
}
