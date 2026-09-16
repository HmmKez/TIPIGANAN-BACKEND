<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Records what the school's own system last said about each account holder, so
// that login can enforce it WITHOUT calling the school on every sign-in. Making
// their system part of the login path would mean their downtime becomes ours,
// for an answer that changes at most once a semester.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // What the school last reported: enrolled, graduated, resigned, and
            // so on. Null means never checked - which is every account created
            // before verification was switched on, and is deliberately NOT
            // treated as a reason to refuse a login.
            $table->string('directory_status', 32)->nullable()->after('id_number');

            $table->timestamp('directory_checked_at')->nullable()->after('directory_status');

            // The school's permanent identifier for the person, when they can
            // supply one. This is what makes it possible to tell a returning
            // student from a stranger who was later issued the same ID number.
            // See Known Issue #24.
            $table->string('directory_person_key', 64)->nullable()->after('directory_checked_at');

            // Accounts the school's roster will never contain, and which must
            // therefore never be revoked by a roster comparison: library staff
            // hired outside the student/faculty system, and the seeded demo
            // accounts in the reserved 9xxxx block.
            $table->boolean('directory_exempt')->default(false)->after('directory_person_key');
        });

        Schema::table('users', function (Blueprint $table) {
            // Both columns are read by the nightly reconciliation, which scans
            // for accounts to re-check or revoke rather than fetching one row.
            $table->index('directory_status');
            $table->index('directory_person_key');
        });

        // Existing accounts predate verification. Exempt the reserved demo block
        // and everyone with library duties, so switching verification on cannot
        // lock the administrators out of their own system.
        DB::table('users')
            ->where('id_number', 'like', '9%')
            ->orWhereIn('role', ['staff', 'super_admin'])
            ->update(['directory_exempt' => true]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['directory_status']);
            $table->dropIndex(['directory_person_key']);
            $table->dropColumn([
                'directory_status',
                'directory_checked_at',
                'directory_person_key',
                'directory_exempt',
            ]);
        });
    }
};
