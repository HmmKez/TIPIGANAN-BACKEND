<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The school's list of everyone allowed to use TIPIGANAN, imported from a CSV.
 *
 * Expected columns (header row required, any order, case and spacing ignored):
 *   id_number   5 digits, kept as text so 00042 stays 00042
 *   first_name  shown to staff to confirm the person at activation
 *   last_name   optional
 *   role        student | teacher | staff | super_admin
 *               ("faculty" counts as teacher; "librarian" as staff;
 *                "admin" / "super admin" as super_admin)
 *
 * Nothing here talks to the school: a person or the import screen hands it a
 * file, and the parsed rows are stored in the school_roster table.
 */
class SchoolRoster
{
    public const ROLES = ['student', 'teacher', 'staff', 'super_admin'];

    /** Column-name spellings accepted for each field. */
    private const HEADERS = [
        'id_number'  => ['id_number', 'id number', 'idnumber', 'id', 'id no', 'id no.', 'student id', 'school id'],
        'first_name' => ['first_name', 'first name', 'firstname', 'given name', 'given_name'],
        'last_name'  => ['last_name', 'last name', 'lastname', 'surname', 'family name'],
        'role'       => ['role', 'type', 'position', 'account type'],
    ];

    private const ROLE_ALIASES = [
        'student' => 'student', 'students' => 'student',
        'teacher' => 'teacher', 'teachers' => 'teacher', 'faculty' => 'teacher', 'instructor' => 'teacher',
        'staff' => 'staff', 'librarian' => 'staff', 'library staff' => 'staff', 'repository staff' => 'staff',
        'super_admin' => 'super_admin', 'super admin' => 'super_admin', 'superadmin' => 'super_admin', 'admin' => 'super_admin',
    ];

    /**
     * Read a CSV into roster rows. Never throws for a bad row: each one comes
     * back in `rejected` with its line number and the reason, so the person
     * importing can fix the file.
     *
     * @return array{rows: array<int, array>, rejected: array<int, array>, error: ?string}
     */
    public static function parse(string $contents): array
    {
        // A byte-order mark (Excel's "CSV UTF-8") would otherwise become part of
        // the first column's name.
        $contents = preg_replace('/^\xEF\xBB\xBF/', '', $contents);
        $lines = preg_split('/\r\n|\r|\n/', $contents);

        $header = null;
        $lineNo = 0;
        $rows = [];
        $rejected = [];
        $seen = [];

        foreach ($lines as $line) {
            $lineNo++;
            if (trim($line) === '') {
                continue;
            }
            $cells = array_map('trim', str_getcsv($line));

            if ($header === null) {
                $header = self::mapHeader($cells);
                if (! isset($header['id_number'], $header['first_name'], $header['role'])) {
                    return ['rows' => [], 'rejected' => [], 'error' =>
                        'The first row must name the columns, including id_number, first_name and role (last_name is optional).'];
                }
                continue;
            }

            $id = $cells[$header['id_number']] ?? '';
            $first = $cells[$header['first_name']] ?? '';
            $last = isset($header['last_name']) ? ($cells[$header['last_name']] ?? '') : '';
            $roleRaw = Str::lower(trim($cells[$header['role']] ?? ''));
            $role = self::ROLE_ALIASES[$roleRaw] ?? null;

            $reason = match (true) {
                ! preg_match('/^\d{5}$/', $id) => preg_match('/^\d{1,4}$/', $id)
                    ? "ID number \"{$id}\" has fewer than 5 digits - a leading zero was probably lost (Excel does this; format the column as Text)."
                    : "ID number \"{$id}\" is not 5 digits.",
                isset($seen[$id]) => "ID number {$id} is listed twice (first on line {$seen[$id]}).",
                ! preg_match('/\pL/u', $first) => 'The first name is missing or has no letters, so staff could not confirm the person by name.',
                $role === null => "Role \"{$roleRaw}\" is not one of student, teacher, staff or super_admin.",
                default => null,
            };

            if ($reason) {
                $rejected[] = ['line' => $lineNo, 'id_number' => $id, 'reason' => $reason];
                continue;
            }

            $seen[$id] = $lineNo;
            $rows[] = ['id_number' => $id, 'first_name' => $first, 'last_name' => $last ?: null, 'role' => $role];
        }

        if ($header === null) {
            return ['rows' => [], 'rejected' => [], 'error' => 'The file is empty.'];
        }

        return ['rows' => $rows, 'rejected' => $rejected, 'error' => null];
    }

