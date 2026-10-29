<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

// Accounts are no longer self-registered. A student or teacher goes to the
// library, staff look their ID number up on the school's list and confirm the
// name against their school ID card, and the person types their own email and
// password on the staff computer (AccountActivationController).
//
//   users.needs_activation  An account that exists but must be activated at the
//                           library before it can sign in again (accounts made
//                           under the old self-registration). Activation sets a
//                           new email and password and keeps everything else.
//   activate_accounts       The permission behind the Activate an Account panel;
//                           every staff member has it by default.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('needs_activation')->default(false)->after('status');
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $permission = Permission::firstOrCreate(['name' => 'activate_accounts', 'guard_name' => 'web']);
        foreach (['staff', 'super_admin'] as $role) {
            if ($r = Role::where('name', $role)->first()) {
                $r->givePermissionTo($permission);
            }
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('needs_activation');
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Permission::where('name', 'activate_accounts')->delete();
    }
};
