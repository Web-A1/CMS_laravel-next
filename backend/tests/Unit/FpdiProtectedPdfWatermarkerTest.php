<?php

namespace Tests\Unit;

use App\Exceptions\ProtectedFileGenerationException;
use App\Services\ProtectedFiles\FpdiProtectedPdfWatermarker;
use setasign\Fpdi\Fpdi;
use setasign\Fpdi\PdfParser\CrossReference\CrossReferenceException;
use Tests\TestCase;

class FpdiProtectedPdfWatermarkerTest extends TestCase
{
    public function test_watermark_is_written_twice_on_every_page_and_source_bytes_are_unchanged(): void
    {
        $sourceBytes = $this->sourcePdf(2);
        $source = $this->stream($sourceBytes);
        $watermark = 'Christmedschool | COPY-ABCDEF1234567890';

        $output = (new FpdiProtectedPdfWatermarker)->watermark($source, $watermark, 2);

        $this->assertStringStartsWith('%PDF-', $output);
        $this->assertSame(4, substr_count($output, $watermark));
        $this->assertSame(2, $this->pageCount($output));
        rewind($source);
        $this->assertSame($sourceBytes, stream_get_contents($source));
        fclose($source);
    }

    public function test_page_limit_plus_one_fails_closed(): void
    {
        $source = $this->stream($this->sourcePdf(2));

        $exception = $this->captureFailure(
            fn () => (new FpdiProtectedPdfWatermarker)->watermark(
                $source,
                'Christmedschool | COPY-LIMIT1234567890',
                1,
            ),
        );

        $this->assertSame(
            ProtectedFileGenerationException::PDF_PAGE_LIMIT_EXCEEDED,
            $exception->errorCode,
        );
        fclose($source);
    }

    public function test_malformed_pdf_fails_with_stable_code_without_parser_details(): void
    {
        $source = $this->stream("%PDF-1.7\nprivate parser sentinel");

        $exception = $this->captureFailure(
            fn () => (new FpdiProtectedPdfWatermarker)->watermark(
                $source,
                'Christmedschool | COPY-BROKEN123456789',
                10,
            ),
        );

        $this->assertSame(ProtectedFileGenerationException::PDF_UNSUPPORTED, $exception->errorCode);
        $this->assertSame('pdf_unsupported', $exception->getMessage());
        $this->assertNull($exception->getPrevious());
        $this->assertStringNotContainsString('private parser sentinel', $exception->getMessage());
        fclose($source);
    }

    public function test_encrypted_pdf_marker_fails_closed(): void
    {
        $pdf = $this->sourcePdf(1);
        $encrypted = str_replace('/Root ', '/Encrypt 999 0 R /Root ', $pdf);
        $this->assertNotSame($pdf, $encrypted);
        $source = $this->stream($encrypted);
        $this->assertCrossReferenceFailure($source, CrossReferenceException::ENCRYPTED);

        $exception = $this->captureFailure(
            fn () => (new FpdiProtectedPdfWatermarker)->watermark(
                $source,
                'Christmedschool | COPY-LOCKED123456789',
                10,
            ),
        );

        $this->assertSame(ProtectedFileGenerationException::PDF_UNSUPPORTED, $exception->errorCode);
        fclose($source);
    }

    public function test_pdf_15_cross_reference_stream_fails_closed(): void
    {
        $source = $this->stream($this->xrefStreamPdf());
        $this->assertCrossReferenceFailure($source, CrossReferenceException::COMPRESSED_XREF);

        $exception = $this->captureFailure(
            fn () => (new FpdiProtectedPdfWatermarker)->watermark(
                $source,
                'Christmedschool | COPY-XREF12345678901',
                10,
            ),
        );

        $this->assertSame(ProtectedFileGenerationException::PDF_UNSUPPORTED, $exception->errorCode);
        fclose($source);
    }

    private function sourcePdf(int $pages): string
    {
        $pdf = new \FPDF;
        for ($page = 1; $page <= $pages; $page++) {
            $pdf->AddPage();
            $pdf->SetFont('Helvetica', '', 12);
            $pdf->Cell(40, 10, "Source page {$page}");
        }

        return $pdf->Output('S');
    }

    /** @return resource */
    private function stream(string $contents): mixed
    {
        $stream = tmpfile();
        $this->assertIsResource($stream);
        fwrite($stream, $contents);
        rewind($stream);

        return $stream;
    }

    private function pageCount(string $pdfBytes): int
    {
        $stream = $this->stream($pdfBytes);
        try {
            return (new Fpdi)->setSourceFile($stream);
        } finally {
            fclose($stream);
        }
    }

    /** @param resource $source */
    private function assertCrossReferenceFailure(mixed $source, int $expectedCode): void
    {
        try {
            (new Fpdi)->setSourceFile($source);
            $this->fail('Expected an FPDI cross-reference failure.');
        } catch (CrossReferenceException $exception) {
            $this->assertSame($expectedCode, $exception->getCode());
        } finally {
            rewind($source);
        }
    }

    private function xrefStreamPdf(): string
    {
        $prefix = "%PDF-1.5\n"
            ."1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n"
            ."2 0 obj\n<< /Type /Pages /Count 0 /Kids [] >>\nendobj\n";
        $xrefOffset = strlen($prefix);

        return $prefix
            ."3 0 obj\n<< /Type /XRef /Length 0 /W [1 2 1] /Size 4 /Root 1 0 R >>\n"
            ."stream\n\nendstream\nendobj\n"
            ."startxref\n{$xrefOffset}\n%%EOF\n";
    }

    private function captureFailure(callable $callback): ProtectedFileGenerationException
    {
        try {
            $callback();
        } catch (ProtectedFileGenerationException $exception) {
            return $exception;
        }

        $this->fail('Expected FPDI watermarking to fail.');
    }
}
