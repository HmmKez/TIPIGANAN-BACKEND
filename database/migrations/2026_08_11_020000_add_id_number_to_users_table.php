<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Accounts are identified by the school's 5-digit student/teacher ID number.
// It becomes the login credential, and once the school's API is available it is
// the key used to look up the person's real name and details - which is why the
// name is no longer asked for at registration and becomes nullable here.
//
// Stored as CHAR(5), not an integer, on purpose: an ID like "00123" is a
// perfectly valid 5-digit number to a school office, and any numeric column
// would silently store it as 123 and lose the leading zeros.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->char('id_number', 5)->nullable()->after('email');
        });

        // Backfill every existing account before the unique index goes on, or
        // the index would fail against a table full of NULLs on some engines
        // and leave the column unusable. Ordered by id, starting at 10001, so
        // the four seeded accounts (ids 1-4: super admin, staff, student,
        // teacher) land on 10001-10004 predictably.
        $next = 10001;
        foreach (DB::table('users')->orderBy('id')->pluck('id') as $userId) {
            DB::table('users')->where('id', $userId)->update(['id_number' => (string) $next]);
            $next++;
        }

        Schema::table('users', function (Blueprint $table) {
            $table->unique('id_number');
        });

        // Name is no longer collected at registration; the school API supplies
        // it later. Anything that displays a name falls back to the ID number
        // in the meantime (see User::getDisplayNameAttribute).
        Schema::table('users', function (Blueprint $table) {
            $table->string('name')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Rows created without a name cannot be made NOT NULL again as-is, so
        // give them one before reversing - otherwise the rollback fails on
        // exactly the data this migration made possible.
        DB::table('users')->whereNull('name')->update(['name' => DB::raw('id_number')]);

        Schema::table('users', function (Blueprint $table) {
            $table->string('name')->nullable(false)->change();
            $table->dropUnique(['id_number']);
            $table->dropColumn('id_number');
        });
    }
};
