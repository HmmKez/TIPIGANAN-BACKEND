<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Importing a new school list switches off every account no longer on it.
// This flag records that the import did it, not a staff member - so a later
// list that has the person again switches the account back on, while an
// account staff deactivated by hand stays off.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('deactivated_by_roster')->default(false)->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('deactivated_by_roster');
        });
    }
};
