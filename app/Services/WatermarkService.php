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

            // Upright seal, centred on the page. This is now the ONLY logo a
            // reader sees — the viewer used to lay six more 96px seals over the
            // top of it, which is what actually crowded the page.
            //
            // Sizing and opacity move together, and must. At 0.5 the seal
            // covered a quarter of the page at 15% ink; scaling it to 0.78
            // covers well over half, so holding the alpha there would put half
            // again as much ink across the body text. Dropping to 10% keeps the
            // per-pixel wash lighter than before, so a bigger mark reaching into
            // the margins still reads as a watermark rather than a veil over the
            // words.
            $logoSize = min($size['width'], $size['height']) * 0.78;
            $pdf->SetAlpha(0.10);
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