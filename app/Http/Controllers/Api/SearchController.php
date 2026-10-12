<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Thesis;
use App\Services\SearchService;
use Illuminate\Http\Request;

class SearchController extends Controller
{
    public function __construct(protected SearchService $searchService) {}

    public function search(Request $request)
    {
        $request->validate([
            'q'              => 'required|string|min:1',
            'category_id'    => 'nullable|exists:categories,id',
            'year_published' => 'nullable|digits:4|integer',
            'status'         => 'nullable|in:active,restricted,archived',
        ]);

        // This route has no auth:sanctum middleware (guests can search too),
        // so the app's default guard is still 'web' — plain $request->user()
        // would always be null even for a logged-in Bearer-token user.
        // Asking for the 'sanctum' guard explicitly resolves it regardless.
        $user = $request->user('sanctum');

        // Same visibility rule as browsing (Thesis::visibleStatusesFor), and
        // the same ?status= narrowing: staff can search archived work too, or
        // only one status. A status the caller may not see returns nothing.
        $results = $this->searchService->search(
            $request->input('q'),
            $request->only(['category_id', 'year_published']),
            Thesis::statusesRequestedBy($user, $request->input('status'))
        );

        // Log the search activity — audit_logs.user_id is nullable specifically
        // to support anonymous/guest searches, which power "Most Searched".
        //
        // The term goes into `metadata` as data. `description` is only the
        // human-readable sentence shown in the log viewer; reports read the
        // metadata, so rewording the sentence can't break them.
        AuditLog::create([
            'user_id'     => $user?->id,
            'action'      => 'search',
            'target_type' => null,
            'target_id'   => null,
            'description' => ($user?->name ?? 'Guest') . " searched for: {$request->input('q')}",
            'metadata'    => ['query' => $request->input('q')],
            'ip_address'  => $request->ip(),
        ]);

        return response()->json($results);
    }
}