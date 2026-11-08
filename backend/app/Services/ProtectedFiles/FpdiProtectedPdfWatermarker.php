<?php

namespace App\Services\ProtectedFiles;

use App\Contracts\ProtectedFiles\ProtectedPdfWatermarker;
use App\Exceptions\ProtectedFileGenerationException;
use setasign\Fpdi\Fpdi;
use setasign\Fpdi\PdfReader\PageBoundaries;
use Throwable;

class FpdiProtectedPdfWatermarker implements ProtectedPdfWatermarker
{
    public function watermark(mixed $source, string $watermark, int $maxPages): string
    {
        if (! is_resource($source) || $maxPages < 1) {
            throw new ProtectedFileGenerationException(
                ProtectedFileGenerationException::SOURCE_METADATA_INVALID,
            );
        }

        try {
            rewind($source);
            $pdf = new Fpdi;
            $pdf->SetCompression(false);
            $pageCount = $pdf->setSourceFile($source);
            if ($pageCount < 1 || $pageCount > $maxPages) {
                throw new ProtectedFileGenerationException(
                    ProtectedFileGenerationException::PDF_PAGE_LIMIT_EXCEEDED,
                );
            }

            for ($page = 1; $page <= $pageCount; $page++) {
                $template = $pdf->importPage(
                    $page,
                    PageBoundaries::CROP_BOX,
                    true,
                    false,
                );
                $size = $pdf->getTemplateSize($template);
                $width = (float) $size['width'];
                $height = (float) $size['height'];
                if ($width <= 0 || $height <= 0 || $width > 2000 || $height > 2000) {
                    throw new ProtectedFileGenerationException(
                        ProtectedFileGenerationException::PDF_UNSUPPORTED,
                    );
                }

                $pdf->AddPage($width > $height ? 'L' : 'P', [$width, $height]);
                $pdf->useTemplate($template);
                $this->writeWatermark($pdf, $watermark, $width, $height);
            }

            $output = $pdf->Output('S');
            if (! is_string($output) || ! str_starts_with($output, '%PDF-')) {
                throw new ProtectedFileGenerationException(
                    ProtectedFileGenerationException::OUTPUT_INTEGRITY_FAILED,
                );
            }

            return $output;
        } catch (ProtectedFileGenerationException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new ProtectedFileGenerationException(
                ProtectedFileGenerationException::PDF_UNSUPPORTED,
            );
        }
    }

    private function writeWatermark(Fpdi $pdf, string $watermark, float $width, float $height): void
    {
        $pdf->SetFont('Helvetica', '', 7);
        $pdf->SetTextColor(96, 96, 96);
        $textWidth = $pdf->GetStringWidth($watermark);
        $x = max(4.0, ($width - $textWidth) / 2);

        $pdf->Text($x, 6, $watermark);
        $pdf->Text($x, max(10.0, $height - 5), $watermark);
    }
}
