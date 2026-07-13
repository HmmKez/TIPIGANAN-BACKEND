<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SettingController extends Controller
{
    /**
     * Public — the term sits in the navbar, which guests see too.
     */
    public function activeTerm()
    {
        return response()->json([
            'term'      => Setting::activeTerm(),
            'semesters' => Setting::SEMESTERS,
        ]);
    }

    /**
     * Super Admin only (gated by `role:super_admin` on the route). Replaces the
     * value that used to be hardcoded in the navbar, so the term can be rolled
     * over each semester without a code change or redeploy.
     */
    public function updateActiveTerm(Request $request)
    {
        $validated = $request->validate([
            'semester'    => ['required', 'string', Rule::in(Setting::SEMESTERS)],
            // Two consecutive years, e.g. 2026-2027. The format check alone
            // would happily accept "2026-2029" or a backwards "2027-2026", so
            // the consecutive-year rule is enforced below rather than trusted
            // to the regex.
            'school_year' => ['required', 'string', 'regex:/^\d{4}-\d{4}$/'],
        ]);

        [$start, $end] = array_map('intval', explode('-', $validated['school_year']));

        if ($end !== $start + 1) {
            throw ValidationException::withMessages([
                'school_year' => ['The school year must span two consecutive years, e.g. ' . $start . '-' . ($start + 1) . '.'],
            ]);
        }

        $previous = Setting::activeTerm();

        Setting::set(Setting::ACTIVE_TERM_SEMESTER, $validated['semester'], $request->user()->id);
        Setting::set(Setting::ACTIVE_TERM_SCHOOL_YEAR, $validated['school_year'], $request->user()->id);
        Setting::forgetActiveTerm();

        $term = Setting::activeTerm();

        AuditLog::create([
            'user_id'     => $request->user()->id,
            'action'      => 'update_active_term',
            'target_type' => 'setting',
            'target_id'   => null,
            'description' => "{$request->user()->name} changed the active term from \"{$previous['label']}\" to \"{$term['label']}\"",
            'ip_address'  => $request->ip(),
        ]);

        return response()->json([
            'message' => 'Active term updated.',
            'term'    => $term,
        ]);
    }
}
