<?php

namespace App\Console\Commands;

use App\Models\Thesis;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

// Fixity check: recompute each thesis PDF's SHA-256 and compare it to the hash
// recorded at upload. A mismatch means the stored file changed since upload
// (silent disk corruption or tampering) — the whole point of fixity in a
// single-copy archive. Rows with no stored hash yet (uploaded before this
// feature) are backfilled. Scheduled weekly; mismatches are logged so a
// scheduled run surfaces problems even with no console attached.
class VerifyThesisChecksums extends Command
{
    protected $signature = 'theses:verify-checksums';

    protected $description = 'Verify stored PDFs against their recorded SHA-256 fixity hashes (backfills missing ones).';

    public function handle(): int
    {
        $ok = 0; $mismatch = 0; $missing = 0; $backfilled = 0;

        Thesis::query()->chunkById(100, function ($theses) use (&$ok, &$mismatch, &$missing, &$backfilled) {
            foreach ($theses as $thesis) {
                if (! $thesis->file_path || ! Storage::disk('local')->exists($thesis->file_path)) {
                    $missing++;
                    Log::warning('Fixity: file missing for thesis', ['thesis_id' => $thesis->id, 'path' => $thesis->file_path]);
                    $this->warn("MISSING  #{$thesis->id}  {$thesis->title}");
                    continue;
                }

                $hash = hash_file('sha256', Storage::disk('local')->path($thesis->file_path));

                if (blank($thesis->checksum)) {
                    // updateQuietly: no model events → no Scout/Meilisearch call.
                    $thesis->updateQuietly(['checksum' => $hash]);
                    $backfilled++;
                    continue;
                }

                if (hash_equals($thesis->checksum, $hash)) {
                    $ok++;
                } else {
                    $mismatch++;
                    Log::warning('Fixity: checksum MISMATCH for thesis', [
                        'thesis_id' => $thesis->id,
                        'expected'  => $thesis->checksum,
                        'actual'    => $hash,
                    ]);
                    $this->error("MISMATCH #{$thesis->id}  {$thesis->title}");
                }
            }
        });

        $this->info("Fixity check complete — verified: {$ok}, backfilled: {$backfilled}, MISMATCH: {$mismatch}, MISSING: {$missing}");

        // Non-zero exit if anything is wrong, so a scheduler/monitor can alert.
        return ($mismatch === 0 && $missing === 0) ? self::SUCCESS : self::FAILURE;
    }
}