    /** Replace the whole list with these rows, in one transaction. */
    public static function replace(array $rows): void
    {
        $now = now();

        DB::transaction(function () use ($rows, $now) {
            DB::table('school_roster')->delete();
            foreach (array_chunk($rows, 500) as $chunk) {
                DB::table('school_roster')->insert(array_map(fn ($r) => $r + ['imported_at' => $now], $chunk));
            }
        });
    }

    /**
     * Bring the accounts in line with the list just imported. Only with the
     * driver set to csv - a list stored while the driver is off changes nobody.
     *
     *  - An active account whose ID is not on the list is switched off
     *    (status deactivated, flagged as done by the list) and signed out
     *    everywhere. Its password is kept.
     *  - An account the list switched off earlier, whose ID is back on this
     *    list, is switched on again with the password it had.
     * Accounts staff deactivated by hand are not touched either way.
     *
     * @return array{switched_off: array<int,string>, switched_on: array<int,string>}
     */
    public static function syncAccounts(): array
    {
        if (config('school.directory.driver') !== 'csv' || ! self::isLoaded()) {
            return ['switched_off' => [], 'switched_on' => []];
        }

        $listed = fn ($q) => $q->select('id_number')->from('school_roster');

        return DB::transaction(function () use ($listed) {
            $off = User::where('status', 'active')->whereNotIn('id_number', $listed)->get();
            foreach ($off as $u) {
                $u->update(['status' => 'deactivated', 'deactivated_by_roster' => true]);
                $u->tokens()->delete();
            }

            $on = User::where('deactivated_by_roster', true)->whereIn('id_number', $listed)->get();
            foreach ($on as $u) {
                $u->update(['status' => 'active', 'deactivated_by_roster' => false]);
            }

            return [
                'switched_off' => $off->pluck('id_number')->all(),
                'switched_on'  => $on->pluck('id_number')->all(),
            ];
        });
    }

    /** Is a list loaded at all? An empty list enforces nothing. */
    public static function isLoaded(): bool
    {
        return DB::table('school_roster')->exists();
    }

    public static function find(string $idNumber): ?object
    {
        return DB::table('school_roster')->where('id_number', $idNumber)->first();
    }

    public static function contains(?string $idNumber): bool
    {
        return $idNumber !== null && DB::table('school_roster')->where('id_number', $idNumber)->exists();
    }

    /** Is the roster switched on (driver csv) and loaded? Only then is it enforced. */
    public static function isEnforced(): bool
    {
        return config('school.directory.driver') === 'csv' && self::isLoaded();
    }

    /** @return array{total:int, by_role: array<string,int>, imported_at: ?string} */
    public static function summary(): array
    {
        $byRole = DB::table('school_roster')->select('role', DB::raw('COUNT(*) as n'))->groupBy('role')->pluck('n', 'role')->all();

        return [
            'total'       => (int) array_sum($byRole),
            'by_role'     => array_merge(array_fill_keys(self::ROLES, 0), array_map('intval', $byRole)),
            'imported_at' => DB::table('school_roster')->max('imported_at'),
        ];
    }

    private static function mapHeader(array $cells): array
    {
        $map = [];
        foreach ($cells as $i => $cell) {
            $name = Str::lower(preg_replace('/\s+/', ' ', trim($cell)));
            foreach (self::HEADERS as $field => $aliases) {
                if (! isset($map[$field]) && in_array($name, $aliases, true)) {
                    $map[$field] = $i;
                }
            }
        }

        return $map;
    }
}
