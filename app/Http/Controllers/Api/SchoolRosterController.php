<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Setting;
use App\Models\User;
use App\Support\AuditCsv;
use App\Support\SchoolRoster;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Super Admin: the school's list of everyone allowed to use TIPIGANAN.
 *
 * Preview first, then import. Importing replaces the whole list. Two checks
 * stop an import from locking people out by mistake:
 *  - the Super Admin doing the import must be on the new list, or they would
 *    sign themselves out the moment it is saved;
 *  - a list much smaller than the last one (under 80%) is refused unless the
 *    person confirms, since a cut-off or wrong file is the likeliest cause.
 */
class SchoolRosterController extends Controller
{
    private const SHRINK_FLOOR = 0.8;
    private const META_KEY = 'school_roster_import';
    private const LIST_LIMIT = 50;

    public function show()
    {
        $meta = json_decode((string) Setting::get(self::META_KEY), true) ?: null;

        return response()->json([
            'enabled'     => config('school.directory.driver') === 'csv',
            'enforced'    => SchoolRoster::isEnforced(),
            'summary'     => SchoolRoster::summary(),
            'last_import' => $meta,
            // Accounts that exist but are not on the list - they cannot sign in.
            'accounts_not_on_list' => $this->accountsNotOnList(),
            'accounts_not_on_list_total' => $this->accountsNotOnListTotal(),
        ]);
    }

    /**
     * Who is on the current list: ID number and whether their account has been
     * activated. Deliberately no names or roles (the user's choice): names are
     * shown only one at a time, at activation.
     */
    public function people(Request $request)
    {
        $request->validate([
            'search'    => 'nullable|string|max:5',
            'activated' => 'nullable|in:yes,no',
        ]);

        // Activated = an account exists and is not waiting for activation.
        $activated = 'u.id IS NOT NULL AND u.needs_activation = 0';

        $q = DB::table('school_roster as r')
            ->leftJoin('users as u', 'u.id_number', '=', 'r.id_number')
            ->select('r.id_number', DB::raw("({$activated}) as activated"))
            ->orderBy('r.id_number');

        if ($search = preg_replace('/\D/', '', (string) $request->search)) {
            $q->where('r.id_number', 'like', $search.'%');
        }
        if ($request->activated === 'yes') {
            $q->whereRaw($activated);
        } elseif ($request->activated === 'no') {
            $q->whereRaw("NOT ({$activated})");
        }

        // 10 a page: the table is two narrow columns, and a school list runs to
        // thousands, so short pages with a numbered pager read best.
        $page = $q->paginate(10);
        $page->getCollection()->transform(fn ($r) => ['id_number' => $r->id_number, 'activated' => (bool) $r->activated]);

        $done = DB::table('school_roster as r')->join('users as u', 'u.id_number', '=', 'r.id_number')
            ->where('u.needs_activation', false)->count();
        $total = DB::table('school_roster')->count();

        return response()->json($page->toArray() + [
            'counts' => ['total' => $total, 'activated' => $done, 'not_activated' => $total - $done],
        ]);
    }

    public function preview(Request $request)
    {
        return response()->json($this->check($request));
    }

    /**
     * Everyone in one section of the preview, as a CSV. The screen lists only
     * the first 50, and a school's list runs to thousands, so this is how the
     * person importing can check every name first. Changes no account.
     */
    public function downloadPreview(Request $request)
    {
        $request->validate(['section' => 'required|in:switched_off,name_changes,role_changes,switched_back_on,skipped']);

        $plan = $this->plan($request);
        if ($plan['parsed']['error']) {
            return response()->json(['message' => $plan['parsed']['error']], 422);
        }

        [$columns, $rows] = match ($request->section) {
            'switched_off' => [
                ['ID number', 'Name', 'Role', 'Account'],
                $this->peopleRows($plan['losing']()),
            ],
            'switched_back_on' => [
                ['ID number', 'Name', 'Role', 'Account'],
                $this->peopleRows($plan['restoring']()),
            ],
            'name_changes' => [
                ['ID number', 'Current name', 'Name on the list', 'Role'],
                array_map(fn ($c) => [$c['user']->id_number, (string) $c['user']->name, $c['list_name'], $c['user']->role], $plan['changes']['names']),
            ],
            'role_changes' => [
                ['ID number', 'Name', 'Current role', 'New role'],
                array_map(fn ($c) => [$c['user']->id_number, $c['user']->display_name, $c['from'], $c['to']], $plan['changes']['roles']),
            ],
            'skipped' => [
                ['Line', 'ID number', 'Why'],
                array_map(fn ($r) => [(string) $r['line'], $r['id_number'], $r['reason']], $plan['parsed']['rejected']),
            ],
        };

        return $this->csvDownload($request, $columns, $rows, 'school-list-'.str_replace('_', '-', $request->section).'.csv');
    }

