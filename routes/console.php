<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Scheduled tasks. These only fire when the OS runs `php artisan schedule:run`
// every minute (one cron entry — see the deploy checklist in PROJECT-STATUS.md
// §7). Until that cron exists, thesis-file cleanup still happens via the
// opportunistic sweep (ThesisFilePurger::sweepIfDue), but backups do NOT run
// without the scheduler — so wiring the cron is part of going live.
Schedule::command('theses:purge-expired-files')->daily();
Schedule::command('backup:database')->dailyAt('02:00');
// Fixity: re-verify stored PDFs against their recorded hashes weekly.
Schedule::command('theses:verify-checksums')->weekly();
