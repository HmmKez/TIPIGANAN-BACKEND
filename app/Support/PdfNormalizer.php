<?php

namespace App\Support;

use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Exception\ExceptionInterface as ProcessExceptionInterface;
use Symfony\Component\Process\Process;

// Some PDF producers (Word/LibreOffice "Save as PDF", scanners, some OCR
// tools) write modern PDFs using compressed cross-reference streams and
// object streams (PDF 1.5+). The free tier of setasign/fpdi — which
// WatermarkPdf/WatermarkService depend on — can't parse that structure at
// all, and throws "Could not load the PDF" the moment it tries.
//
// Rather than require every uploader's PDF to already be in an
// FPDI-compatible shape (or require a paid FPDI-PDF-Parser license), this
// rewrites the file once, right after upload, into an older/uncompressed
// structure that FPDI can always read — using qpdf, a free, widely
// available CLI tool.
//
// qpdf may not be installed on every machine yet (e.g. a teammate's local
// Laragon setup, or an undecided deployment target). Same "degrade
// gracefully" rule as SafeCache: if qpdf is missing or the rewrite fails for
// any reason, we log it and leave the original file exactly as uploaded.
// The upload itself must never fail because of this step — it only means
// that particular thesis won't be watermarkable until the file is
// re-normalized (e.g. after qpdf gets installed) or replaced.
class PdfNormalizer
{
    // Disabling object streams is the whole fix: it also forces qpdf to write
    // a classic cross-reference table instead of an xref stream, which is
    // exactly the pair FPDI 2.x cannot parse. Compression of the page content
    // itself is untouched, because FPDI reads Flate-compressed streams fine.
    //
    // --decrypt covers the OTHER half of what FPDI refuses. Encryption is not
    // the same problem as compression, and it is at least as common: Acrobat's
    // "restrict editing", most journal downloads and some scanners produce a
    // PDF with an owner password and NO user password. It opens normally in
    // every reader, so nothing looks wrong — but FPDI rejects it outright
    // ("This PDF document is encrypted"), and without this the thesis simply
    // never displays. Verified against RC4-40, AES-128 and AES-256 fixtures:
    // all three fail on upload and all three open after this.
    //
    // This does not bypass access control. qpdf can only decrypt what already
    // opens WITHOUT a password; a PDF carrying a real user password makes qpdf
    // exit non-zero, which lands in the catch below and leaves the file exactly
    // as uploaded. Verified.
    //
    // Do NOT add --qdf here. It rewrites the file into qpdf's uncompressed,
    // human-readable debugging form, which is not needed for FPDI and is
    // enormously expensive: measured on a real 2.0 MB thesis, --qdf produced
    // 44.4 MB where this produces 2.7 MB (16x), and since servePdf() stamps
    // and streams the file on EVERY view, that difference is paid again on
    // every single read, not just once on disk.
    private const QPDF_ARGS = ['--object-streams=disable', '--decrypt'];

    public static function normalize(string $absolutePath): bool
    {
        $tmpPath = $absolutePath . '.normalized.tmp';

        try {
            $process = new Process([
                config('thesis.qpdf_binary', 'qpdf'),
                ...self::QPDF_ARGS,
                $absolutePath,
                $tmpPath,
            ]);
            $process->setTimeout(30);
            $process->run();

            // qpdf returns 0 for a clean rewrite and 3 for "succeeded with
            // warnings" (e.g. minor spec violations in the source file that
            // it repaired anyway) — both mean $tmpPath is usable.
            if (! in_array($process->getExitCode(), [0, 3], true)) {
                throw new \RuntimeException($process->getErrorOutput() ?: 'qpdf exited with status ' . $process->getExitCode());
            }

            if (! is_file($tmpPath) || filesize($tmpPath) === 0) {
                throw new \RuntimeException('qpdf produced no output file.');
            }

            // rename() returns false on failure rather than throwing, so it
            // has to be checked — otherwise a failed replace would fall
            // through and report success while leaving the ORIGINAL file in
            // place, which is worse than a clean failure: the caller believes
            // the PDF is now FPDI-readable when it isn't.
            //
            // On Windows the replace also fails outright with "Access is
            // denied" if anything still holds the destination open (FPDI keeps
            // a stream open on a file it has parsed), where POSIX would just
            // swap it. The fallback moves the original ASIDE rather than
            // deleting it: deleting first would mean that if the second rename
            // then failed, the catch below would unlink the temp file too and
            // the thesis PDF would be gone entirely. Moved aside, the original
            // can always be put back.
            if (! @rename($tmpPath, $absolutePath)) {
                $backupPath = $absolutePath . '.orig.bak';
                @unlink($backupPath);

                if (! @rename($absolutePath, $backupPath)) {
                    throw new \RuntimeException('Could not move the original PDF aside to replace it.');
                }

                if (! @rename($tmpPath, $absolutePath)) {
                    @rename($backupPath, $absolutePath);

                    throw new \RuntimeException('Could not replace the original PDF with the normalized one.');
                }

                @unlink($backupPath);
            }

            return true;
        } catch (ProcessExceptionInterface|\Throwable $e) {
            @unlink($tmpPath);

            Log::warning('PDF normalization skipped (qpdf unavailable or failed); file stored as-is.', [
                'path'  => $absolutePath,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }
}