<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

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
        $lines = preg_split('/\r\n|\r|\n/', self::toUtf8($contents));

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
     * Save the list and bring the accounts in line with it, as one step: if
     * anything fails, the old list and every account stay as they were.
     */
    public static function import(array $rows, ?User $importer = null): array
    {
        return DB::transaction(function () use ($rows, $importer) {
            self::replace($rows);

            return self::syncAccounts($importer);
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
     *  - An account whose name differs from the list must be activated again,
     *    since the ID number may now belong to someone else: it is signed out
     *    and cannot sign in. The person importing is the exception (see
     *    accountChanges).
     *  - A student or teacher account follows the list's role.
     * Accounts staff deactivated by hand stay deactivated either way.
     *
     * @return array{switched_off: array<int,string>, switched_on: array<int,string>, needs_activation: array<int,string>, role_changed: array<int,string>}
     */
    public static function syncAccounts(?User $importer = null): array
    {
        if (config('school.directory.driver') !== 'csv' || ! self::isLoaded()) {
            return ['switched_off' => [], 'switched_on' => [], 'needs_activation' => [], 'role_changed' => []];
        }

        $listed = fn ($q) => $q->select('id_number')->from('school_roster');

        return DB::transaction(function () use ($listed, $importer) {
            // In groups, not one account at a time: a new school year can change
            // thousands of accounts, and hosts commonly stop a request at 30 seconds.
            $off = User::where('status', 'active')->whereNotIn('id_number', $listed)->get(['id', 'id_number']);
            self::updateInGroups($off->pluck('id')->all(), ['status' => 'deactivated', 'deactivated_by_roster' => true]);
            self::signOut($off->pluck('id')->all());

            $rows = DB::table('school_roster')->get()->map(fn ($r) => (array) $r)->all();
            $changes = self::accountChanges($rows, $importer);

            foreach ($changes['names_filled'] as $c) {
                $c['user']->update(['name' => $c['list_name']]);
            }
            $renamed = collect($changes['names'])->pluck('user');
            self::updateInGroups($renamed->pluck('id')->all(), ['needs_activation' => true]);
            // Off only because an earlier list left it out: now it is simply
            // waiting. One deactivated by staff stays deactivated.
            self::updateInGroups(
                $renamed->where('deactivated_by_roster', true)->pluck('id')->all(),
                ['status' => 'active', 'deactivated_by_roster' => false],
            );
            self::signOut($renamed->pluck('id')->all());

            foreach (collect($changes['roles'])->groupBy('to') as $role => $group) {
                self::giveRole($group->pluck('user.id')->all(), $role);
            }

            $on = User::where('deactivated_by_roster', true)->whereIn('id_number', $listed)->get(['id', 'id_number']);
            self::updateInGroups($on->pluck('id')->all(), ['status' => 'active', 'deactivated_by_roster' => false]);

            return [
                'switched_off' => $off->pluck('id_number')->all(),
                'switched_on'  => $on->pluck('id_number')->all(),
                'needs_activation' => array_map(fn ($c) => $c['user']->id_number, $changes['names']),
                'role_changed' => array_map(fn ($c) => $c['user']->id_number, $changes['roles']),
            ];
        });
    }

    private static function updateInGroups(array $userIds, array $fields): void
    {
        foreach (array_chunk($userIds, 500) as $ids) {
            User::whereIn('id', $ids)->update($fields);
        }
    }

    /** Ends every session of these accounts. */
    private static function signOut(array $userIds): void
    {
        foreach (array_chunk($userIds, 500) as $ids) {
            DB::table('personal_access_tokens')
                ->where('tokenable_type', (new User)->getMorphClass())
                ->whereIn('tokenable_id', $ids)
                ->delete();
        }
    }

    /**
     * Both have to move together: the `role` column is what the app reads, and
     * the Spatie table is what the permission middleware checks.
     */
    private static function giveRole(array $userIds, string $role): void
    {
        $roleId = Role::findByName($role, 'web')->id;
        $table = config('permission.table_names.model_has_roles');
        $key = config('permission.column_names.model_morph_key');
        $type = (new User)->getMorphClass();

        foreach (array_chunk($userIds, 500) as $ids) {
            User::whereIn('id', $ids)->update(['role' => $role]);
            DB::table($table)->where('model_type', $type)->whereIn($key, $ids)->delete();
            DB::table($table)->insert(array_map(
                fn ($id) => ['role_id' => $roleId, 'model_type' => $type, $key => $id],
                $ids,
            ));
        }
    }

    /**
     * What these rows would change on accounts already in use, for the preview
     * and for the import itself.
     *
     *  - names: the name differs from the list, so it must be activated again.
     *  - names_filled: no name yet, or it is the person importing. They are
     *    signed in and confirming this list themselves, so the name just updates;
     *    sending them to activation would lock out the only person able to help.
     *  - roles: student or teacher accounts whose role differs. Staff and Super
     *    Admin are never touched, or every import would undo a promotion.
     *
     * @return array{names: array<int,array>, names_filled: array<int,array>, roles: array<int,array>}
     */
    public static function accountChanges(array $rows, ?User $importer = null): array
    {
        $byId = array_column($rows, null, 'id_number');
        $changes = ['names' => [], 'names_filled' => [], 'roles' => []];

        foreach (array_chunk(array_keys($byId), 500) as $ids) {
            $users = User::whereIn('id_number', array_map('strval', $ids))->where('needs_activation', false)->orderBy('id_number')->get();

            foreach ($users as $u) {
                $row = $byId[$u->id_number];
                $listName = self::fullName((object) $row);

                if (blank($u->name) || ($importer?->id === $u->id && ! self::sameName($u->name, $listName))) {
                    $changes['names_filled'][] = ['user' => $u, 'list_name' => $listName];
                } elseif (! self::sameName($u->name, $listName)) {
                    $changes['names'][] = ['user' => $u, 'list_name' => $listName];
                    continue;   // Activation settles the role.
                }

                $to = self::accountRole($row['role']);
                if (in_array($u->role, ['student', 'teacher'], true) && $u->role !== $to) {
                    $changes['roles'][] = ['user' => $u, 'from' => $u->role, 'to' => $to];
                }
            }
        }

        return $changes;
    }

    /** Would an active Super Admin still be able to sign in after importing these rows? */
    public static function keepsASuperAdmin(array $rows): bool
    {
        $byId = array_column($rows, null, 'id_number');

        return User::where('role', 'super_admin')->where('status', 'active')->where('needs_activation', false)
            ->get()
            ->contains(fn (User $u) => isset($byId[$u->id_number]) && (
                blank($u->name) || self::sameName($u->name, self::fullName((object) $byId[$u->id_number]))
            ));
    }

    public static function fullName(object $row): string
    {
        return trim($row->first_name.' '.$row->last_name);
    }

    /** Capitals and spacing are not a different name; anything else is. */
    public static function sameName(?string $a, ?string $b): bool
    {
        $normal = fn (?string $s) => Str::lower(trim(preg_replace('/\s+/u', ' ', (string) $s)));

        return $normal($a) === $normal($b);
    }

    /** Activation and imports never give staff powers; those come from User Management. */
    public static function accountRole(string $listRole): string
    {
        return $listRole === 'student' ? 'student' : 'teacher';
    }

    /**
     * The file to send to the school: just the columns. The byte-order mark makes
     * Excel read ñ correctly when the school fills it in.
     */
    public static function template(): string
    {
        return "\xEF\xBB\xBFid_number,first_name,last_name,role\r\n";
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

    /**
     * The file's text as UTF-8, whichever way it was saved. Excel's plain "CSV
     * (Comma delimited)" is Windows-1252, where the ñ in a name like Niño or Peña
     * is not valid UTF-8. A byte-order mark ("CSV UTF-8") is removed, or it would
     * become part of the first column's name.
     */
    private static function toUtf8(string $contents): string
    {
        if (str_starts_with($contents, "\xFF\xFE")) {
            return mb_convert_encoding(substr($contents, 2), 'UTF-8', 'UTF-16LE');
        }
        if (str_starts_with($contents, "\xFE\xFF")) {
            return mb_convert_encoding(substr($contents, 2), 'UTF-8', 'UTF-16BE');
        }
        if (str_starts_with($contents, "\xEF\xBB\xBF")) {
            $contents = substr($contents, 3);
        }

        return mb_check_encoding($contents, 'UTF-8')
            ? $contents
            : mb_convert_encoding($contents, 'UTF-8', 'Windows-1252');
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
