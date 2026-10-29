<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// The school's list of everyone allowed to use TIPIGANAN, imported from a CSV
// exported by the school's own database (see App\Support\SchoolRoster).
//
// With SCHOOL_DIRECTORY_DRIVER=csv:
//   - registration only accepts an ID number on this list, and the person's
//     starting password is derived from the first name stored here;
//   - signing in, and every signed-in request, requires the account's ID
//     number to be on this list - for every role, staff and Super Admin too.
// An empty list enforces nothing, so switching the driver on before the first
// import cannot lock everybody out.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('school_roster', function (Blueprint $table) {
            // Text, never a number: an ID like 00042 must keep its leading zeros.
            $table->char('id_number', 5)->primary();
            $table->string('first_name', 100);
            $table->string('last_name', 100)->nullable();
            $table->enum('role', ['student', 'teacher', 'staff', 'super_admin']);
            $table->timestamp('imported_at')->nullable();
        });

        // The account still has the starting password it was registered with
        // (first three letters of the first name, "_", the ID number). Shown
        // as a reminder to change it; cleared when the password is changed.
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('must_change_password')->default(false)->after('password');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('must_change_password');
        });
        Schema::dropIfExists('school_roster');
    }
};
