<?php

namespace App\Console\Commands;

use App\Models\Thesis;
use App\Services\WatermarkService;
use App\Support\PdfNormalizer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

// One-off backfill for theses uploaded BEFORE PdfNormalizer was wired into
// store()/replaceFile(). Those files were stored exactly as the uploader
// produced them, so any written with object streams / xref streams (PDF 1.5+,
// which is what Word and most scanners emit) cannot be opened by the free
// FPDI tier at all — meaning servePdf() throws and the thesis simply will not
// open in the viewer. New uploads are normalized on the way in; this fixes
// the ones already in the archive.
//
// Safe to re-run: each file is probed with FPDI first and only rewritten if it
// actually fails, so an already-readable thesis is left byte-identical rather
// than being needlessly rewritten (which would churn its fixity hash for no
// reason).
class NormalizeThesisPdfs extends Command
{
    protected $signature = 'theses:normalize-pdfs
                            {--dry-run : Report what would change without touching any file}
                            {--with-trashed : Also repair soft-deleted theses (they are restorable, so a broken file there resurfaces on restore)}';

    protected $description = 'Rewrite already-stored thesis PDFs that FPDI cannot open into a readable structure (backfill).';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        if ($dryRun) {
            $this->info('DRY RUN — no files will be modified.');
        }

        $readable = 0; $fixed = 0; $failed = 0; $missing = 0;

        $query = $this->option('with-trashed') ? Thesis::withTrashed() : Thesis::query();

        $query->chunkById(100, function ($theses) use (&$readable, &$fixed, &$failed, &$missing, $dryRun) {
            foreach ($theses as $thesis) {
                if (! $thesis->file_path || ! Storage::disk('local')->exists($thesis->file_path)) {
                    $missing++;
                    $this->warn("MISSING  #{$thesis->id}  {$thesis->title}");
                    continue;
                }

                $path = Storage::disk('local')->path($thesis->file_path);

                if ($this->isFpdiReadable($path)) {
                    $readable++;
                    continue;
                }

                if ($dryRun) {
                    $this->line("WOULD FIX  #{$thesis->id}  {$thesis->title}");
                    $fixed++;
                    continue;
                }

                if (! PdfNormalizer::normalize($path)) {
                    $failed++;
                    $this->error("FAILED   #{$thesis->id}  {$thesis->title}  (is qpdf installed?)");
                    continue;
                }

                // Re-probe rather than trusting the rewrite: a file qpdf
                // happily rewrites can still be one FPDI won't take, and
                // reporting it as fixed would send someone looking in the
                // wrong place when the thesis still won't open.
                if (! $this->isFpdiReadable($path)) {
                    $failed++;
                    $this->error("STILL UNREADABLE  #{$thesis->id}  {$thesis->title}");
                    continue;
                }

                // Normalizing changes the file's bytes, so the SHA-256 recorded
                // at upload no longer describes what is on disk. Without this,
                // the weekly theses:verify-checksums would report every single
                // repaired thesis as a fixity MISMATCH — i.e. as tampering.
                // updateQuietly: no model events → no Scout/Meilisearch call
                // (nothing searchable changed anyway).
                $thesis->updateQuietly(['checksum' => hash_file('sha256', $path)]);

                $fixed++;
                $this->info("FIXED    #{$thesis->id}  {$thesis->title}");
            }
        });

        $verb = $dryRun ? 'would be repaired' : 'repaired';
        $this->info("Done — already readable: {$readable}, {$verb}: {$fixed}, failed: {$failed}, file missing: {$missing}");

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    // Shares WatermarkService's own check rather than keeping a second copy —
    // "can this be viewed" must mean exactly the same thing here as it does on
    // the upload path and at read time.
    private function isFpdiReadable(string $path): bool
    {
        return (new WatermarkService())->canStamp($path);
    }
}
