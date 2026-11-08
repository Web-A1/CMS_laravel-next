<?php

namespace Tests\Feature;

use App\Jobs\ProcessSupportImage;
use App\Models\AdminSupportTicket;
use App\Models\SupportAttachment;
use App\Services\Reviews\ReviewImageProcessor;
use App\Services\Support\SupportImages;
use App\Services\Support\SupportOperations;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;
use Tests\Concerns\SupportDatabase;
use Tests\TestCase;

class SupportImagesTest extends TestCase
{
    use SupportDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Storage::fake('support-private');
    }

    private function accept(UploadedFile $file): SupportAttachment
    {
        app(SupportOperations::class)->submit('guest@example.test', 'Скриншот с мелким текстом', 'image-key-'.bin2hex(random_bytes(8)), [$file]);

        return SupportAttachment::latest('id')->firstOrFail();
    }

    public function test_screenshot_keeps_full_resolution_and_uses_private_disk_and_verified_webp(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'support-screen-');
        try {
            $font = is_file('/System/Library/Fonts/Supplemental/Arial.ttf') ? '/System/Library/Fonts/Supplemental/Arial.ttf' : 'DejaVu-Sans';
            (new Process(['magick', '-size', '640x960', 'xc:white', '-font', $font, '-fill', 'black', '-pointsize', '12', '-annotate', '+20+30', 'Error 422: description is required', 'PNG:'.$path]))->mustRun();
            $attachment = $this->accept(new UploadedFile($path, 'screen.png', 'image/png', null, true));
            $source = $attachment->source_key;
            (new ProcessSupportImage($attachment->id))->handle(app(ReviewImageProcessor::class), app(SupportImages::class));
            $attachment->refresh();
            $this->assertSame('ready', $attachment->status);
            $this->assertSame([640, 960], [$attachment->width, $attachment->height]);
            $bytes = Storage::disk('support-private')->get($attachment->preview_key);
            $dimensions = getimagesizefromstring($bytes);
            $this->assertSame([640, 960], [$dimensions[0], $dimensions[1]]);
            $this->assertSame('image/webp', $dimensions['mime']);
            Storage::disk('support-private')->assertMissing($source);
            $manifest = $attachment->toArray();
            (new ProcessSupportImage($attachment->id))->handle(app(ReviewImageProcessor::class), app(SupportImages::class));
            $this->assertSame($manifest, $attachment->fresh()->toArray());
            $this->assertSame('support-private', $attachment->disk);
        } finally {
            @unlink($path);
        }
    }

    public function test_real_heic_and_jpeg_orientation_are_decoded_without_public_media_lifecycle(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'support-heic-');
        try {
            (new Process(['magick', '-size', '80x120', 'gradient:red-blue', 'HEIC:'.$path]))->mustRun();
            $attachment = $this->accept(new UploadedFile($path, 'phone.heic', null, null, true));
            (new ProcessSupportImage($attachment->id))->handle(app(ReviewImageProcessor::class), app(SupportImages::class));
            $this->assertSame('ready', $attachment->fresh()->status);
            $this->assertSame([80, 120], [$attachment->fresh()->width, $attachment->fresh()->height]);

            $jpeg = UploadedFile::fake()->image('rotate.jpg', 80, 120)->get();
            // Actual EXIF little endian orientation 6: decoder must rotate 90 degrees clockwise.
            $exif = "Exif\0\0II\x2a\x00\x08\x00\x00\x00\x01\x00\x12\x01\x03\x00\x01\x00\x00\x00\x06\x00\x00\x00\x00\x00\x00\x00";
            $jpeg = substr($jpeg, 0, 2)."\xff\xe1".pack('n', strlen($exif) + 2).$exif.substr($jpeg, 2);
            $attachment = $this->accept(UploadedFile::fake()->createWithContent('rotate.jpg', $jpeg));
            (new ProcessSupportImage($attachment->id))->handle(app(ReviewImageProcessor::class), app(SupportImages::class));
            $this->assertSame([120, 80], [$attachment->fresh()->width, $attachment->fresh()->height]);
            $this->assertDatabaseCount('review_image_assets', 0);
        } finally {
            @unlink($path);
        }
    }

    public function test_mixed_files_keep_text_valid_file_and_early_warning_and_enforce_limits(): void
    {
        $good = UploadedFile::fake()->image('image.png', 40, 80);
        $bad = UploadedFile::fake()->createWithContent('fake.jpg', 'Not an image');
        $large = UploadedFile::fake()->createWithContent('large.png', $good->get().str_repeat('x', 10 * 1024 * 1024));
        $result = app(SupportOperations::class)->submit('guest@example.test', 'Text retained', 'mixed-files-key', [$good, $bad, $large]);
        $this->assertCount(2, $result['data']['warnings']);
        $this->assertDatabaseCount('support_attachments', 1);
        $this->assertDatabaseCount('support_messages', 1);
        $this->assertSame('Text retained', AdminSupportTicket::first()->messages->first()->body);
    }
}
