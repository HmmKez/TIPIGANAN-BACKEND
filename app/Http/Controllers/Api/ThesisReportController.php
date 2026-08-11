<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Thesis;
use App\Models\ThesisReport;
use Illuminate\Http\Request;

class ThesisReportController extends Controller
{
    // Any logged-in user can flag a thesis — students/teachers use this
    // from the "Report" button on the thesis detail page.
    public function store(Request $request, $thesisId)
    {
        $thesis = Thesis::findOrFail($thesisId);

        $request->validate([
            'reason' => 'nullable|string|max:1000',
        ]);

        $report = ThesisReport::create([
            'thesis_id' => $thesis->id,
            'user_id'   => $request->user()->id,
            'reason'    => $request->reason,
            'status'    => 'pending',
        ]);

        AuditLog::create([
            'user_id'     => $request->user()->id,
            'action'      => 'report_thesis',
            'target_type' => 'thesis',
            'target_id'   => $thesis->id,
            'description' => "{$request->user()->display_name} reported thesis: {$thesis->title}",
            'ip_address'  => $request->ip(),
        ]);

        return response()->json($report, 201);
    }

    // Staff and above — list reported theses, most recent first
    public function index(Request $request)
    {
        $reports = ThesisReport::with(['thesis:id,title,authors,status', 'thesis.category:id,name', 'user:id,name,email', 'resolver:id,name'])
            ->when($request->status, fn ($q) => $q->where('status', $request->status), fn ($q) => $q->where('status', 'pending'))
            ->latest()
            ->paginate(20);

        return response()->json($reports);
    }

    // Staff and above — mark a report as resolved after reviewing/editing
    // the flagged thesis
    public function resolve(Request $request, $id)
    {
        $report = ThesisReport::findOrFail($id);

        $report->update([
            'status'      => 'resolved',
            'resolved_by' => $request->user()->id,
            'resolved_at' => now(),
        ]);

        AuditLog::create([
            'user_id'     => $request->user()->id,
            'action'      => 'resolve_thesis_report',
            'target_type' => 'thesis',
            'target_id'   => $report->thesis_id,
            'description' => "{$request->user()->display_name} resolved a report on thesis #{$report->thesis_id}",
            'ip_address'  => $request->ip(),
        ]);

        return response()->json($report);
    }
}
