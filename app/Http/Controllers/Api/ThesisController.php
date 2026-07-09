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
use Illuminate\Support\Facades\Storage;

class ThesisController extends Controller
{
    // Syncs a thesis to the search index without letting a Meilisearch
    // outage (e.g. cURL error 7, connection refused) fail the request —
    // the database write already succeeded, so search sync is best-effort.
    private function syncSearchable(Thesis $thesis): void
    {
        try {
            $thesis->searchable();
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Meilisearch sync failed, thesis saved to database only.', [
                'thesis_id' => $thesis->id,
                'error'     => $e->getMessage(),
            ]);
        }
    }

    // Same as syncSearchable, but for removing a thesis from the index.
    private function syncUnsearchable(Thesis $thesis): void
    {
        try {
            $thesis->unsearchable();
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Meilisearch unsearchable failed, thesis removed from database only.', [
                'thesis_id' => $thesis->id,
                'error'     => $e->getMessage(),
            ]);
        }
    }

    // Public — guests can see the list but not open documents
    public function index(Request $request)
    {
        // Restricted theses are visible to any logged-in user (student and
        // up) but hidden from guests entirely; archived theses stay hidden
        // from everyone unless explicitly requested via ?status=.
        // This route has no auth:sanctum middleware, so the default guard
        // stays 'web' — the 'sanctum' guard must be asked for explicitly.
        $user = $request->user('sanctum');
        $defaultStatuses = $user ? ['active', 'restricted'] : ['active'];

        $theses = Thesis::with('category', 'uploader')
            ->when($request->status,
                fn($q) => $q->where('status', $request->status),
                fn($q) => $q->whereIn('status', $defaultStatuses))
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
    public function show(Request $request, $id)
    {
        // Same visibility rule as index(): restricted is visible to any
        // logged-in user, hidden entirely from guests.
        $user = $request->user('sanctum');
        $visibleStatuses = $user ? ['active', 'restricted'] : ['active'];

        $thesis = Thesis::with('category', 'uploader', 'citations')
            ->whereIn('status', $visibleStatuses)
            ->findOrFail($id);

        $related = $this->findRelatedTheses($thesis, $visibleStatuses);

        return response()->json([
            'thesis'         => $thesis,
            'related'        => $related,
            'view_count'     => ReadingHistory::where('thesis_id', $thesis->id)->count(),
            'bookmark_count' => \App\Models\Favorite::where('thesis_id', $thesis->id)->count(),
            'bookmarked'     => $user
                ? \App\Models\Favorite::where('thesis_id', $thesis->id)->where('user_id', $user->id)->exists()
                : false,
        ]);
    }

    // Ranks candidate theses by actual content overlap (keywords, then
    // abstract) instead of just "same category, whatever order the DB
    // returns them in". Same-category theses are still included as a
    // baseline (score 1) even with zero keyword/abstract overlap, so this
    // never regresses to showing nothing — it just ranks genuinely similar
    // theses above merely same-department ones instead of treating them
    // the same.
    private function findRelatedTheses(Thesis $thesis, array $visibleStatuses)
    {
        // A handful of theses seeded during earlier testing have corrupted
        // `keywords` (raw OCR text blobs instead of a short comma-separated
        // list). Cap term length so a real keyword phrase still matches
        // while a paragraph-sized "term" can't turn into a slow, useless
        // LIKE '%...%' pattern that will never match anything anyway.
        $terms = collect(explode(',', $thesis->keywords ?? ''))
            ->map(fn ($k) => trim($k))
            ->filter(fn ($k) => $k !== '' && strlen($k) <= 60)
            ->values();

        $candidates = Thesis::where('id', '!=', $thesis->id)
            ->whereIn('status', $visibleStatuses)
            ->where(function ($q) use ($thesis, $terms) {
                $q->where('category_id', $thesis->category_id);
                foreach ($terms as $term) {
                    $q->orWhere('keywords', 'LIKE', "%{$term}%")
                      ->orWhere('abstract', 'LIKE', "%{$term}%");
                }
            })
            ->get(['id', 'title', 'authors', 'year_published', 'category_id', 'keywords', 'abstract']);

        return $candidates
            ->map(function ($t) use ($thesis, $terms) {
                $score = $t->category_id === $thesis->category_id ? 1 : 0;
                $candidateKeywords = strtolower($t->keywords ?? '');
                $candidateAbstract = strtolower($t->abstract ?? '');

                foreach ($terms as $term) {
                    $term = strtolower($term);
                    if (str_contains($candidateKeywords, $term)) {
                        $score += 3;
                    }
                    if (str_contains($candidateAbstract, $term)) {
                        $score += 1;
                    }
                }

                $t->relevance_score = $score;
                return $t;
            })
            ->sortByDesc('relevance_score')
            ->take(5)
            ->values()
            ->map(fn ($t) => [
                'id'             => $t->id,
                'title'          => $t->title,
                'authors'        => $t->authors,
                'year_published' => $t->year_published,
            ]);
    }

    // Protected — generates a signed URL for secure PDF viewing
    public function generateViewToken(Request $request, $id)
    {
        // Any logged-in user can read a restricted thesis (that's what
        // "restricted" means — hidden from guests, open to logged-in users).
        $thesis = Thesis::whereIn('status', ['active', 'restricted'])->findOrFail($id);

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
        $path   = \Illuminate\Support\Facades\Storage::disk('local')->path($thesis->file_path);

        if (! file_exists($path)) {
            return response()->json(['message' => 'File not found.'], 404);
        }

        $watermarked = (new \App\Services\WatermarkService())->stamp($path);

        return response($watermarked, 200, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => 'inline; filename="' . $thesis->title . '.pdf"',
            'Cache-Control'       => 'no-store, no-cache',
            'Content-Length'      => strlen($watermarked),
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

        // withoutSyncingToSearch prevents a Meilisearch connection attempt
        // (and a possible cURL error 7) from happening inline with the DB
        // write; syncSearchable() below retries it safely afterward.
        $thesis = Thesis::withoutSyncingToSearch(fn () => Thesis::create([
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
        ]));

        $this->syncSearchable($thesis);

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

        Thesis::withoutSyncingToSearch(fn () => $thesis->update($request->only([
            'title', 'authors', 'adviser', 'abstract',
            'keywords', 'year_published', 'category_id',
            'pages', 'status',
        ])));

        $this->syncSearchable($thesis);

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
        // withoutSyncingToSearch prevents a Meilisearch connection attempt
        // when Scout driver is meilisearch but the service isn't running locally.
        Thesis::withoutSyncingToSearch(fn() => $thesis->update(['status' => 'archived']));

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

    // Staff and above — unarchive, restrict, or remove a restriction.
    // 'restricted' means visible to any logged-in user but hidden from
    // guests; setting status back to 'active' un-restricts or unarchives.
    public function updateStatus(Request $request, $id)
    {
        $request->validate([
            'status' => 'required|in:active,archived,restricted',
        ]);

        $thesis = Thesis::findOrFail($id);
        $newStatus = $request->status;
        $previousStatus = $thesis->status;

        Thesis::withoutSyncingToSearch(fn () => $thesis->update(['status' => $newStatus]));
        $this->syncSearchable($thesis);

        $actionLabel = match ($newStatus) {
            'archived'   => 'archived',
            'restricted' => 'restricted',
            'active'     => $previousStatus === 'archived' ? 'restored' : 'unrestricted',
        };

        AuditLog::create([
            'user_id'     => $request->user()->id,
            'action'      => 'update_thesis_status',
            'target_type' => 'thesis',
            'target_id'   => $thesis->id,
            'description' => "{$request->user()->name} {$actionLabel} thesis: {$thesis->title}",
            'ip_address'  => $request->ip(),
        ]);

        return response()->json($thesis);
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

        // withoutSyncingToSearch prevents a Meilisearch connection attempt
        // (cURL error 7) from happening inline with the DB delete;
        // syncUnsearchable() below retries it safely afterward.
        Thesis::withoutSyncingToSearch(fn () => $thesis->delete());
        $this->syncUnsearchable($thesis);

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