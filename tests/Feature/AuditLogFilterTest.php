<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AuditLogFilterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate('staff');
        Permission::findOrCreate('reset_passwords');
        Permission::findOrCreate('export_reports');
    }

    private function viewer(): User
    {
        $user = User::factory()->create(['role' => 'staff']);
        $user->assignRole('staff');
        $user->givePermissionTo('reset_passwords'); // the gate for reading the log
        $user->givePermissionTo('export_reports');

        return $user;
    }

    private function log(string $at, string $description): AuditLog
    {
        return AuditLog::create([
            'user_id'     => null,
            'action'      => 'login',
            'description' => $description,
            'ip_address'  => '127.0.0.1',
            'created_at'  => $at,
        ]);
    }

    /**
     * Regression, and the reason DateRange exists.
     *
     * The filters used whereDate('created_at', '<=', $to), which wraps the column
     * in DATE() and cannot use an index — 193ms vs 25ms on 200k rows. But the
     * obvious replacement, `where('created_at', '<=', $to)`, compares against
     * MIDNIGHT STARTING that day and silently drops every entry logged during it.
     * The upper bound has to be exclusive-next-midnight.
     */
    public function test_an_entry_late_on_the_last_day_of_the_range_is_included(): void
    {
        $this->log('2026-07-13 23:58:00', 'LATE on the final day');
        $this->log('2026-07-14 00:02:00', 'JUST AFTER the range');

        $body = $this->actingAs($this->viewer(), 'sanctum')
            ->getJson('/api/audit-logs?date_from=2026-07-10&date_to=2026-07-13')
            ->assertOk()
            ->json();

        $descriptions = collect($body['data'])->pluck('description');

        $this->assertContains('LATE on the final day', $descriptions);
        $this->assertNotContains('JUST AFTER the range', $descriptions);
    }

    public function test_an_entry_at_the_first_instant_of_the_range_is_included(): void
    {
        $this->log('2026-07-10 00:00:00', 'FIRST instant');
        $this->log('2026-07-09 23:59:00', 'JUST BEFORE the range');

        $body = $this->actingAs($this->viewer(), 'sanctum')
            ->getJson('/api/audit-logs?date_from=2026-07-10&date_to=2026-07-13')
            ->assertOk()
            ->json();

        $descriptions = collect($body['data'])->pluck('description');

        $this->assertContains('FIRST instant', $descriptions);
        $this->assertNotContains('JUST BEFORE the range', $descriptions);
    }

    /**
     * The CSV export shares the same date handling, so it must agree with the
     * list the administrator was looking at when they clicked Export.
     */
    public function test_the_csv_export_uses_the_same_date_boundaries(): void
    {
        $this->log('2026-07-13 23:58:00', 'LATE on the final day');
        $this->log('2026-07-14 00:02:00', 'JUST AFTER the range');

        $csv = $this->actingAs($this->viewer(), 'sanctum')
            ->get('/api/audit-logs/export?period=custom&date_from=2026-07-10&date_to=2026-07-13')
            ->assertOk()
            ->streamedContent();

        $this->assertStringContainsString('LATE on the final day', $csv);
        $this->assertStringNotContainsString('JUST AFTER the range', $csv);
    }

    public function test_the_paginated_total_reflects_the_filter(): void
    {
        $this->log('2026-07-11 10:00:00', 'in range one');
        $this->log('2026-07-12 10:00:00', 'in range two');
        $this->log('2026-08-01 10:00:00', 'out of range');

        // The total is served from a short-lived cache rather than a COUNT(*) on
        // every request — it must still be the count for THIS filter, not a
        // stale one from a different set of filters.
        $body = $this->actingAs($this->viewer(), 'sanctum')
            ->getJson('/api/audit-logs?date_from=2026-07-10&date_to=2026-07-13')
            ->assertOk()
            ->json();

        $this->assertSame(2, $body['total']);
        $this->assertCount(2, $body['data']);
    }
}
