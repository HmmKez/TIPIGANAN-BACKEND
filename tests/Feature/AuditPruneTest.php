<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AuditPruneTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Config::set('audit.prune.actions', ['view_thesis', 'search']);
        Config::set('audit.prune.days', 365);
        Config::set('audit.archive.disk', 'local');
        Config::set('audit.archive.path', 'audit-archives');
    }

    private function log(string $action, int $daysAgo, string $description = 'entry'): AuditLog
    {
        return AuditLog::create([
            'user_id'     => null,
            'action'      => $action,
            'description' => $description,
            'ip_address'  => '127.0.0.1',
            'created_at'  => now()->subDays($daysAgo),
        ]);
    }

    private function archiveFile(): ?string
    {
        return collect(Storage::disk('local')->files('audit-archives'))->first();
    }

    /**
     * The whole point of the retention policy: the table's bulk is usage
     * analytics (views and searches), and those are what get pruned. Logins,
     * permission grants, deletions — the things an auditor actually asks for —
     * are never touched, no matter how old.
     */
    public function test_only_old_analytics_entries_are_pruned(): void
    {
        $oldView   = $this->log('view_thesis', 400);
        $oldSearch = $this->log('search', 400);
        $oldLogin  = $this->log('login', 400);              // security — must survive
        $oldGrant  = $this->log('grant_permission', 400);   // security — must survive
        $newView   = $this->log('view_thesis', 10);         // inside retention

        $this->artisan('audit:prune --force')->assertSuccessful();

        $this->assertDatabaseMissing('audit_logs', ['id' => $oldView->id]);
        $this->assertDatabaseMissing('audit_logs', ['id' => $oldSearch->id]);

        $this->assertDatabaseHas('audit_logs', ['id' => $oldLogin->id]);
        $this->assertDatabaseHas('audit_logs', ['id' => $oldGrant->id]);
        $this->assertDatabaseHas('audit_logs', ['id' => $newView->id]);
    }

    /**
     * Nothing is destroyed outright. The archive is the only remaining copy of a
     * pruned row, so it must contain every one of them.
     */
    public function test_pruned_entries_are_archived_to_csv_first(): void
    {
        foreach (range(1, 25) as $i) {
            $this->log('view_thesis', 400, "old view {$i}");
        }

        $this->artisan('audit:prune --force')->assertSuccessful();

        $file = $this->archiveFile();
        $this->assertNotNull($file, 'no archive was written');

        $csv = Storage::disk('local')->get($file);

        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);                 // Excel-safe
        $this->assertStringContainsString('ID,Timestamp,User,Role,Action', $csv);

        // Every deleted row is recoverable from the file.
        foreach (range(1, 25) as $i) {
            $this->assertStringContainsString("old view {$i}", $csv);
        }

        $this->assertSame(0, AuditLog::where('action', 'view_thesis')->count());
    }

    public function test_a_dry_run_changes_nothing(): void
    {
        $this->log('view_thesis', 400);

        $this->artisan('audit:prune --dry-run')->assertSuccessful();

        $this->assertSame(1, AuditLog::where('action', 'view_thesis')->count());
        $this->assertNull($this->archiveFile(), 'a dry run must not write an archive');
    }

    public function test_nothing_is_pruned_when_there_is_nothing_old_enough(): void
    {
        $this->log('view_thesis', 10);

        $this->artisan('audit:prune --force')->assertSuccessful();

        $this->assertSame(1, AuditLog::where('action', 'view_thesis')->count());
        $this->assertNull($this->archiveFile());
    }

    /**
     * Deleting an audit trail is itself an auditable act — and the entry
     * recording it is a security action, so a later run never prunes it.
     */
    public function test_the_prune_is_recorded_in_the_audit_log(): void
    {
        $this->log('view_thesis', 400);
        $this->log('search', 400);

        $this->artisan('audit:prune --force')->assertSuccessful();

        $entry = AuditLog::where('action', 'prune_audit_logs')->first();

        $this->assertNotNull($entry);
        $this->assertSame(2, $entry->metadata['deleted']);
        $this->assertNotEmpty($entry->metadata['archive']);
        $this->assertNotContains('prune_audit_logs', config('audit.prune.actions'));
    }

    /**
     * The CSV injection guard matters MORE in an archive than in an export: this
     * file is the last surviving copy of the row.
     */
    public function test_the_archive_neutralises_formula_injection(): void
    {
        $this->log('search', 400, "=cmd|'/c calc'!A0");

        $this->artisan('audit:prune --force')->assertSuccessful();

        $csv = Storage::disk('local')->get($this->archiveFile());

        $this->assertStringContainsString("'=cmd|'/c calc'!A0", $csv);
        $this->assertStringNotContainsString(',"=cmd', $csv);
    }
}
