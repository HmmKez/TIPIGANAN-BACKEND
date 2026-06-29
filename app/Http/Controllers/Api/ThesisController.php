<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\ReadingHistory;
use App\Models\SignedUrlToken;
use App\Models\Thesis;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use App\Jobs\ProcessThesisOcr;

class ThesisController extends Controller
{
    // Public — guests can see the list but not open documents
    public function index(Request $request)
    {
        $theses = Thesis::with('category', 'uploader')
            ->where('status', 'active')
            ->when($request->category_id, fn($q) =>
                $q->where('category_id', $request->category_id))
            ->when($request->year_published, fn($q) =>
                $q->where('year_published', $request->year_published))
            ->when($request->author, fn($q) =>
                $q->where('authors', 'LIKE', "%{$request->author}%"))
            ->latest()
            ->paginate(12);

        return response()->json($theses);
    }

    // Public — basic info only, no file access
    public function show($id)
    {
        $thesis = Thesis::with('category', 'uploader', 'citations')
            ->where('status', 'active')
            ->findOrFail($id);

        // Related articles — same category, different thesis
        $related = Thesis::where('category_id', $thesis->category_id)
            ->where('id', '!=', $thesis->id)
            ->where('status', 'active')
            ->limit(5)
            ->get(['id', 'title', 'authors', 'year_published']);

        return response()->json([
            'thesis'  => $thesis,
            'related' => $related,
        ]);
    }

    // Protected — generates a signed URL for secure PDF viewing
    public function generateViewToken(Request $request, $id)
    {
        $thesis = Thesis::where('status', 'active')->findOrFail($id);

        // Delete any existing token for this user + thesis
        SignedUrlToken::where('user_id', $request->user()->id)
            ->where('thesis_id', $id)
            ->delete();

        $token = SignedUrlToken::create([
            'user_id'    => $request->user()->id,
            'thesis_id'  => $thesis->id,
            'token'      => Str::random(64),
            'expires_at' => now()->addMinutes(30),
        ]);

        // Record reading history
        ReadingHistory::create([
            'user_id'   => $request->user()->id,
            'thesis_id' => $thesis->id,
            'viewed_at' => now(),
        ]);

        AuditLog::create([
            'user_id'     => $request->user()->id,
            'action'      => 'view_thesis',
            'target_type' => 'thesis',
            'target_id'   => $thesis->id,
            'description' => "{$request->user()->name} opened thesis: {$thesis->title}",
            'ip_address'  => $request->ip(),
        ]);

        return response()->json([
            'token'      => $token->token,
            'expires_at' => $token->expires_at,
        ]);
    }

