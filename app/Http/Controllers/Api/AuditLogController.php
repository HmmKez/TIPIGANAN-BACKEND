<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\Request;

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
}