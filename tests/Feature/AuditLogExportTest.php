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

    public function test_users_without_export_permission_cannot_download_the_audit_log_pdf(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user, 'sanctum')
            ->get('/api/audit-logs/export?period=day');

        $response->assertStatus(403);
    }

    public function test_users_with_export_permission_can_download_the_audit_log_pdf_with_filters(): void
    {
        Permission::findOrCreate('export_reports');
        Role::findOrCreate('staff');

        // The export route sits behind role:staff|super_admin *and*
        // permission:export_reports — granting only the permission isn't
        // enough to pass the outer role gate.
        $user = User::factory()->create(['role' => 'staff']);
        $user->assignRole('staff');
        $user->givePermissionTo('export_reports');

        AuditLog::create([
            'user_id' => $user->id,
            'action' => 'login',
            'target_type' => 'user',
            'target_id' => $user->id,
            'description' => 'User logged in',
            'ip_address' => '127.0.0.1',
            'created_at' => now()->subDays(2),
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->get('/api/audit-logs/export?period=custom&date_from=' . now()->subDays(3)->toDateString() . '&date_to=' . now()->toDateString());

        $response->assertOk()
            ->assertHeader('content-type', 'application/pdf');

        $this->assertStringStartsWith('%PDF', $response->getContent());
    }
}
