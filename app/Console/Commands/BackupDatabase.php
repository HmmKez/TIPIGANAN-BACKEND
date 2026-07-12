<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;

// Dumps the MySQL database to storage/app/backups, timestamped and rotated.
// Scheduled daily (see routes/console.php). RAID/NAS redundancy is NOT a
// backup — it doesn't protect against accidental deletion or corruption — so
// this exists to preserve the metadata/users/citations/audit trail that make
// the stored PDFs meaningful. In production, point the backups directory (or a
// copy step) at somewhere OFF the primary server (see deploy checklist §7).
class BackupDatabase extends Command
{
    protected $signature = 'backup:database {--keep=7 : How many recent backups to retain}';

    protected $description = 'Dump the MySQL database to storage/app/backups (timestamped, rotated).';

    public function handle(): int
    {
        $db = config('database.connections.mysql');

        if (($db['driver'] ?? null) !== 'mysql') {
            $this->error('backup:database only supports the mysql connection.');
            return self::FAILURE;
        }

        // Create the directory at the exact path mysqldump writes to (don't go
        // through the Storage disk — its root is storage/app/private in
        // Laravel 11+, which wouldn't match this raw path).
        $dir = storage_path('app' . DIRECTORY_SEPARATOR . 'backups');
        if (! is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $stamp   = now()->format('Y-m-d_His');
        $outPath = $dir . DIRECTORY_SEPARATOR . "tipiganan-{$stamp}.sql";

        // Credentials go in a temp options file, never on the command line
        // (argv is visible in the process list to other users on the box).
        $cnf = tempnam(sys_get_temp_dir(), 'dbdump');
        file_put_contents($cnf,
            "[client]\n" .
            "host=\"{$db['host']}\"\n" .
            "port=\"{$db['port']}\"\n" .
            "user=\"{$db['username']}\"\n" .
            "password=\"{$db['password']}\"\n"
        );

        try {
            $result = Process::timeout(600)->run([
                config('database.dump.binary', 'mysqldump'),
                "--defaults-extra-file={$cnf}",
                '--single-transaction', // consistent InnoDB snapshot without locking
                '--no-tablespaces',     // avoids needing the PROCESS privilege
                "--result-file={$outPath}",
                $db['database'],
            ]);
        } finally {
            @unlink($cnf);
        }

        if (! $result->successful()) {
            @unlink($outPath);
            $this->error('Backup failed: ' . trim($result->errorOutput() ?: $result->output() ?: 'unknown error'));
            $this->line('If mysqldump is not on PATH, set DB_DUMP_BINARY to its full path.');
            return self::FAILURE;
        }

        $this->info('Backup written: ' . $outPath . ' (' . number_format((int) @filesize($outPath)) . ' bytes)');
        $this->rotate($dir, (int) $this->option('keep'));

        return self::SUCCESS;
    }

    // Keep only the N most-recent dumps (timestamped names sort chronologically).
    private function rotate(string $dir, int $keep): void
    {
        $files = glob($dir . DIRECTORY_SEPARATOR . 'tipiganan-*.sql') ?: [];
        rsort($files);
        foreach (array_slice($files, max($keep, 1)) as $old) {
            @unlink($old);
            $this->line('Rotated out: ' . basename($old));
        }
    }
}
