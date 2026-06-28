<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
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
        ]);

        $results = $this->searchService->search(
            $request->input('q'),
            $request->only(['category_id', 'year_published'])
        );

        // Log the search activity if user is logged in
        if ($request->user()) {
            AuditLog::create([
                'user_id'     => $request->user()->id,
                'action'      => 'search',
                'target_type' => null,
                'target_id'   => null,
                'description' => "{$request->user()->name} searched for: {$request->input('q')}",
                'ip_address'  => $request->ip(),
            ]);
        }

        return response()->json($results);
    }
}