<?php

namespace App\Console\Commands;

use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Regenerates the System Blueprint (database schema reference) from the LIVE
// database rather than from a hand-maintained document.
//
// The original blueprint was a standalone PDF with no source file, written in
// June. By August it described a schema that no longer existed: it still had
// categories.parent_id (subcategories were removed), a user_permissions pivot
// (the app uses Spatie's tables), no users.id_number, and none of the tables
// added since — settings, thesis_file_versions, thesis_reports,
// signed_url_tokens, bookmarks. Nobody could correct it, because there was
// nothing to edit but the PDF itself.
//
// Reading the schema straight from information_schema means the document
// cannot drift again: it is a report on the database, not a description of it.
class GenerateBlueprint extends Command
{
    protected $signature = 'docs:blueprint {--output= : Full path to write the PDF to}';

    protected $description = 'Generate the System Blueprint (live database schema + role capabilities) as a PDF';

    /**
     * Framework plumbing that carries no design intent. Listing them alongside
     * the domain tables buries the ~14 that a reader actually cares about.
     */
    private const INFRASTRUCTURE = [
        'cache', 'cache_locks', 'failed_jobs', 'jobs', 'job_batches', 'migrations',
        'password_reset_tokens', 'personal_access_tokens', 'sessions',
    ];

    /** Spatie's permission tables: real, but third-party rather than ours. */
    private const PERMISSION_TABLES = [
        'permissions', 'roles', 'model_has_permissions', 'model_has_roles', 'role_has_permissions',
    ];

    /** What each domain table is for, in one line a reader can use. */
    private const PURPOSE = [
        'users'                => 'Accounts for students, teachers, staff and super admins. Identified by a 5-digit school ID number.',
        'categories'           => 'The collections/departments a thesis can belong to. Flat — nested categories were removed.',
        'theses'               => 'The catalogued items themselves: metadata, the stored PDF path, and its fixity checksum.',
        'citations'            => 'One APA and one MLA citation per thesis, auto-generated and staff-editable.',
        'citation_logs'        => 'Records each time a reader copies a citation. Powers the Most Cited report.',
        'bookmarks'            => "A reader's saved items. Formerly named 'favorites'.",
        'reading_history'      => 'Which account opened which thesis and when. Powers view counts and Continue Reading.',
        'audit_logs'           => 'Append-only record of significant actions. Retained even when the acting account is deleted.',
        'thesis_reports'       => 'Reader-submitted reports about an item, and their resolution by staff.',
        'thesis_file_versions' => 'Superseded PDFs kept restorable for a grace period after a file replace.',
        'signed_url_tokens'    => 'Short-lived tokens backing the secure PDF viewer, so files are never served by a guessable URL.',
        'settings'             => 'Key/value store for values that must change at runtime: the active term, landing hero, featured collections.',
    ];

    public function handle(): int
    {
        $tables = collect(DB::select('SHOW TABLES'))
            ->map(fn ($row) => array_values((array) $row)[0])
            ->reject(fn ($t) => in_array($t, self::INFRASTRUCTURE, true))
            ->reject(fn ($t) => in_array($t, self::PERMISSION_TABLES, true))
            ->sort()
            ->values();

        $schema = $tables->map(fn ($table) => [
            'name'    => $table,
            'purpose' => self::PURPOSE[$table] ?? '',
            'rows'    => DB::table($table)->count(),
            'columns' => $this->columnsOf($table),
        ])->all();

        $pdf = Pdf::loadView('pdf.blueprint', [
            'generatedAt'      => now()->format('j F Y'),
            'schema'           => $schema,
            'permissionTables' => self::PERMISSION_TABLES,
            'infrastructure'   => self::INFRASTRUCTURE,
        ])->setPaper('a4', 'portrait');

        $output = $this->option('output')
            ?? base_path('..' . DIRECTORY_SEPARATOR . 'TIPIGANAN-System-Blueprint.pdf');

        file_put_contents($output, $pdf->output());

        $this->info("Blueprint generated from the live schema: {$output}");
        $this->line('  ' . count($schema) . ' domain tables documented.');

        return self::SUCCESS;
    }

    private function columnsOf(string $table): array
    {
        // Foreign keys are the part a developer most needs and the part a
        // hand-written document gets wrong first, so they are read from the
        // constraints rather than inferred from column names.
        $foreignKeys = collect(DB::select('
            SELECT k.COLUMN_NAME AS col, k.REFERENCED_TABLE_NAME AS ref_table,
                   k.REFERENCED_COLUMN_NAME AS ref_col, rc.DELETE_RULE AS on_delete
            FROM information_schema.KEY_COLUMN_USAGE k
            JOIN information_schema.REFERENTIAL_CONSTRAINTS rc
              ON rc.CONSTRAINT_NAME = k.CONSTRAINT_NAME
             AND rc.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA
            WHERE k.TABLE_SCHEMA = DATABASE() AND k.TABLE_NAME = ?
        ', [$table]))->keyBy('col');

        return collect(DB::select("SHOW FULL COLUMNS FROM `{$table}`"))->map(function ($c) use ($foreignKeys) {
            $notes = [];

            if ($c->Key === 'PRI')                    { $notes[] = 'primary key'; }
            if ($c->Key === 'UNI')                    { $notes[] = 'unique'; }
            if ($fk = $foreignKeys->get($c->Field))   { $notes[] = "→ {$fk->ref_table}.{$fk->ref_col} (on delete: " . strtolower($fk->on_delete) . ')'; }
            if ($c->Null === 'YES')                   { $notes[] = 'nullable'; }
            if ($c->Comment)                          { $notes[] = $c->Comment; }

            return [
                'field' => $c->Field,
                'type'  => $c->Type,
                'notes' => implode(', ', $notes),
            ];
        })->all();
    }
}
