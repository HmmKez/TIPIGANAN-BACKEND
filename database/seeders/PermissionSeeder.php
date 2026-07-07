<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class PermissionSeeder extends Seeder
{
    public function run(): void
    {
        // Reset cached roles and permissions
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        // Create permissions
        $permissions = [
            'delete_documents',
            'delete_accounts',
            'reset_passwords',
            'manage_categories',
            'upload_thesis',
            'edit_thesis',
            'archive_thesis',
            'download_thesis',
            'view_logs',
            'export_reports',
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission]);
        }

        // Create roles and assign permissions
        $superAdmin = Role::firstOrCreate(['name' => 'super_admin']);
        $staff      = Role::firstOrCreate(['name' => 'staff']);
        $student    = Role::firstOrCreate(['name' => 'student']);
        $teacher    = Role::firstOrCreate(['name' => 'teacher']);

        // Super admin gets everything
        $superAdmin->givePermissionTo(Permission::all());

        // Staff gets every permission except deleting — per the team guide,
        // "Delete user accounts" and "Delete documents" specifically require
        // a Super Admin grant; everything else, including viewing user
        // accounts and resetting passwords, is a baseline staff privilege.
        $staff->givePermissionTo([
            'reset_passwords',
            'upload_thesis',
            'edit_thesis',
            'archive_thesis',
            'download_thesis',
            'manage_categories',
            'view_logs',
            'export_reports',
        ]);

        // Students and teachers — read only, no special permissions
        $student->givePermissionTo([]);
        $teacher->givePermissionTo([]);

        // Cleanup: 'manage_users' was a short-lived permission from an
        // earlier iteration that never actually gated anything — replaced
        // by the more specific 'reset_passwords' above.
        if ($stale = Permission::where('name', 'manage_users')->first()) {
            $staff->revokePermissionTo($stale);
            $stale->delete();
        }
    }
}