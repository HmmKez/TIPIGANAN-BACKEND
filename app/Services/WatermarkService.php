<?php

namespace App\Services;

use App\Services\Pdf\WatermarkPdf;

class WatermarkService
{
    public function stamp(string $absolutePath): string
    {
        $pdf = new WatermarkPdf();
        $pageCount = $pdf->setSourceFile($absolutePath);

        for ($i = 1; $i <= $pageCount; $i++) {
            $tplId = $pdf->importPage($i);
            $size  = $pdf->getTemplateSize($tplId);

            // Add a page matching the original's orientation and size
            $orientation = $size['width'] > $size['height'] ? 'L' : 'P';
            $pdf->AddPage($orientation, [$size['width'], $size['height']]);
            $pdf->useTemplate($tplId);

            // Diagonal watermark
            $pdf->SetFont('Helvetica', 'B', 45);
            $pdf->SetTextColor(255, 0, 0);
            $pdf->SetAlpha(0.3);              // 25% opacity

            // Rotate and center the text diagonally
            $pdf->StartTransform();
            $pdf->Rotate(45, $size['width'] / 2, $size['height'] / 2);
            $pdf->SetXY(0, $size['height'] / 2 - 10);
            $pdf->Cell($size['width'], 20, 'TIPIGANAN - FOR VIEWING ONLY', 0, 0, 'C');
            $pdf->StopTransform();

            $pdf->SetAlpha(1); // reset
        }

        // Return as string (in-memory, not saved to disk)
        return $pdf->Output('S');
    }
}