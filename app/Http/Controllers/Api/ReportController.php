<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\CitationLog;
use App\Models\Thesis;
use App\Models\User;
use App\Support\DateRange;
use App\Support\SafeCache;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Barryvdh\DomPDF\Facade\Pdf;

class ReportController extends Controller
{
    // These are all expensive GROUP BY aggregates over audit_logs/citation_logs
    // (tables that only grow), viewed on a staff-only dashboard that doesn't
    // need up-to-the-second freshness. A few minutes of staleness is a fine
    // trade for not re-running the aggregation on every page view.
    private const TTL = 300;

    // Reports driven by user activity (citations, searches, sessions) can be
    // narrowed to student-only, teacher-only, or both, plus a date range.
    // Reports about the theses themselves (by-department, by-year) and the
    // top-level dashboard totals aren't user-activity reports, so no filter
    // applies to those.
    private function parseFilters(Request $request): array
    {
        $roles = collect(explode(',', (string) $request->input('roles', '')))
            ->map(fn ($r) => trim($r))
            ->filter(fn ($r) => in_array($r, ['student', 'teacher'], true))
            ->values()
            ->all();

        return [
            'roles'     => $roles,
            'date_from' => $request->input('date_from'),
            'date_to'   => $request->input('date_to'),
        ];
    }

    private function filterSignature(array $filters): string
    {
        return md5(json_encode($filters));
    }

    private function filterSubtitle(array $filters): string
    {
        $parts = [];
        if (! empty($filters['roles'])) {
            $parts[] = 'Role: ' . implode(', ', array_map('ucfirst', $filters['roles']));
        }
        if ($filters['date_from'] || $filters['date_to']) {
            $parts[] = 'Date: ' . $this->formatDateRange($filters['date_from'], $filters['date_to']);
        }

        return $parts ? implode(' | ', $parts) : 'Generated report';
    }

    private function mostCitedQuery(array $filters)
    {
        return CitationLog::query()
            ->when($filters['roles'], fn ($q, $roles) =>
                $q->whereHas('user', fn ($q2) => $q2->whereIn('role', $roles)))
            // Range comparisons, not whereDate() — see App\Support\DateRange.
            ->when($filters['date_from'], fn ($q, $d) => $q->where('cited_at', '>=', DateRange::start($d)))
            ->when($filters['date_to'], fn ($q, $d) => $q->where('cited_at', '<', DateRange::endExclusive($d)))
            ->select('thesis_id', DB::raw('COUNT(*) as citation_count'))
            ->groupBy('thesis_id')
            ->orderByDesc('citation_count')
            ->limit(10)
            ->with('thesis:id,title,authors,year_published,category_id');
    }

    /**
     * The top 10 search terms — the single implementation behind both the live
     * report and its PDF export, which previously each carried their own copy
     * of the parsing logic and had already drifted apart.
     *
     * Counting happens in PHP rather than SQL because the term lives in a JSON
     * column and MySQL/SQLite disagree on how to group by it. That means rows
     * are pulled into memory — so this is deliberately bounded with lazy() and
     * a running tally instead of ->get(), which would have loaded EVERY search
     * ever made. On a repository logging thousands of searches a month, the old
     * ->get() was the same unbounded-memory bug as the audit-log PDF export.
     */
    private function mostSearchedTerms(array $filters): \Illuminate\Support\Collection
    {
        $counts = [];

        foreach ($this->mostSearchedQuery($filters)->lazyById(1000) as $log) {
            $term = $log->searchQuery();

            // A row whose term can't be recovered at all is dropped, rather than
            // counted as a "keyword" made out of its own log sentence — which is
            // what the old regex fallback (`?? $description`) silently did.
            if ($term !== null && $term !== '') {
                $counts[$term] = ($counts[$term] ?? 0) + 1;
            }
        }

        return collect($counts)->sortDesc()->take(10);
    }

