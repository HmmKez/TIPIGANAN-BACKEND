<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\CitationLog;
use App\Models\Thesis;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Barryvdh\DomPDF\Facade\Pdf;

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
        // Grouping by the raw description would split identical keywords
        // searched by different users into separate rows (the description
        // includes the searcher's name). Extract the keyword first, then
        // aggregate counts over the actual search term.
        $results = AuditLog::where('action', 'search')
            ->pluck('description')
            ->map(function ($description) {
                preg_match('/searched for: (.+)/', $description, $matches);
                return $matches[1] ?? $description;
            })
            ->countBy()
            ->sortDesc()
            ->take(10)
            ->map(fn ($count, $keyword) => ['keyword' => $keyword, 'count' => $count])
            ->values();

        return response()->json($results);
    }

    // Most active users — "hours active" is the count of distinct
    // date+hour buckets in which a user had at least one logged action.
    // There's no session-duration tracking in the schema (sessions here
    // are stateless Sanctum tokens, not the sessions table), so this is
    // the closest honest proxy for "time spent on the site" the audit
    // trail can support.
    public function mostActiveUsers()
    {
        $results = AuditLog::select('user_id', DB::raw("COUNT(DISTINCT DATE_FORMAT(created_at, '%Y-%m-%d %H')) as active_hours"))
            ->whereNotNull('user_id')
            ->groupBy('user_id')
            ->orderByDesc('active_hours')
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

    public function exportPdf(Request $request)
    {
        $request->validate([
            'report' => ['required', 'in:dashboard,most-cited,by-department,by-year,most-searched,most-active,peak-hours'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
        ]);

        $user = $request->user();

        if (! $user || ! $user->hasPermissionTo('export_reports')) {
            abort(403, 'You do not have permission to export reports.');
        }

        $reportType = $request->input('report');
        $dateFrom = $request->input('date_from');
        $dateTo = $request->input('date_to');
        $reportTitle = $this->reportTitle($reportType);

        $data = $this->collectReportData($reportType, $dateFrom, $dateTo);

        $pdf = Pdf::loadView('pdf.pdf_report', [
            'title' => $reportTitle,
            'subtitle' => 'Generated report',
            'generatedAt' => now()->format('Y-m-d H:i:s'),
            'dateRange' => $this->formatDateRange($dateFrom, $dateTo),
            'columns' => $data['columns'],
            'rows' => $data['rows'],
        ])->setPaper('a4', 'portrait');

        return $pdf->download(str_replace(' ', '_', strtolower($reportTitle)) . '.pdf');
    }

    private function reportTitle(string $reportType): string
    {
        return match ($reportType) {
            'dashboard' => 'Dashboard Summary',
            'most-cited' => 'Most Cited Theses',
            'by-department' => 'Thesis Count by Department',
            'by-year' => 'Thesis Count by Year',
            'most-searched' => 'Most Searched Keywords',
            'most-active' => 'Most Active Users',
            'peak-hours' => 'Peak Usage Hours',
            default => 'Report',
        };
    }

    private function collectReportData(string $reportType, ?string $dateFrom, ?string $dateTo): array
    {
        return match ($reportType) {
            'dashboard' => $this->dashboardReportData(),
            'most-cited' => $this->mostCitedReportData(),
            'by-department' => $this->byDepartmentReportData(),
            'by-year' => $this->byYearReportData(),
            'most-searched' => $this->mostSearchedReportData(),
            'most-active' => $this->mostActiveUsersReportData(),
            'peak-hours' => $this->peakHoursReportData(),
            default => ['columns' => [], 'rows' => []],
        };
    }

    private function formatDateRange(?string $from, ?string $to): string
    {
        if ($from && $to) {
            return $from . ' to ' . $to;
        }

        if ($from) {
            return 'From ' . $from;
        }

        if ($to) {
            return 'Until ' . $to;
        }

        return 'All available records';
    }

    private function dashboardReportData(): array
    {
        $summary = $this->dashboard()->getData(true);

        return [
            'columns' => ['Metric', 'Value'],
            'rows' => [
                ['Total Theses', $summary['total_theses']],
                ['Total Users', $summary['total_users']],
                ['Total Citations', $summary['total_citations']],
                ['Total Categories', $summary['total_categories']],
            ],
        ];
    }

    private function mostCitedReportData(): array
    {
        $results = CitationLog::select('thesis_id', DB::raw('COUNT(*) as citation_count'))
            ->groupBy('thesis_id')
            ->orderByDesc('citation_count')
            ->limit(10)
            ->with('thesis:id,title,authors,year_published,category_id')
            ->get();

        return [
            'columns' => ['Thesis', 'Authors', 'Year', 'Citation Count'],
            'rows' => $results->map(function ($item) {
                return [
                    $item->thesis?->title ?? 'Unknown',
                    $item->thesis?->authors ?? '-',
                    $item->thesis?->year_published ?? '-',
                    $item->citation_count,
                ];
            })->toArray(),
        ];
    }

    private function byDepartmentReportData(): array
    {
        $results = Thesis::select('category_id', DB::raw('COUNT(*) as total'))
            ->where('status', 'active')
            ->groupBy('category_id')
            ->with('category:id,name')
            ->get();

        return [
            'columns' => ['Department', 'Total Theses'],
            'rows' => $results->map(function ($item) {
                return [
                    $item->category?->name ?? 'Uncategorized',
                    $item->total,
                ];
            })->toArray(),
        ];
    }

    private function byYearReportData(): array
    {
        $results = Thesis::select('year_published', DB::raw('COUNT(*) as total'))
            ->where('status', 'active')
            ->groupBy('year_published')
            ->orderByDesc('year_published')
            ->get();

        return [
            'columns' => ['Year', 'Total Theses'],
            'rows' => $results->map(function ($item) {
                return [
                    $item->year_published,
                    $item->total,
                ];
            })->toArray(),
        ];
    }

    private function mostSearchedReportData(): array
    {
        $results = AuditLog::where('action', 'search')
            ->pluck('description')
            ->map(function ($description) {
                preg_match('/searched for: (.+)/', $description, $matches);
                return $matches[1] ?? $description;
            })
            ->countBy()
            ->sortDesc()
            ->take(10);

        return [
            'columns' => ['Keyword', 'Search Count'],
            'rows' => $results->map(fn ($count, $keyword) => [$keyword, $count])->values()->toArray(),
        ];
    }

    private function mostActiveUsersReportData(): array
    {
        $results = AuditLog::select('user_id', DB::raw("COUNT(DISTINCT DATE_FORMAT(created_at, '%Y-%m-%d %H')) as active_hours"))
            ->whereNotNull('user_id')
            ->groupBy('user_id')
            ->orderByDesc('active_hours')
            ->limit(10)
            ->with('user:id,name,email,role')
            ->get();

        return [
            'columns' => ['User', 'Email', 'Role', 'Hours Active'],
            'rows' => $results->map(function ($item) {
                return [
                    $item->user?->name ?? 'Unknown',
                    $item->user?->email ?? '-',
                    $item->user?->role ?? '-',
                    $item->active_hours,
                ];
            })->toArray(),
        ];
    }

    private function peakHoursReportData(): array
    {
        $results = AuditLog::select(
                DB::raw('HOUR(created_at) as hour'),
                DB::raw('COUNT(*) as total')
            )
            ->groupBy('hour')
            ->orderBy('hour')
            ->get();

        return [
            'columns' => ['Hour', 'Total Activity'],
            'rows' => $results->map(function ($item) {
                return [
                    sprintf('%02d:00', $item->hour),
                    $item->total,
                ];
            })->toArray(),
        ];
    }
}
