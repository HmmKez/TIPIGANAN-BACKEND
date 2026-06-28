<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\CitationLog;
use App\Models\Thesis;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ReportController extends Controller
{
    // Most cited theses
    public function mostCited()
    {
        $results = CitationLog::select('thesis_id', DB::raw('COUNT(*) as citation_count'))
            ->groupBy('thesis_id')
            ->orderByDesc('citation_count')
            ->limit(10)
            ->with('thesis:id,title,authors,year_published,category_id')
            ->get();

        return response()->json($results);
    }

    // Theses count by department/category
    public function byDepartment()
    {
        $results = Thesis::select('category_id', DB::raw('COUNT(*) as total'))
            ->where('status', 'active')
            ->groupBy('category_id')
            ->with('category:id,name')
            ->get();

        return response()->json($results);
    }

    // Theses count by year
    public function byYear()
    {
        $results = Thesis::select('year_published', DB::raw('COUNT(*) as total'))
            ->where('status', 'active')
            ->groupBy('year_published')
            ->orderByDesc('year_published')
            ->get();

        return response()->json($results);
    }

    // Most searched keywords from audit logs
    public function mostSearched()
    {
        $results = AuditLog::where('action', 'search')
            ->select('description', DB::raw('COUNT(*) as count'))
            ->groupBy('description')
            ->orderByDesc('count')
            ->limit(10)
            ->get()
            ->map(function ($log) {
                // Extract the search term from the description
                preg_match('/searched for: (.+)/', $log->description, $matches);
                return [
                    'keyword' => $matches[1] ?? $log->description,
                    'count'   => $log->count,
                ];
            });

        return response()->json($results);
    }

    // Most active users
    public function mostActiveUsers()
    {
        $results = AuditLog::select('user_id', DB::raw('COUNT(*) as activity_count'))
            ->whereNotNull('user_id')
            ->groupBy('user_id')
            ->orderByDesc('activity_count')
            ->limit(10)
            ->with('user:id,name,email,role')
            ->get();

        return response()->json($results);
    }

    // Peak usage hours
    public function peakHours()
    {
        $results = AuditLog::select(
                DB::raw('HOUR(created_at) as hour'),
                DB::raw('COUNT(*) as total')
            )
            ->groupBy('hour')
            ->orderBy('hour')
            ->get();

        return response()->json($results);
    }

    // Full dashboard summary
    public function dashboard()
    {
        return response()->json([
            'total_theses'     => Thesis::where('status', 'active')->count(),
            'total_users'      => User::count(),
            'total_citations'  => CitationLog::count(),
            'total_categories' => \App\Models\Category::count(),
            'recent_uploads'   => Thesis::with('category', 'uploader')
                                    ->where('status', 'active')
                                    ->latest()
                                    ->limit(5)
                                    ->get(),
            'most_cited'       => CitationLog::select('thesis_id', DB::raw('COUNT(*) as citation_count'))
                                    ->groupBy('thesis_id')
                                    ->orderByDesc('citation_count')
                                    ->limit(5)
                                    ->with('thesis:id,title,authors')
                                    ->get(),
        ]);
    }
}