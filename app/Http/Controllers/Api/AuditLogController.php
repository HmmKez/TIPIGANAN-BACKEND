<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\View;
use Barryvdh\DomPDF\Facade\Pdf;

class AuditLogController extends Controller
{
    public function index(Request $request)
    {
        $logs = AuditLog::with('user')
            ->when($request->user_id, fn($q) =>
                $q->where('user_id', $request->user_id))
            ->when($request->action, fn($q) =>
                $q->where('action', $request->action))
            ->when($request->date_from, fn($q) =>
                $q->whereDate('created_at', '>=', $request->date_from))
            ->when($request->date_to, fn($q) =>
                $q->whereDate('created_at', '<=', $request->date_to))
            ->latest('created_at')
            ->paginate(20);

        return response()->json($logs);
    }

    public function show($id)
    {
        $log = AuditLog::with('user')->findOrFail($id);
        return response()->json($log);
    }

    public function exportPdf(Request $request)
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

        $query = AuditLog::with('user')
            ->when($request->user_id, fn ($q) => $q->where('user_id', $request->user_id))
            ->when($request->action, fn ($q) => $q->where('action', $request->action))
            ->when($dateFrom, fn ($q) => $q->whereDate('created_at', '>=', $dateFrom))
            ->when($dateTo, fn ($q) => $q->whereDate('created_at', '<=', $dateTo))
            ->latest('created_at');

        $logs = $query->get();
        $periodLabel = match ($period) {
            'day' => 'Last 1 day',
            'week' => 'Last 1 week',
            'month' => 'Last 1 month',
            'custom' => 'Custom range',
            default => 'All records',
        };

        $rows = $logs->map(function ($log) {
            return [
                'timestamp' => $log->created_at?->format('Y-m-d H:i:s') ?? '-',
                'user' => $log->user?->name ?? 'System',
                'action' => $log->action,
                'description' => $log->description ?? '-',
            ];
        })->values();

        $dateRange = $dateFrom && $dateTo
            ? $dateFrom . ' to ' . $dateTo
            : ($dateFrom ? 'From ' . $dateFrom : ($dateTo ? 'Until ' . $dateTo : 'All available records'));

        $pdf = Pdf::loadView('pdf.pdf_audit', [
            'title' => 'Audit Log Export',
            'subtitle' => $periodLabel,
            'generatedAt' => now()->format('Y-m-d H:i:s'),
            'dateRange' => $dateRange,
            'rows' => $rows,
        ])->setPaper('a4', 'portrait');

        return $pdf->download('audit-log-export.pdf');
    }
}