    // Protected — serves the actual PDF file using the token
    public function servePdf(Request $request, $token)
    {
        $signedToken = SignedUrlToken::where('token', $token)
            ->where('user_id', $request->user()->id)
            ->first();

        if (! $signedToken || $signedToken->isExpired()) {
            return response()->json(['message' => 'Invalid or expired token.'], 403);
        }

        $thesis = Thesis::findOrFail($signedToken->thesis_id);
        $path   = storage_path('app/' . $thesis->file_path);

        if (! file_exists($path)) {
            return response()->json(['message' => 'File not found.'], 404);
        }

        return response()->file($path, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => 'inline',
            'Cache-Control'       => 'no-store, no-cache',
        ]);
    }

    // Staff and above — upload new thesis
    public function store(Request $request)
    {
        $request->validate([
            'title'          => 'required|string',
            'authors'        => 'required|string',
            'adviser'        => 'required|string',
            'abstract'       => 'required|string',
            'keywords'       => 'nullable|string',
            'year_published' => 'required|digits:4|integer',
            'category_id'    => 'required|exists:categories,id',
            'pages'          => 'nullable|integer',
            'cover_image'    => 'nullable|image|max:2048',
            'pdf_file'       => 'required|mimes:pdf|max:51200',
        ]);

        // Store PDF
        $pdfPath = $request->file('pdf_file')
            ->store('theses', 'local');

        // Store cover image if provided
        $coverPath = null;
        if ($request->hasFile('cover_image')) {
            $coverPath = $request->file('cover_image')
                ->store('covers', 'public');
        }

        $thesis = Thesis::create([
            'title'           => $request->title,
            'authors'         => $request->authors,
            'adviser'         => $request->adviser,
            'abstract'        => $request->abstract,
            'keywords'        => $request->keywords,
            'year_published'  => $request->year_published,
            'category_id'     => $request->category_id,
            'pages'           => $request->pages,
            'file_path'       => $pdfPath,
            'cover_image_path'=> $coverPath,
            'status'          => 'active',
            'uploaded_by'     => $request->user()->id,
        ]);

        // Dispatch OCR job to run in background
        ProcessThesisOcr::dispatch($thesis);

        AuditLog::create([
            'user_id'     => $request->user()->id,
            'action'      => 'upload_thesis',
            'target_type' => 'thesis',
            'target_id'   => $thesis->id,
            'description' => "{$request->user()->name} uploaded thesis: {$thesis->title}",
            'ip_address'  => $request->ip(),
        ]);

        return response()->json($thesis, 201);
    }

    // Staff and above — edit thesis metadata
    public function update(Request $request, $id)
    {
        $thesis = Thesis::findOrFail($id);

        $request->validate([
            'title'          => 'sometimes|string',
            'authors'        => 'sometimes|string',
            'adviser'        => 'sometimes|string',
            'abstract'       => 'sometimes|string',
            'keywords'       => 'nullable|string',
            'year_published' => 'sometimes|digits:4|integer',
            'category_id'    => 'sometimes|exists:categories,id',
            'pages'          => 'nullable|integer',
            'status'         => 'sometimes|in:active,archived,restricted',
        ]);

        $thesis->update($request->only([
            'title', 'authors', 'adviser', 'abstract',
            'keywords', 'year_published', 'category_id',
            'pages', 'status',
        ]));

        AuditLog::create([
            'user_id'     => $request->user()->id,
            'action'      => 'edit_thesis',
            'target_type' => 'thesis',
            'target_id'   => $thesis->id,
            'description' => "{$request->user()->name} edited thesis: {$thesis->title}",
            'ip_address'  => $request->ip(),
        ]);

        return response()->json($thesis);
    }

    // Staff and above — archive
    public function archive(Request $request, $id)
    {
        $thesis = Thesis::findOrFail($id);
        $thesis->update(['status' => 'archived']);

        AuditLog::create([
            'user_id'     => $request->user()->id,
            'action'      => 'archive_thesis',
            'target_type' => 'thesis',
            'target_id'   => $thesis->id,
            'description' => "{$request->user()->name} archived thesis: {$thesis->title}",
            'ip_address'  => $request->ip(),
        ]);

        return response()->json(['message' => 'Thesis archived successfully.']);
    }

    // Super admin only — hard delete (soft delete)
    public function destroy(Request $request, $id)
    {
        $thesis = Thesis::findOrFail($id);

        AuditLog::create([
            'user_id'     => $request->user()->id,
            'action'      => 'delete_thesis',
            'target_type' => 'thesis',
            'target_id'   => $thesis->id,
            'description' => "{$request->user()->name} deleted thesis: {$thesis->title}",
            'ip_address'  => $request->ip(),
        ]);

        $thesis->delete();

        return response()->json(['message' => 'Thesis deleted successfully.']);
    }

    // Staff — download (staff privilege)
    public function download(Request $request, $id)
    {
        $thesis = Thesis::findOrFail($id);
        $path   = storage_path('app/' . $thesis->file_path);

        if (! file_exists($path)) {
            return response()->json(['message' => 'File not found.'], 404);
        }

        AuditLog::create([
            'user_id'     => $request->user()->id,
            'action'      => 'download_thesis',
            'target_type' => 'thesis',
            'target_id'   => $thesis->id,
            'description' => "{$request->user()->name} downloaded thesis: {$thesis->title}",
            'ip_address'  => $request->ip(),
        ]);

        return response()->download($path, $thesis->title . '.pdf');
    }
}