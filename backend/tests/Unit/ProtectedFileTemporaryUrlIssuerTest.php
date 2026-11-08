<?php

namespace Tests\Unit;

use App\Services\ProtectedFiles\FilesystemTemporaryDownloadUrlIssuer;
use Carbon\CarbonImmutable;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;
use UnexpectedValueException;

class ProtectedFileTemporaryUrlIssuerTest extends TestCase
{
    public function test_missing_private_object_does_not_receive_a_temporary_url(): void
    {
        $filesystem = Mockery::mock(FilesystemAdapter::class);
        $filesystem->shouldReceive('exists')->once()->with('protected/copy.pdf')->andReturnFalse();
        $filesystem->shouldNotReceive('temporaryUrl');
        Storage::shouldReceive('disk')->once()->with('protected-output')->andReturn($filesystem);

        $this->expectException(UnexpectedValueException::class);

        (new FilesystemTemporaryDownloadUrlIssuer)->issue(
            'protected-output',
            'protected/copy.pdf',
            CarbonImmutable::now()->addMinutes(2),
            'notes.pdf',
        );
    }

    public function test_existing_private_object_receives_only_supported_temporary_url(): void
    {
        $expiresAt = CarbonImmutable::now()->addMinutes(2);
        $filesystem = Mockery::mock(FilesystemAdapter::class);
        $filesystem->shouldReceive('exists')->once()->with('protected/copy.pdf')->andReturnTrue();
        $filesystem->shouldReceive('temporaryUrl')
            ->once()
            ->with(
                'protected/copy.pdf',
                $expiresAt,
                Mockery::on(fn (array $options): bool => $options['ResponseContentType'] === 'application/pdf'
                    && str_contains($options['ResponseContentDisposition'], 'notes.pdf')),
            )
            ->andReturn('https://files.example.test/protected/copy.pdf?signature=short-lived');
        Storage::shouldReceive('disk')->once()->with('protected-output')->andReturn($filesystem);

        $url = (new FilesystemTemporaryDownloadUrlIssuer)->issue(
            'protected-output',
            'protected/copy.pdf',
            $expiresAt,
            'notes.pdf',
        );

        $this->assertSame(
            'https://files.example.test/protected/copy.pdf?signature=short-lived',
            $url,
        );
    }

    public function test_non_ascii_filename_receives_ascii_fallback_disposition(): void
    {
        $expiresAt = CarbonImmutable::now()->addMinutes(2);
        $filesystem = Mockery::mock(FilesystemAdapter::class);
        $filesystem->shouldReceive('exists')->once()->with('protected/copy.pdf')->andReturnTrue();
        $filesystem->shouldReceive('temporaryUrl')
            ->once()
            ->with(
                'protected/copy.pdf',
                $expiresAt,
                Mockery::on(function (array $options): bool {
                    $disposition = $options['ResponseContentDisposition'] ?? '';

                    return $options['ResponseContentType'] === 'application/pdf'
                        && is_string($disposition)
                        && str_contains($disposition, 'filename=')
                        && str_contains($disposition, "filename*=utf-8''")
                        && str_contains($disposition, rawurlencode('Введение.pdf'));
                }),
            )
            ->andReturn('https://files.example.test/protected/copy.pdf?signature=short-lived');
        Storage::shouldReceive('disk')->once()->with('protected-output')->andReturn($filesystem);

        $url = (new FilesystemTemporaryDownloadUrlIssuer)->issue(
            'protected-output',
            'protected/copy.pdf',
            $expiresAt,
            'Введение.pdf',
        );

        $this->assertSame(
            'https://files.example.test/protected/copy.pdf?signature=short-lived',
            $url,
        );
    }

    public function test_non_http_storage_url_is_rejected(): void
    {
        $filesystem = Mockery::mock(FilesystemAdapter::class);
        $filesystem->shouldReceive('exists')->once()->andReturnTrue();
        $filesystem->shouldReceive('temporaryUrl')->once()->andReturn('file:///private/copy.pdf');
        Storage::shouldReceive('disk')->once()->andReturn($filesystem);

        $this->expectException(UnexpectedValueException::class);

        (new FilesystemTemporaryDownloadUrlIssuer)->issue(
            'protected-output',
            'protected/copy.pdf',
            CarbonImmutable::now()->addMinutes(2),
            'notes.pdf',
        );
    }
}