    /** The format to send to the school's IT. It holds no real data. */
    public function template()
    {
        return response(SchoolRoster::template(), 200, [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="school-list-template.csv"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** Everyone with an account whose ID number is not on the current list. */
    public function downloadNotOnList(Request $request)
    {
        $query = SchoolRoster::isLoaded()
            ? User::whereNotIn('id_number', fn ($q) => $q->select('id_number')->from('school_roster'))
            : User::whereRaw('1 = 0');

        return $this->csvDownload($request, ['ID number', 'Name', 'Role', 'Account'], $this->peopleRows($query), 'school-list-not-on-list.csv');
    }

    private function peopleRows($query): \Generator
    {
        foreach ($query->orderBy('id_number')->cursor() as $u) {
            yield [$u->id_number, $u->display_name, $u->role, $u->needs_activation ? 'Not activated yet' : 'Activated'];
        }
    }

    /** Names of many people are leaving the system, so the download leaves a trace. */
    private function csvDownload(Request $request, array $columns, iterable $rows, string $filename)
    {
        AuditLog::create([
            'user_id'     => $request->user()->id,
            'action'      => 'download_school_list',
            'target_type' => 'school_roster',
            'target_id'   => null,
            'description' => "{$request->user()->display_name} downloaded {$filename}",
            'ip_address'  => $request->ip(),
        ]);

        return response()->streamDownload(function () use ($columns, $rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");   // So Excel reads ñ correctly.
            fputcsv($out, $columns);
            foreach ($rows as $row) {
                fputcsv($out, array_map([self::class, 'csvCell'], $row));
            }
            fclose($out);
        }, $filename, [
            'Content-Type'           => 'text/csv; charset=UTF-8',
            'Cache-Control'          => 'no-store, no-cache',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * Excel would show the ID number 00042 as 42, which is a different person's
     * number to anyone checking the file, so those are written as text. Everything
     * else is protected against formulas, as in the audit log export.
     */
    private static function csvCell(?string $value): string
    {
        return preg_match('/^0\d*$/', (string) $value) ? '="'.$value.'"' : AuditCsv::safe((string) $value);
    }

    public function import(Request $request)
    {
        $result = $this->check($request);

        if ($result['error']) {
            return response()->json(['message' => $result['error']] + $result, 422);
        }
        if (! $result['includes_you']) {
            return response()->json(['message' => 'Your own ID number ('.$request->user()->id_number.') is not on this list. Importing it would sign you out and lock you out of the system, so it was not imported.'] + $result, 422);
        }
        if ($result['much_smaller'] && ! $request->boolean('confirm_smaller')) {
            return response()->json(['message' => "This list has {$result['valid']} people, much fewer than the {$result['previous_total']} on the current list. Check that the file is complete, then confirm to import it anyway."] + $result, 409);
        }

        $rows = SchoolRoster::parse($this->contents($request))['rows'];
        $sync = SchoolRoster::import($rows, $request->user());

        Setting::set(self::META_KEY, json_encode([
            'imported_at' => now()->toIso8601String(),
            'imported_by' => $request->user()->display_name,
            'file_name'   => $request->file('file')->getClientOriginalName(),
            'count'       => count($rows),
            'rejected'    => count($result['rejected']),
        ]), $request->user()->id);

        AuditLog::create([
            'user_id'     => $request->user()->id,
            'action'      => 'import_school_roster',
            'target_type' => 'school_roster',
            'target_id'   => null,
            'description' => "{$request->user()->display_name} imported the school list: ".count($rows).' people'
                .($result['rejected'] ? ', '.count($result['rejected']).' rows rejected' : '')
                .($sync['switched_off'] ? '; '.count($sync['switched_off']).' accounts switched off' : '')
                .($sync['switched_on'] ? '; '.count($sync['switched_on']).' accounts switched back on' : '')
                .($sync['needs_activation'] ? '; '.count($sync['needs_activation']).' accounts must be activated again (name changed)' : '')
                .($sync['role_changed'] ? '; '.count($sync['role_changed']).' roles updated' : ''),
            'metadata'    => ['count' => count($rows), 'by_role' => $result['by_role'], 'rejected' => count($result['rejected'])] + $sync,
            'ip_address'  => $request->ip(),
        ]);

        $message = 'School list imported: '.count($rows).' people.'
            .($sync['switched_off'] ? ' '.count($sync['switched_off']).' account'.(count($sync['switched_off']) === 1 ? '' : 's').' not on it switched off.' : '')
            .($sync['switched_on'] ? ' '.count($sync['switched_on']).' account'.(count($sync['switched_on']) === 1 ? '' : 's').' back on it switched on again.' : '')
            .($sync['needs_activation'] ? ' '.count($sync['needs_activation']).' account'.(count($sync['needs_activation']) === 1 ? '' : 's').' with a changed name must be activated again.' : '')
            .($sync['role_changed'] ? ' '.count($sync['role_changed']).' role'.(count($sync['role_changed']) === 1 ? '' : 's').' updated.' : '');

        return response()->json(['message' => $message] + $result + $sync + [
            'summary' => SchoolRoster::summary(),
            'accounts_not_on_list' => $this->accountsNotOnList(),
            'accounts_not_on_list_total' => $this->accountsNotOnListTotal(),
        ]);
    }

    /** Read the uploaded file and work out who it would affect. Changes nothing. */
    private function plan(Request $request): array
    {
        $request->validate([
            'file' => 'required|file|mimes:csv,txt|max:10240',
        ], [
            'file.mimes' => 'The school list must be a CSV file (.csv).',
        ]);

        $parsed = SchoolRoster::parse($this->contents($request));
        $ids = array_column($parsed['rows'], 'id_number');
        $changes = SchoolRoster::accountChanges($parsed['rows'], $request->user());
        $renamedIds = array_map(fn ($c) => $c['user']->id_number, $changes['names']);

        return [
            'parsed'    => $parsed,
            'ids'       => $ids,
            'changes'   => $changes,
            'losing'    => fn () => User::where('status', 'active')->whereNotIn('id_number', $ids ?: ['-']),
            'restoring' => fn () => User::where('deactivated_by_roster', true)->whereIn('id_number', $ids ?: ['-'])->whereNotIn('id_number', $renamedIds ?: ['-']),
        ];
    }

    /** What importing the uploaded file would do, for the screen. Changes nothing. */
    private function check(Request $request): array
    {
        ['parsed' => $parsed, 'ids' => $ids, 'changes' => $changes, 'losing' => $losing, 'restoring' => $restoring] = $this->plan($request);

        $byRole = array_fill_keys(SchoolRoster::ROLES, 0);
        foreach ($parsed['rows'] as $r) {
            $byRole[$r['role']]++;
        }
        $previous = SchoolRoster::summary()['total'];
        $valid = count($parsed['rows']);
        $people = fn ($q) => $q->orderBy('id_number')->limit(self::LIST_LIMIT)->get(['id_number', 'name', 'role'])
            ->map(fn ($u) => ['id_number' => $u->id_number, 'name' => $u->display_name, 'role' => $u->role])->all();
        $yourName = collect($changes['names_filled'])->first(fn ($c) => $c['user']->id === $request->user()->id && filled($c['user']->name));

        return [
            'error'          => $parsed['error'] ?? ($valid === 0 ? 'No usable rows were found in this file.' : null),
            'valid'          => $valid,
            'by_role'        => $byRole,
            // Capped: a badly formatted file could reject thousands of rows.
            'rejected'       => array_slice($parsed['rejected'], 0, 200),
            'rejected_total' => count($parsed['rejected']),
            'includes_you'   => in_array($request->user()->id_number, $ids, true),
            'previous_total' => $previous,
            'much_smaller'   => $previous > 0 && $valid < $previous * self::SHRINK_FLOOR,
            // Existing accounts the new list would leave out - they would lose access.
            // The lists are capped for the screen; the totals are the true counts.
            'would_lose_access' => $people($losing()),
            'would_lose_access_total' => $losing()->count(),
            // Accounts an earlier list switched off that this one has again.
            'would_restore' => $people($restoring()),
            'would_restore_total' => $restoring()->count(),
            // Accounts whose name differs from the list: they must be activated again.
            'name_changes' => array_map(fn ($c) => [
                'id_number' => $c['user']->id_number, 'name' => $c['user']->name, 'list_name' => $c['list_name'],
            ], array_slice($changes['names'], 0, self::LIST_LIMIT)),
            'name_changes_total' => count($changes['names']),
            // Students and teachers whose role follows the list.
            'role_changes' => array_map(fn ($c) => [
                'id_number' => $c['user']->id_number, 'name' => $c['user']->display_name, 'from' => $c['from'], 'to' => $c['to'],
            ], array_slice($changes['roles'], 0, self::LIST_LIMIT)),
            'role_changes_total' => count($changes['roles']),
            // The person importing keeps their access; their name just updates.
            'your_new_name' => $yourName['list_name'] ?? null,
        ];
    }

    private function contents(Request $request): string
    {
        return (string) file_get_contents($request->file('file')->getRealPath());
    }

    private function accountsNotOnList(): array
    {
        if (! SchoolRoster::isLoaded()) {
            return [];
        }

        return User::whereNotIn('id_number', fn ($q) => $q->select('id_number')->from('school_roster'))
            ->orderBy('id_number')->limit(self::LIST_LIMIT)->get(['id_number', 'name', 'role'])
            ->map(fn ($u) => ['id_number' => $u->id_number, 'name' => $u->display_name, 'role' => $u->role])->all();
    }

    private function accountsNotOnListTotal(): int
    {
        return SchoolRoster::isLoaded()
            ? User::whereNotIn('id_number', fn ($q) => $q->select('id_number')->from('school_roster'))->count()
            : 0;
    }
}
