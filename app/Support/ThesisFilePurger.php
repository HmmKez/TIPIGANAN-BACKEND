<?php

namespace App\Support;

use App\Models\ThesisFileVersion;
use Illuminate\Support\Facades\Storage;

// Permanently deletes superseded thesis PDFs once their grace period
// (config('thesis.old_file_retention_days')) has passed. There's no reliable
// OS-level cron wired up for this project (local dev, no scheduler running),
// so rather than depend on one, `sweep()` is also triggered opportunistically
// from staff-facing file-management endpoints — `ran()` guards that so it
// only actually runs the delete query once an hour no matter how often it's
// called. A real deployment can additionally point a real cron at
// `php artisan theses:purge-expired-files`, which calls sweep() unconditionally.
class ThesisFilePurger
{
    private const LOCK_KEY = 'thesis-file-versions:purge-lock';
    private const LOCK_SECONDS = 3600;

    // SafeCache (not the raw Cache facade) so a Redis outage can't take down
    // whatever staff endpoint happened to trigger this — worst case with
    // Redis down is the sweep query running on every call instead of once an
    // hour, which is cheap enough to be a fine degraded mode.
    public static function sweepIfDue(): void
    {
        SafeCache::remember(self::LOCK_KEY, self::LOCK_SECONDS, function () {
            self::sweep();
            return true;
        });
    }

    public static function sweep(): int
    {
        $expired = ThesisFileVersion::where('status', 'pending')
            ->where('purge_after', '<=', now())
            ->get();

        foreach ($expired as $version) {
            Storage::disk('local')->delete($version->file_path);
            $version->update(['status' => 'purged', 'purged_at' => now()]);
        }

        return $expired->count();
    }
}
