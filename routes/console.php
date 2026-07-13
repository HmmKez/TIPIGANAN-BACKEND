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

// Retention: archive old usage-analytics entries (thesis views, searches) to CSV
// and delete them, so audit_logs — the one table that grows without bound —
// stays a size the Audit Logs page and its reports can actually query. Security
// and administrative entries are never touched, and nothing is deleted unless
// the CSV archive was written and read back successfully first. Monthly: the
// table grows over months, not hours, and each run is a big write.
Schedule::command('audit:prune --force')->monthlyOn(1, '03:00');
