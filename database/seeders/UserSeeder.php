<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

// Dummy accounts for development and testing.
//
// Accounts log in with a 5-digit school ID number, not an email — that ID is
// the key the school's API will later use to fetch the person's real details.
// Names are left NULL on purpose here: it keeps these accounts honest about
// what a real registration now produces, so anywhere that assumed a name
// exists shows up during testing rather than after the API is connected.
// (Anything that displays a name falls back to the ID — see
// User::getDisplayNameAttribute.)
class UserSeeder extends Seeder
{
    /**
     * Log in with the ID number; the password for all of them is "password".
     *
     * The IDs sit in a 9xxxx block, deliberately clear of the 10001+ range the
     * migration used to backfill real accounts. An earlier version of this
     * seeder matched on id_number and picked 10005/10006, which the migration
     * had already handed to two REAL accounts — so seeding overwrote a live
     * user's email and flipped their role. Reserving a separate block means a
     * seeded account can never land on somebody's real one.
     */
    private const ACCOUNTS = [
        ['id_number' => '90001', 'email' => 'superadmin@tipiganan.com', 'role' => 'super_admin'],
        ['id_number' => '90002', 'email' => 'staff@tipiganan.com',      'role' => 'staff'],
        ['id_number' => '90003', 'email' => 'student@tipiganan.com',    'role' => 'student'],
        ['id_number' => '90004', 'email' => 'teacher@tipiganan.com',    'role' => 'teacher'],
        // A second student and teacher, so account-to-account behaviour (one
        // account not seeing another's bookmarks, history, or lockouts) can be
        // checked without inventing accounts by hand each time.
        ['id_number' => '90005', 'email' => 'student2@tipiganan.com',   'role' => 'student'],
        ['id_number' => '90006', 'email' => 'teacher2@tipiganan.com',   'role' => 'teacher'],
    ];

    public function run(): void
    {
        foreach (self::ACCOUNTS as $account) {
            // Matched on EMAIL, not id_number. These four have been identified
            // by their address since before ID numbers existed, so it is the
            // one value guaranteed to point at the same account across a
            // re-seed — and matching on it cannot collide with an unrelated
            // user who merely happens to hold that ID.
            $user = User::updateOrCreate(
                ['email' => $account['email']],
                [
                    'id_number' => $account['id_number'],
                    'password'  => Hash::make('password'),
                    'role'      => $account['role'],
                    'status'    => 'active',
                ]
            );

            $user->syncRoles([$account['role']]);
        }
    }
}
