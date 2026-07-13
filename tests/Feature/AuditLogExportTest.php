<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AuditLogExportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['student', 'staff', 'super_admin'] as $role) {
            Role::findOrCreate($role);
        }
        Permission::findOrCreate('export_reports');
    }

    /**
     * The export route sits behind role:staff|super_admin *and*
     * permission:export_reports — the permission alone won't pass the role gate.
     */
    private function exporter(): User
    {
        $user = User::factory()->create(['role' => 'staff', 'name' => 'Admin']);
        $user->assignRole('staff');
        $user->givePermissionTo('export_reports');

        return $user;
    }

    private function log(array $attributes = []): AuditLog
    {
        return AuditLog::create(array_merge([
            'user_id'     => null,
            'action'      => 'login',
            'description' => 'Someone logged in',
            'ip_address'  => '127.0.0.1',
            'created_at'  => now(),
        ], $attributes));
    }

    private function export(User $user, array $params = []): string
    {
        $response = $this->actingAs($user, 'sanctum')->get('/api/audit-logs/export?' . http_build_query(array_merge([
            'period'    => 'custom',
            'date_from' => '2000-01-01',
            'date_to'   => '2100-01-01',
        ], $params)));

        $response->assertOk();
        $response->assertHeader('content-type', 'text/csv; charset=UTF-8');

        return $response->streamedContent();
    }

    /**
     * The audit log is the one UNBOUNDED export in the system — it grows with
     * every login, view and search. It used to be handed whole to DomPDF, which
     * buffers the entire rendered document in memory. Measured on 20k rows:
     * hydrating them all costs ~47 MB before DomPDF even starts; streaming costs
     * ~0.1 MB. It is also the wrong kind of artifact for a PDF — an audit trail
     * is filtered, sorted and pivoted, which is spreadsheet work.
     */
    public function test_the_audit_log_exports_as_csv_not_pdf(): void
    {
        $this->log();

        $csv = $this->export($this->exporter());

        $this->assertStringNotContainsString('%PDF', $csv);
        $this->assertStringContainsString('ID,Timestamp,User,Role,Action,Description', $csv);

        // The BOM matters: without it Excel reads the file in the local ANSI
        // codepage and mangles every non-ASCII name and search term.
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
    }

    public function test_the_export_requires_the_export_reports_permission(): void
    {
        // getJson, not get: on an API-only app an unauthenticated plain GET
        // tries to redirect to a `login` route that does not exist, and 500s
        // instead of returning the 401 we are actually asserting.
        $this->getJson('/api/audit-logs/export?period=day')->assertStatus(401);

        // Staff *without* the individually-grantable permission are refused,
        // even though they may read the log in the UI.
        $staff = User::factory()->create(['role' => 'staff']);
        $staff->assignRole('staff');

        $this->actingAs($staff, 'sanctum')
            ->getJson('/api/audit-logs/export?period=day')
            ->assertStatus(403);
    }

    /**
     * Regression. Excel, LibreOffice and Sheets execute any cell beginning with
     * `=`, `+`, `-` or `@` as a formula. Account names reach this export
     * verbatim, so registering as `=cmd|'/c calc'!A0` would plant a live formula
     * in the audit CSV that fires when an administrator opens it — the attacker
     * never touches the admin's machine, they just wait for the export.
     */
    public function test_a_formula_in_a_user_name_cannot_execute_in_the_csv(): void
    {
        $attacker = User::factory()->create(['name' => "=cmd|'/c calc'!A0", 'role' => 'student']);
        $attacker->assignRole('student');

        $this->log([
            'user_id'     => $attacker->id,
            'description' => $attacker->name . ' logged in',
        ]);

        $csv = $this->export($this->exporter());

        // The payload is still recorded — an audit log must not quietly rewrite
        // history — but it can no longer begin a cell, so it is inert text.
        $this->assertStringContainsString("'=cmd|'/c calc'!A0", $csv);
        $this->assertStringNotContainsString(',"=cmd', $csv);
        $this->assertStringNotContainsString(',=cmd', $csv);
    }

    /**
     * Two genuinely distinct entries can share every other column — the same
     * person searching the same term twice within one second produces
     * byte-identical rows. The primary key is what keeps the records
     * distinguishable, both for citing one and for de-duplicating the file.
     */
    public function test_rows_stay_distinguishable_when_every_other_column_matches(): void
    {
        $user = $this->exporter();
        $at   = now();

        $a = $this->log(['user_id' => $user->id, 'action' => 'search', 'description' => 'Admin searched for: x', 'created_at' => $at]);
        $b = $this->log(['user_id' => $user->id, 'action' => 'search', 'description' => 'Admin searched for: x', 'created_at' => $at]);

        $csv = $this->export($user);

        $this->assertNotSame($a->id, $b->id);
        $this->assertStringContainsString("{$a->id},", $csv);
        $this->assertStringContainsString("{$b->id},", $csv);
    }

    public function test_the_export_honours_the_action_filter(): void
    {
        $user = $this->exporter();

        $this->log(['user_id' => $user->id, 'action' => 'search', 'description' => 'Admin searched for: findme']);
        $this->log(['user_id' => $user->id, 'action' => 'login',  'description' => 'Admin logged in NOTME']);

        $csv = $this->export($user, ['action' => 'search']);

        $this->assertStringContainsString('findme', $csv);
        $this->assertStringNotContainsString('NOTME', $csv);
    }
}