    private function mostSearchedQuery(array $filters)
    {
        return AuditLog::where('action', 'search')
            ->when($filters['roles'], fn ($q, $roles) =>
                $q->whereHas('user', fn ($q2) => $q2->whereIn('role', $roles)))
            // Range comparisons, not whereDate() — see App\Support\DateRange.
            ->when($filters['date_from'], fn ($q, $d) => $q->where('created_at', '>=', DateRange::start($d)))
            ->when($filters['date_to'], fn ($q, $d) => $q->where('created_at', '<', DateRange::endExclusive($d)));
    }

    // Distinct users seen active in each hour-of-day (0-23), across the
    // whole filtered date range — "how many different people tend to be
    // on the site around 2pm", not a raw activity-volume count. Guests
    // (user_id null) can't be individually counted, so they're excluded
    // rather than undercounting/overcounting an unknown number of them.
    private function usersOnlineQuery(array $filters)
    {
        return AuditLog::select(
                DB::raw('HOUR(created_at) as hour'),
                DB::raw('COUNT(DISTINCT user_id) as users_online')
            )
            ->whereNotNull('user_id')
            ->when($filters['roles'], fn ($q, $roles) =>
                $q->whereHas('user', fn ($q2) => $q2->whereIn('role', $roles)))
            // Range comparisons, not whereDate() — see App\Support\DateRange.
            ->when($filters['date_from'], fn ($q, $d) => $q->where('created_at', '>=', DateRange::start($d)))
            ->when($filters['date_to'], fn ($q, $d) => $q->where('created_at', '<', DateRange::endExclusive($d)))
            ->groupBy('hour')
            ->orderBy('hour');
    }

    // Most cited theses
    public function mostCited(Request $request)
    {
        $filters = $this->parseFilters($request);

        $results = SafeCache::remember('reports:most-cited:' . $this->filterSignature($filters), self::TTL,
            fn () => $this->mostCitedQuery($filters)->get()->toArray());

        return response()->json($results);
    }

    // Theses count per collection (category) — not a user-activity report, so no
    // role/date filter.
    public function byDepartment()
    {
        // `code` is loaded so the chart can label its axis "CABM-B" rather than
        // "College of Business and Management - Business", which no bar is wide
        // enough to hold.
        //
        // The cache key is versioned because this payload's SHAPE changed. A key
        // still holding the old {id, name} rows would keep serving them for the
        // rest of the TTL, and every bar would render with a blank label — the
        // kind of bug that looks like the frontend's fault and only appears in
        // whichever environment happens to have a warm cache.
        $results = SafeCache::remember('reports:by-department:v2', self::TTL, fn () =>
            Thesis::select('category_id', DB::raw('COUNT(*) as total'))
                ->where('status', 'active')
                ->groupBy('category_id')
                ->with('category:id,code,name')
                ->get()
                ->toArray()
        );

        return response()->json($results);
    }

    // Theses count by year — not a user-activity report, no role/date filter
    public function byYear()
    {
        $results = SafeCache::remember('reports:by-year', self::TTL, fn () =>
            Thesis::select('year_published', DB::raw('COUNT(*) as total'))
                ->where('status', 'active')
                ->groupBy('year_published')
                ->orderByDesc('year_published')
                ->get()
                ->toArray()
        );

        return response()->json($results);
    }

    // Most searched keywords from audit logs
    public function mostSearched(Request $request)
    {
        $filters = $this->parseFilters($request);

        // Grouping by the raw description would split identical keywords
        // searched by different users into separate rows (the description
        // includes the searcher's name), so the term is aggregated instead.
        // That term comes from AuditLog::searchQuery() — the structured
        // metadata — not from re-parsing the display sentence here.
        $results = SafeCache::remember('reports:most-searched:' . $this->filterSignature($filters), self::TTL, fn () =>
            $this->mostSearchedTerms($filters)
                ->map(fn ($count, $keyword) => ['keyword' => $keyword, 'count' => $count])
                ->values()
                ->toArray()
        );

        return response()->json($results);
    }

