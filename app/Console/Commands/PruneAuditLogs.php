<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Support\AuditCsv;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class PruneAuditLogs extends Command
{
    protected $signature = 'audit:prune
                            {--days= : Override the retention window (config: audit.prune.days)}
                            {--dry-run : Report what would be archived and deleted, and change nothing}
                            {--force : Skip the confirmation prompt (used by the scheduler)}';

    protected $description = 'Archive old usage-analytics audit entries to CSV, then delete them';

    public function handle(): int
    {
        $days    = (int) ($this->option('days') ?: config('audit.prune.days'));
        $actions = config('audit.prune.actions', []);
        $dryRun  = (bool) $this->option('dry-run');

        if ($days < 1 || empty($actions)) {
            $this->error('Retention is not configured (audit.prune.days / audit.prune.actions).');

            return self::FAILURE;
        }

        $cutoff = now()->subDays($days)->startOfDay();

        // Only ever touches the actions marked as pure usage analytics.
        // Security and administrative entries are never pruned automatically.
        $query = AuditLog::query()
            ->whereIn('action', $actions)
            ->where('created_at', '<', $cutoff);

        $count = (clone $query)->count();

        $this->line("Retention:  {$days} days  (cutoff " . $cutoff->toDateTimeString() . ')');
        $this->line('Actions:    ' . implode(', ', $actions));
        $this->line('Candidates: ' . number_format($count));

        if ($count === 0) {
            $this->info('Nothing to prune.');

            return self::SUCCESS;
        }

        if ($dryRun) {
            $this->comment('Dry run — nothing was archived or deleted.');

            return self::SUCCESS;
        }

        if (! $this->option('force') && ! $this->confirm("Archive and delete {$count} entries?", true)) {
            $this->comment('Aborted.');

            return self::SUCCESS;
        }

        // Pin the set to rows that exist RIGHT NOW. New audit entries are written
        // with created_at = now(), so none can fall behind a cutoff that is
        // already in the past — but pinning the max id makes "what was archived"
        // and "what gets deleted" provably the same set rather than merely
        // probably the same one.
        $maxId = (clone $query)->max('id');
        $query->where('id', '<=', $maxId);

        [$path, $written] = $this->archive(clone $query, $cutoff);

        // The archive is the ONLY remaining copy of these rows once they are
        // gone. Verify it landed on disk, and that it contains exactly what we
        // are about to destroy, BEFORE destroying anything.
        if (! $this->verify($path, $written, $count)) {
            $this->error('Archive verification FAILED — nothing was deleted. The archive is at: ' . $path);

            return self::FAILURE;
        }

        $this->info("Archived {$written} entries to {$path}");

        $deleted = 0;
        while (($chunk = (clone $query)->limit(1000)->delete()) > 0) {
            $deleted += $chunk;
        }

        // Pruning the audit trail is itself an auditable act — and this entry is
        // a security action, so it is never pruned by a later run.
        AuditLog::create([
            'user_id'     => null,
            'action'      => 'prune_audit_logs',
            'target_type' => null,
            'target_id'   => null,
            'description' => "Archived and deleted {$deleted} analytics entries older than {$days} days",
            'metadata'    => [
                'deleted'  => $deleted,
                'cutoff'   => $cutoff->toDateTimeString(),
                'actions'  => $actions,
                'archive'  => $path,
            ],
            'ip_address'  => null,
        ]);

        $this->info("Deleted {$deleted} entries. Audit log is now " . number_format(AuditLog::count()) . ' rows.');

        return self::SUCCESS;
    }

    /**
     * Streams the doomed rows into a CSV on the archive disk.
     *
     * php://temp spills to a real file past a few MB, so a million-row archive
     * never sits in memory; writeStream() then hands it to the disk without
     * buffering it again. Same AuditCsv writer the on-demand export uses, so an
     * archive and an export are byte-for-byte the same format — including the
     * formula-injection guard, which matters more here, not less: this file is
     * the last copy.
     */
    private function archive($query, $cutoff): array
    {
        $dir  = trim(config('audit.archive.path'), '/');
        $disk = config('audit.archive.disk');
        $path = $dir . '/audit-archive_upto-' . $cutoff->format('Y-m-d') . '_' . now()->format('Ymd-His') . '.csv';

        $handle = fopen('php://temp/maxmemory:8388608', 'w+');

        AuditCsv::writeHeader($handle);

        $written = 0;
        foreach ($query->with('user')->lazyById(1000) as $log) {
            AuditCsv::writeRow($handle, $log);
            $written++;
        }

        rewind($handle);
        Storage::disk($disk)->writeStream($path, $handle);
        fclose($handle);

        return [$path, $written];
    }

    /**
     * Reads the archive back OFF the disk and counts its rows. Trusting the
     * writer's own return value would miss the case this exists to catch: a disk
     * that accepted the write and silently kept nothing.
     */
    private function verify(string $path, int $written, int $expected): bool
    {
        $disk = config('audit.archive.disk');

        if ($written !== $expected) {
            $this->error("Wrote {$written} rows but expected {$expected}.");

            return false;
        }

        if (! Storage::disk($disk)->exists($path) || Storage::disk($disk)->size($path) === 0) {
            $this->error('Archive file is missing or empty on disk.');

            return false;
        }

        $stream = Storage::disk($disk)->readStream($path);
        $lines  = 0;

        while (fgetcsv($stream) !== false) {
            $lines++;
        }
        fclose($stream);

        $dataRows = $lines - 1; // minus the header

        if ($dataRows !== $expected) {
            $this->error("Archive on disk holds {$dataRows} rows but {$expected} were expected.");

            return false;
        }

        return true;
    }
}
