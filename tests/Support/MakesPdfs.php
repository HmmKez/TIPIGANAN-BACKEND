<?php

namespace Tests\Support;

use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

// Builds real PDFs for tests instead of committing binary fixtures, so the
// exact shape under test (object streams, encryption) is visible in the test
// rather than hidden inside an opaque file nobody can inspect in a diff.
trait MakesPdfs
{
    protected function qpdfPath(): ?string
    {
        $configured = config('thesis.qpdf_binary', 'qpdf');

        if ($configured !== 'qpdf' && is_file($configured)) {
            return $configured;
        }

        return (new ExecutableFinder())->find('qpdf');
    }

    // A one-page PDF with a classic cross-reference table — the old-style
    // structure FPDI can read. This is the "good" case everything else is
    // built from.
    //
    // $textOps pads the content stream with repeated drawing operations. A
    // stream of a few dozen bytes compresses to nothing and decompresses to
    // nothing, so a small fixture cannot show the difference between a
    // compressed rewrite and an uncompressed one.
    protected function minimalPdf(int $textOps = 1): string
    {
        $content = '';
        for ($i = 0; $i < $textOps; $i++) {
            $content .= 'BT /F1 24 Tf 100 700 Td (Fixture line ' . $i . ") Tj ET\n";
        }

        $objects = [
            "1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n",
            "2 0 obj\n<< /Type /Pages /Kids [3 0 R] /Count 1 >>\nendobj\n",
            "3 0 obj\n<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Contents 4 0 R /Resources << >> >>\nendobj\n",
            "4 0 obj\n<< /Length " . strlen($content) . " >>\nstream\n" . $content . "endstream\nendobj\n",
        ];

        $pdf     = "%PDF-1.4\n";
        $offsets = [];

        foreach ($objects as $object) {
            $offsets[] = strlen($pdf);
            $pdf .= $object;
        }

        $xrefPos = strlen($pdf);
        $pdf .= "xref\n0 " . (count($objects) + 1) . "\n0000000000 65535 f \n";

        foreach ($offsets as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }

        $pdf .= 'trailer
<< /Size ' . (count($objects) + 1) . " /Root 1 0 R >>\nstartxref\n{$xrefPos}\n%%EOF\n";

        return $pdf;
    }

    // Rewrites a PDF through qpdf into whatever shape the test needs (object
    // streams, encryption, ...) and returns its bytes. Returns null when qpdf
    // isn't available, so callers can skip rather than fail.
    protected function pdfBuiltWith(array $qpdfArgs, int $textOps = 1): ?string
    {
        $qpdf = $this->qpdfPath();

        if (! $qpdf) {
            return null;
        }

        $dir = storage_path('framework/testing/makespdfs');

        if (! is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        $in  = $dir . '/in-' . uniqid() . '.pdf';
        $out = $dir . '/out-' . uniqid() . '.pdf';
        file_put_contents($in, $this->minimalPdf($textOps));

        $process = new Process(array_merge([$qpdf], $qpdfArgs, [$in, $out]));
        $process->setTimeout(120);
        $process->run();

        $bytes = is_file($out) ? file_get_contents($out) : null;

        @unlink($in);
        @unlink($out);

        return $bytes ?: null;
    }
}