    // Users online by hour of day
    public function usersOnline(Request $request)
    {
        $filters = $this->parseFilters($request);

        $results = SafeCache::remember('reports:users-online:' . $this->filterSignature($filters), self::TTL,
            fn () => $this->usersOnlineQuery($filters)->get()->toArray());

        return response()->json($results);
    }

    // Full dashboard summary — top-level totals, not filtered by role/date
    public function dashboard()
    {
        $data = SafeCache::remember('reports:dashboard', self::TTL, fn () => [
            'total_theses'     => Thesis::where('status', 'active')->count(),
            'total_users'      => User::count(),
            'total_citations'  => CitationLog::count(),
            'total_categories' => \App\Models\Category::count(),
            'recent_uploads'   => Thesis::with('category', 'uploader')
                                    ->where('status', 'active')
                                    ->latest()
                                    ->limit(5)
                                    ->get()
                                    ->toArray(),
            'most_cited'       => CitationLog::select('thesis_id', DB::raw('COUNT(*) as citation_count'))
                                    ->groupBy('thesis_id')
                                    ->orderByDesc('citation_count')
                                    ->limit(5)
                                    ->with('thesis:id,title,authors')
                                    ->get()
                                    ->toArray(),
        ]);

        return response()->json($data);
    }

    public function exportPdf(Request $request)
    {
        $request->validate([
            'report'    => ['required', 'in:dashboard,most-cited,by-department,by-year,most-searched,users-online'],
            'date_from' => ['nullable', 'date'],
            'date_to'   => ['nullable', 'date', 'after_or_equal:date_from'],
            'roles'     => ['nullable', 'string'],
        ]);

        $user = $request->user();

        if (! $user || ! $user->hasPermissionTo('export_reports')) {
            abort(403, 'You do not have permission to export reports.');
        }

        $reportType = $request->input('report');
        $filters = $this->parseFilters($request);
        $reportTitle = $this->reportTitle($reportType);

        $data = $this->collectReportData($reportType, $filters);

        $pdf = Pdf::loadView('pdf.pdf_report', [
            'title' => $reportTitle,
            'subtitle' => $this->filterSubtitle($filters),
            'generatedAt' => now()->format('Y-m-d H:i:s'),
            'dateRange' => $this->formatDateRange($filters['date_from'], $filters['date_to']),
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
            // The route segment stays 'by-department' (changing it would break
            // every saved link and the frontend's report picker); only the
            // printed heading is corrected. The categories have not been only
            // departments for some time.
            'by-department' => 'Thesis Count by Collection',
            'by-year' => 'Thesis Count by Year',
            'most-searched' => 'Most Searched Keywords',
            'users-online' => 'Users Online',
            default => 'Report',
        };
    }

    private function collectReportData(string $reportType, array $filters): array
    {
        return match ($reportType) {
            'dashboard' => $this->dashboardReportData(),
            'most-cited' => $this->mostCitedReportData($filters),
            'by-department' => $this->byDepartmentReportData(),
            'by-year' => $this->byYearReportData(),
            'most-searched' => $this->mostSearchedReportData($filters),
            'users-online' => $this->usersOnlineReportData($filters),
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

    private function mostCitedReportData(array $filters): array
    {
        $results = $this->mostCitedQuery($filters)->get();

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

    private function mostSearchedReportData(array $filters): array
    {
        $results = $this->mostSearchedTerms($filters);

        return [
            'columns' => ['Keyword', 'Search Count'],
            'rows' => $results->map(fn ($count, $keyword) => [$keyword, $count])->values()->toArray(),
        ];
    }

    private function usersOnlineReportData(array $filters): array
    {
        $results = $this->usersOnlineQuery($filters)->get();

        return [
            'columns' => ['Hour', 'Users Online'],
            'rows' => $results->map(function ($item) {
                return [
                    sprintf('%02d:00', $item->hour),
                    $item->users_online,
                ];
            })->toArray(),
        ];
    }
}
