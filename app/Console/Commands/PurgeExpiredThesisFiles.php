<?php

namespace App\Console\Commands;

use App\Support\ThesisFilePurger;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

// For a real deployment, point an OS-level cron at this (e.g. daily via
// `php artisan schedule:run` on a schedule defined in routes/console.php,
// or directly in crontab). Locally, ThesisFilePurger::sweepIfDue() also runs
// opportunistically from staff file-management endpoints, so nothing is
// strictly relying on this command actually being scheduled anywhere.
#[Signature('theses:purge-expired-files')]
#[Description('Permanently delete superseded thesis PDFs past their restore grace period')]
class PurgeExpiredThesisFiles extends Command
{
    /**
     * Execute the console command.
     */
    public function handle()
    {
        $count = ThesisFilePurger::sweep();

        $this->info("Purged {$count} expired thesis file version(s).");
    }
}
