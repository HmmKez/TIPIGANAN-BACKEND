<?php

namespace App\Console\Commands;

use App\Models\Category;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class RestoreCategoryCovers extends Command
{
    protected $signature = 'categories:restore-covers
                            {--dry-run : Show what would change, and change nothing}';

    protected $description = 'Restore each department category\'s official MDC seal as its cover image';

    /**
     * Category CODE → the seal shipped in database/seeders/assets/category-covers.
     *
     * Keyed on the code, not the name: the names have already been rewritten once
     * (CAST → "College of Arts, Sciences, and Technology") and will be again. The
     * code is the stable identifier — that is the entire point of having one.
     *
     * Mapped by looking at each image, not by trusting its filename. Two traps
     * that catches: `education.jpg` is the College of EDUCATION seal, so it
     * belongs to COE and not to Graduate Studies (the old frontend fallback map
     * had exactly that wrong, and GS has been showing COE's logo); and
     * business/hospitality are a gold and a green seal of the same college, which
     * are trivially easy to swap.
     *
     * GS, SC, FR, IP and SBC are deliberately absent — no dedicated artwork
     * exists for them. The only other images available (department-studies.png,
     * faculty.png) are the generic MDC seal, and putting a generic seal on one
     * collection but not another looks like a bug rather than a decision. They
     * keep the neutral brand panel until someone uploads real artwork.
     */
    private const COVERS = [
        'CAST'   => 'cast.jpg',        // College of Arts, Sciences, and Technology
        'COE'    => 'education.jpg',   // College of Education
        'CCJ'    => 'ccj.jpg',         // College of Criminal Justice
        'CON'    => 'nursing.jpg',     // College of Nursing
        'CABM-B' => 'business.jpg',    // CABM — Business Department (gold)
        'CABM-H' => 'hospitality.jpg', // CABM — Hospitality Department (green)
    ];

    public function handle(): int
    {
        $dryRun    = (bool) $this->option('dry-run');
        $assetDir  = database_path('seeders/assets/category-covers');
        $restored  = 0;

        foreach (self::COVERS as $code => $file) {
            $category = Category::where('code', $code)->first();

            if (! $category) {
                $this->warn("  {$code}: no such category — skipped.");
                continue;
            }

            $source = $assetDir . '/' . $file;

            if (! is_file($source)) {
                $this->error("  {$code}: missing asset {$file}");
                continue;
            }

            // A stable destination name, so re-running overwrites the same file
            // instead of piling up a new randomly-named copy every time.
            $target   = 'category-covers/default-' . strtolower(str_replace('-', '', $code)) . '.' . pathinfo($file, PATHINFO_EXTENSION);
            $previous = $category->cover_image_path;

            $this->line(sprintf('  %-7s %-45s %s', $code, $category->name, $previous ? "replacing {$previous}" : 'setting cover'));

            if ($dryRun) {
                continue;
            }

            Storage::disk('public')->put($target, file_get_contents($source));

            // Don't orphan the file we're replacing — but never delete one of our
            // own defaults, which would delete the file we just wrote.
            if ($previous && $previous !== $target && ! str_starts_with(basename($previous), 'default-')) {
                Storage::disk('public')->delete($previous);
            }

            $category->update(['cover_image_path' => $target]);
            $restored++;
        }

        if ($dryRun) {
            $this->comment('Dry run — nothing was changed.');

            return self::SUCCESS;
        }

        $this->info("Restored {$restored} category covers.");
        $this->comment('These stay editable — uploading a new cover in Category Management replaces them.');

        return self::SUCCESS;
    }
}
