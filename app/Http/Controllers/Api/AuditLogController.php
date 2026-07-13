<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Support\AuditCsv;
use App\Support\DateRange;
use App\Support\SafeCache;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;

class AuditLogController extends Controller
{
    public function index(Request $request)
    {
        $perPage = 20;
        $page    = max(1, (int) $request->input('page', 1));

        $query = AuditLog::with('user')
            ->when($request->user_id, fn ($q) =>
                $q->where('user_id', $request->user_id))
            ->when($request->action, fn ($q) =>
                $q->where('action', $request->action))
            // Range comparisons, not whereDate() — see App\Support\DateRange.
            ->when($request->date_from, fn ($q) =>
                $q->where('created_at', '>=', DateRange::start($request->date_from)))
            ->when($request->date_to, fn ($q) =>
                $q->where('created_at', '<', DateRange::endExclusive($request->date_to)))
            ->latest('created_at');

        // paginate() runs a COUNT(*) over the whole filtered set on EVERY page
        // load, purely to render "N entries" and the last page number. That cost
        // is proportional to the table size and no index removes it — 75ms at
        // 200k rows, and it only grows. The count is cached briefly instead: an
        // audit log is append-only, so a total that is a few seconds stale is
        // harmless, while a full scan per request is not.
        $total = SafeCache::remember(
            'audit:count:' . md5(json_encode($request->only(['user_id', 'action', 'date_from', 'date_to']))),
            30,
            fn () => (clone $query)->toBase()->getCountForPagination()
        );

        $items = $query->forPage($page, $perPage)->get();

        return response()->json(new LengthAwarePaginator($items, $total, $perPage, $page, [
            'path'     => $request->url(),
            'pageName' => 'page',
        ]));
    }

    public function show($id)
    {
        $log = AuditLog::with('user')->findOrFail($id);
        return response()->json($log);
    }

    /**
     * The audit log exports as CSV, not PDF.
     *
     * It is the one export here that is UNBOUNDED — every login, view, search,
     * citation copy and admin action appends a row, so it only ever grows. The
     * old version did `$query->get()` and handed the whole result set to DomPDF,
     * which buffers the entire rendered document in memory: fine at a few
     * hundred rows, a guaranteed OOM at tens of thousands. Streaming CSV keeps
     * memory flat regardless of size (see the lazy() below).
     *
     * It is also the wrong *kind* of artifact for a PDF. Nobody reads an audit
     * trail front to back — they filter it, sort it, pivot it, or hand it to
     * someone doing an inspection. That is spreadsheet work. The other reports
     * (dashboard, most-cited, by-year…) stay PDF: they are small, fixed-size
     * summaries meant to be read and printed.
     */
    public function exportCsv(Request $request)
    {
        $request->validate([
            'period' => ['nullable', 'in:day,week,month,custom'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'user_id' => ['nullable', 'exists:users,id'],
            'action' => ['nullable', 'string'],
        ]);

        $user = $request->user();

        if (! $user || ! $user->hasPermissionTo('export_reports')) {
            abort(403, 'You do not have permission to export audit logs.');
        }

        $period = $request->input('period', 'month');
        $dateFrom = $request->input('date_from');
        $dateTo = $request->input('date_to');

        if ($period === 'custom') {
            if (! $dateFrom || ! $dateTo) {
                return response()->json([
                    'message' => 'Custom export requires both date_from and date_to.',
                ], 422);
            }
        } else {
            $dateFrom = match ($period) {
                'day' => Carbon::now()->subDay()->toDateString(),
                'week' => Carbon::now()->subWeek()->toDateString(),
                'month' => Carbon::now()->subMonth()->toDateString(),
                default => null,
            };

            $dateTo = Carbon::now()->toDateString();
        }

        // Deliberately NOT ->latest('created_at'): the rows are streamed with
        // lazyByIdDesc() below, which paginates by "WHERE id < last_id" rather
        // than OFFSET. That matters because the audit log is being written to
        // constantly (every view, search and login appends a row) — with an
        // OFFSET-paged newest-first scan, a single insert mid-export shifts every
        // subsequent offset by one and the CSV emits duplicate rows. Keyset
        // paging is immune to that. `id` descending is the same order as
        // `created_at` descending on an append-only table.
        $query = AuditLog::with('user')
            ->when($request->user_id, fn ($q) => $q->where('user_id', $request->user_id))
            ->when($request->action, fn ($q) => $q->where('action', $request->action))
            // Range comparisons, not whereDate() — see App\Support\DateRange.
            ->when($dateFrom, fn ($q) => $q->where('created_at', '>=', DateRange::start($dateFrom)))
            ->when($dateTo, fn ($q) => $q->where('created_at', '<', DateRange::endExclusive($dateTo)));

        $filename = 'audit-log-' . ($dateFrom ?: 'start') . '-to-' . ($dateTo ?: now()->toDateString()) . '.csv';

        return response()->streamDownload(function () use ($query) {
            $out = fopen('php://output', 'w');

            // Shared with the archive written by `audit:prune`, so an exported
            // file and an archived one are the same thing (see App\Support\AuditCsv).
            AuditCsv::writeHeader($out);

            // Streams the result set in keyset-paged chunks instead of hydrating
            // every row up front — this is what keeps memory flat on a log with
            // tens of thousands of entries.
            foreach ($query->lazyByIdDesc(500) as $log) {
                AuditCsv::writeRow($out, $log);
            }

            fclose($out);
        }, $filename, [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Cache-Control'       => 'no-store, no-cache',
            // The export can reflect a filter the viewer set seconds ago; never
            // let a proxy hand someone else a cached copy of an audit trail.
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
