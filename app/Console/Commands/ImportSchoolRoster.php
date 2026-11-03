<?php

namespace App\Console\Commands;

use App\Support\SchoolRoster;
use Illuminate\Console\Command;

/**
 * Import the school's list from a CSV on the command line - for first setup,
 * before anyone can sign in to use the School Records page.
 *
 *   php artisan school:import-roster database/fixtures/school-roster-sample.csv
 */
class ImportSchoolRoster extends Command
{
    protected $signature = 'school:import-roster {file : Path to the CSV} {--force : Import even if the list is much smaller than the current one}';

    protected $description = 'Import the school\'s list of students, teachers and staff from a CSV (replaces the current list)';

    public function handle(): int
    {
        $path = $this->argument('file');
        if (! is_file($path)) {
            $this->error("File not found: {$path}");
            return self::FAILURE;
        }

        $parsed = SchoolRoster::parse((string) file_get_contents($path));
        if ($parsed['error']) {
            $this->error($parsed['error']);
            return self::FAILURE;
        }

        foreach ($parsed['rejected'] as $r) {
            $this->warn("  line {$r['line']}: {$r['reason']}");
        }

        $count = count($parsed['rows']);
        $previous = SchoolRoster::summary()['total'];
        if ($count === 0) {
            $this->error('No usable rows - nothing imported.');
            return self::FAILURE;
        }
        if ($previous > 0 && $count < $previous * 0.8 && ! $this->option('force')) {
            $this->error("Only {$count} people, against {$previous} on the current list. Check the file, or pass --force.");
            return self::FAILURE;
        }

        // No --force for this one: nobody could sign in to put it right.
        if (config('school.directory.driver') === 'csv' && ! SchoolRoster::keepsASuperAdmin($parsed['rows'])) {
            $this->error('Nothing imported: this list would leave no active Super Admin able to sign in. Check that their ID number and name are on it. On a new system, run php artisan accounts:create-super-admin first; that person can then import the list on the School Records page.');
            return self::FAILURE;
        }

        $sync = SchoolRoster::import($parsed['rows']);

        $byRole = array_count_values(array_column($parsed['rows'], 'role'));
        $this->info("Imported {$count} people (".collect($byRole)->map(fn ($n, $r) => "{$n} {$r}")->implode(', ').').'
            .($parsed['rejected'] ? ' '.count($parsed['rejected']).' rows rejected.' : ''));
        if ($sync['switched_off']) {
            $this->line('Switched off (not on the list): '.implode(', ', $sync['switched_off']));
        }
        if ($sync['switched_on']) {
            $this->line('Switched back on (on the list again): '.implode(', ', $sync['switched_on']));
        }
        if ($sync['needs_activation']) {
            $this->line('Must be activated again (the name changed): '.implode(', ', $sync['needs_activation']));
        }
        if ($sync['role_changed']) {
            $this->line('Role updated from the list: '.implode(', ', $sync['role_changed']));
        }
        if (config('school.directory.driver') !== 'csv') {
            $this->line('Note: SCHOOL_DIRECTORY_DRIVER is not "csv", so the list is stored but not enforced yet.');
        }

        return self::SUCCESS;
    }
}
