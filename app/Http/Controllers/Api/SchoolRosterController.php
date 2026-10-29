<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Setting;
use App\Models\User;
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
        SchoolRoster::replace($rows);
        $sync = SchoolRoster::syncAccounts();

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
                .($sync['switched_on'] ? '; '.count($sync['switched_on']).' accounts switched back on' : ''),
            'metadata'    => ['count' => count($rows), 'by_role' => $result['by_role'], 'rejected' => count($result['rejected'])] + $sync,
            'ip_address'  => $request->ip(),
        ]);

        $message = 'School list imported: '.count($rows).' people.'
            .($sync['switched_off'] ? ' '.count($sync['switched_off']).' account'.(count($sync['switched_off']) === 1 ? '' : 's').' not on it switched off.' : '')
            .($sync['switched_on'] ? ' '.count($sync['switched_on']).' account'.(count($sync['switched_on']) === 1 ? '' : 's').' back on it switched on again.' : '');

        return response()->json(['message' => $message] + $result + $sync + [
            'summary' => SchoolRoster::summary(),
            'accounts_not_on_list' => $this->accountsNotOnList(),
        ]);
    }

    /** Parse the uploaded file and describe what importing it would do. Changes nothing. */
    private function check(Request $request): array
    {
        $request->validate([
            'file' => 'required|file|mimes:csv,txt|max:10240',
        ], [
            'file.mimes' => 'The school list must be a CSV file (.csv).',
        ]);

        $parsed = SchoolRoster::parse($this->contents($request));
        $byRole = array_fill_keys(SchoolRoster::ROLES, 0);
        foreach ($parsed['rows'] as $r) {
            $byRole[$r['role']]++;
        }
        $ids = array_column($parsed['rows'], 'id_number');
        $previous = SchoolRoster::summary()['total'];
        $valid = count($parsed['rows']);

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
            'would_lose_access' => User::where('status', 'active')->whereNotIn('id_number', $ids ?: ['-'])->orderBy('id_number')
                ->limit(50)->get(['id_number', 'name', 'role'])
                ->map(fn ($u) => ['id_number' => $u->id_number, 'name' => $u->display_name, 'role' => $u->role])->all(),
            // Accounts an earlier list switched off that this one has again.
            'would_restore' => User::where('deactivated_by_roster', true)->whereIn('id_number', $ids ?: ['-'])->orderBy('id_number')
                ->limit(50)->get(['id_number', 'name', 'role'])
                ->map(fn ($u) => ['id_number' => $u->id_number, 'name' => $u->display_name, 'role' => $u->role])->all(),
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
            ->orderBy('id_number')->limit(50)->get(['id_number', 'name', 'role'])
            ->map(fn ($u) => ['id_number' => $u->id_number, 'name' => $u->display_name, 'role' => $u->role])->all();
    }
}
