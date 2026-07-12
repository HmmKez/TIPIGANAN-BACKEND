<?php

namespace App\Services;

use App\Services\Pdf\WatermarkPdf;

class WatermarkService
{
    public function stamp(string $absolutePath): string
    {
        $pdf = new WatermarkPdf();
        $pageCount = $pdf->setSourceFile($absolutePath);
        $logoPath  = resource_path('images/mdc-logo.png');

        for ($i = 1; $i <= $pageCount; $i++) {
            $tplId = $pdf->importPage($i);
            $size  = $pdf->getTemplateSize($tplId);

            // Add a page matching the original's orientation and size
            $orientation = $size['width'] > $size['height'] ? 'L' : 'P';
            $pdf->AddPage($orientation, [$size['width'], $size['height']]);
            $pdf->useTemplate($tplId);

            // Upright logo watermark, centered on the page
            $logoSize = min($size['width'], $size['height']) * 0.5;
            $pdf->SetAlpha(0.15);
            $pdf->Image(
                $logoPath,
                $size['width'] / 2 - $logoSize / 2,
                $size['height'] / 2 - $logoSize / 2,
                $logoSize,
                $logoSize,
                'PNG'
            );
            $pdf->SetAlpha(1); // reset
        }

        // Return as string (in-memory, not saved to disk)
        return $pdf->Output('S');
    }
}