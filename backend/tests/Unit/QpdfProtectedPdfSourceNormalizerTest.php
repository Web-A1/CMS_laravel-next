<?php

namespace Tests\Unit;

use App\Exceptions\ProtectedFileGenerationException;
use App\Services\ProtectedFiles\QpdfProtectedPdfSourceNormalizer;
use Tests\TestCase;

class QpdfProtectedPdfSourceNormalizerTest extends TestCase
{
    public function test_normalizes_with_configured_qpdf_binary_and_cleans_output_file(): void
    {
        $recordPath = tempnam(sys_get_temp_dir(), 'cms-qpdf-record-');
        $binaryPath = $this->fakeQpdfBinary(0, $recordPath);
        config()->set('protected_files.qpdf_binary', $binaryPath);
        config()->set('protected_files.qpdf_timeout_seconds', 5);
        config()->set('protected_files.max_source_bytes', 1024 * 1024);

        $source = $this->pdfStream("%PDF-1.4\nsource\n%%EOF");
        $normalized = (new QpdfProtectedPdfSourceNormalizer)->normalize($source);

        $this->assertSame("%PDF-1.4\nsource\n%%EOF", stream_get_contents($normalized));
        $record = json_decode((string) file_get_contents((string) $recordPath), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('--object-streams=disable', $record['args'][0]);
        $this->assertSame('--stream-data=preserve', $record['args'][1]);
        $this->assertSame('--normalize-content=n', $record['args'][2]);
        $this->assertFileDoesNotExist($record['output_path']);

        fclose($source);
        fclose($normalized);
        @unlink($binaryPath);
        @unlink((string) $recordPath);
    }

    public function test_qpdf_warning_exit_code_is_accepted_when_output_is_valid_pdf(): void
    {
        $recordPath = tempnam(sys_get_temp_dir(), 'cms-qpdf-record-');
        $binaryPath = $this->fakeQpdfBinary(3, $recordPath);
        config()->set('protected_files.qpdf_binary', $binaryPath);
        config()->set('protected_files.qpdf_timeout_seconds', 5);

        $source = $this->pdfStream("%PDF-1.4\nwarning\n%%EOF");
        $normalized = (new QpdfProtectedPdfSourceNormalizer)->normalize($source);

        $this->assertStringStartsWith('%PDF-', stream_get_contents($normalized));

        fclose($source);
        fclose($normalized);
        @unlink($binaryPath);
        @unlink((string) $recordPath);
    }

    public function test_non_warning_qpdf_failure_is_reported_as_unsupported_pdf(): void
    {
        $recordPath = tempnam(sys_get_temp_dir(), 'cms-qpdf-record-');
        $binaryPath = $this->fakeQpdfBinary(2, $recordPath);
        config()->set('protected_files.qpdf_binary', $binaryPath);
        config()->set('protected_files.qpdf_timeout_seconds', 5);

        $source = $this->pdfStream("%PDF-1.4\nsource\n%%EOF");

        $this->expectException(ProtectedFileGenerationException::class);
        $this->expectExceptionMessage(ProtectedFileGenerationException::PDF_UNSUPPORTED);

        try {
            (new QpdfProtectedPdfSourceNormalizer)->normalize($source);
        } finally {
            fclose($source);
            @unlink($binaryPath);
            @unlink((string) $recordPath);
        }
    }

    public function test_rejects_unsafe_qpdf_binary_configuration(): void
    {
        config()->set('protected_files.qpdf_binary', 'qpdf --bad');
        $source = $this->pdfStream("%PDF-1.4\nsource\n%%EOF");

        $this->expectException(ProtectedFileGenerationException::class);
        $this->expectExceptionMessage(ProtectedFileGenerationException::PDF_UNSUPPORTED);

        try {
            (new QpdfProtectedPdfSourceNormalizer)->normalize($source);
        } finally {
            fclose($source);
        }
    }

    /** @return resource */
    private function pdfStream(string $contents): mixed
    {
        $stream = tmpfile();
        $this->assertIsResource($stream);
        fwrite($stream, $contents);
        rewind($stream);

        return $stream;
    }

    private function fakeQpdfBinary(int $exitCode, string|false $recordPath): string
    {
        $this->assertIsString($recordPath);
        $binaryPath = tempnam(sys_get_temp_dir(), 'cms-fake-qpdf-');
        $this->assertIsString($binaryPath);
        $script = <<<'SH'
#!/bin/sh
record_path="__RECORD_PATH__"
output_path="$5"
cp "$4" "$output_path"
php -r '$record=$argv[1]; $output=$argv[2]; $args=array_slice($argv, 3); file_put_contents($record, json_encode(["output_path"=>$output, "args"=>$args], JSON_UNESCAPED_SLASHES));' "$record_path" "$output_path" "$@"
exit __EXIT_CODE__
SH;
        $script = str_replace(
            ['__RECORD_PATH__', '__EXIT_CODE__'],
            [str_replace("'", "'\\''", $recordPath), (string) $exitCode],
            $script,
        );
        file_put_contents($binaryPath, $script);
        chmod($binaryPath, 0700);

        return $binaryPath;
    }
}
