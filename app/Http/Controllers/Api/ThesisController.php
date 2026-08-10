<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\ReadingHistory;
use App\Models\SignedUrlToken;
use App\Models\Thesis;
use App\Models\ThesisFileVersion;
use App\Support\PdfNormalizer;
use App\Support\ThesisFilePurger;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use App\Jobs\ProcessThesisOcr;
use Illuminate\Support\Facades\DB;
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
            // Browse/Collection Management sort by and display this — a
            // denormalized counter column was never added, so this counts
            // reading_history rows live instead (same source show() already
            // uses for its own view_count).
            ->withCount(['readingHistory as views_count'])
            ->when($request->status,
                fn($q) => $q->where('status', $request->status),
                fn($q) => $q->whereIn('status', $defaultStatuses))
            ->when($request->category_id, fn($q) =>
                $q->where('category_id', $request->category_id))
            ->when($request->year_published, fn($q) =>
                $q->where('year_published', $request->year_published))
            ->when($request->author, fn($q) =>
                $q->where('authors', 'LIKE', "%{$request->author}%"))
            // Collection Management's search bar ("Search by title or
            // author…") sends q — this was never read at all, so the
            // search box silently did nothing regardless of what was typed.
            ->when($request->q, fn($q) =>
                $q->where(function ($sub) use ($request) {
                    $sub->where('title', 'LIKE', "%{$request->q}%")
                        ->orWhere('authors', 'LIKE', "%{$request->q}%");
                }))
            // Recently-added window. The dashboard's "New in 30 days" tile wants
            // a count, not a list, so it asks with per_page=1 and reads the
            // paginator's `total` — which means the number automatically obeys
            // the visibility rules above instead of being computed separately
            // and drifting out of sync with them. Clamped so a hand-crafted
            // ?added_within_days=999999 can't turn into a full-table scan.
            ->when(is_numeric($request->added_within_days), fn($q) =>
                $q->where('created_at', '>=',
                    now()->subDays(max(1, min((int) $request->added_within_days, 365)))))
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

        // Record reading history — but only one row per "reading session"
        // rather than one per open. Without this, refreshing/reopening the
        // viewer repeatedly (very easy to do by accident) inflated both this
        // user's Reading History list and the thesis's public view_count
        // with duplicate entries for what's really a single sitting.
        $recentView = ReadingHistory::where('user_id', $request->user()->id)
            ->where('thesis_id', $thesis->id)
            ->where('viewed_at', '>=', now()->subMinutes(30))
            ->first();

        if ($recentView) {
            $recentView->update(['viewed_at' => now()]);
        } else {
            ReadingHistory::create([
                'user_id'   => $request->user()->id,
                'thesis_id' => $thesis->id,
                'viewed_at' => now(),
            ]);
        }

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

        // Watermarking imports every page via FPDI and builds the whole output
        // as an in-memory string, so it needs roughly 2x the file size in RAM.
        // Measured: a 135MB / 240-page scan peaks at ~290MB and hard-OOMs under
        // ~300MB. With PDFs allowed up to 150MB, that means ~320MB is needed —
        // but PHP's *default* memory_limit is 128M, which would 500 on any
        // large thesis. Raise it for this request only, rather than depending
        // on the server's php.ini being tuned. Never lowers an already-higher
        // limit, and leaves an unlimited (-1) limit alone.
        $this->ensureMemoryLimitAtLeast(512 * 1024 * 1024);

        $watermarked = (new \App\Services\WatermarkService())->stamp($path);

        return response($watermarked, 200, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => 'inline; filename="' . $thesis->title . '.pdf"',
            'Cache-Control'       => 'no-store, no-cache',
            'Content-Length'      => strlen($watermarked),
        ]);
    }

    // Raises PHP's memory_limit for the current request if it's below what the
    // caller needs. Only ever raises — an already-larger limit, or an unlimited
    // (-1) one, is left untouched.
    private function ensureMemoryLimitAtLeast(int $bytes): void
    {
        $current = trim((string) ini_get('memory_limit'));

        if ($current === '' || $current === '-1') {
            return; // unlimited (or unreadable) — nothing to raise
        }

        $unit  = strtolower(substr($current, -1));
        $value = (int) $current;
        $currentBytes = match ($unit) {
            'g'     => $value * 1024 * 1024 * 1024,
            'm'     => $value * 1024 * 1024,
            'k'     => $value * 1024,
            default => $value,
        };

        if ($currentBytes < $bytes) {
            @ini_set('memory_limit', (int) ceil($bytes / 1048576) . 'M');
        }
    }

    // Staff and above — upload new thesis
    public function store(Request $request)
    {
        $request->validate([
            'title'          => 'required|string',
            'authors'        => 'required|string',
            'adviser'        => 'required|string',
            // Left blank, either field is auto-filled from the uploaded
            // PDF's own Abstract/Keywords section by ProcessThesisOcr —
            // see its handle() for the fill-only-if-empty logic.
            'abstract'       => 'nullable|string',
            'keywords'       => 'nullable|string',
            'year_published' => 'required|digits:4|integer',
            'category_id'    => 'required|exists:categories,id',
            'pages'          => 'nullable|integer',
            'cover_image'    => 'nullable|image|max:2048',
            'pdf_file'       => 'required|mimes:pdf|max:153600',
        ]);

        // Store PDF
        $pdfPath = $request->file('pdf_file')
            ->store('theses', 'local');

        // Rewrite into an FPDI-compatible structure if needed (see
        // PdfNormalizer) — best-effort, never blocks the upload if qpdf
        // isn't installed on this machine yet.
        PdfNormalizer::normalize(Storage::disk('local')->path($pdfPath));

        // Fixity: record a SHA-256 of the stored file so silent
        // corruption/tampering can be detected later (theses:verify-checksums).
        $checksum = hash_file('sha256', Storage::disk('local')->path($pdfPath));

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
            // abstract is a NOT NULL column — '' (not null) when left
            // blank, which ProcessThesisOcr's `?:` fallback treats the same
            // as empty either way once OCR fills it in.
            'abstract'        => $request->abstract ?? '',
            'keywords'        => $request->keywords,
            'year_published'  => $request->year_published,
            'category_id'     => $request->category_id,
            'pages'           => $request->pages,
            'file_path'       => $pdfPath,
            'checksum'        => $checksum,
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
            // nullable so a cleared abstract (arrives as null via
            // ConvertEmptyStringsToNull) passes validation — it's coerced back
            // to '' below since the column is NOT NULL.
            'abstract'       => 'sometimes|nullable|string',
            'keywords'       => 'nullable|string',
            'year_published' => 'sometimes|digits:4|integer',
            'category_id'    => 'sometimes|exists:categories,id',
            'pages'          => 'nullable|integer',
            'status'         => 'sometimes|in:active,archived,restricted',
        ]);

        $data = $request->only([
            'title', 'authors', 'adviser', 'abstract',
            'keywords', 'year_published', 'category_id',
            'pages', 'status',
        ]);
        // abstract is NOT NULL — clearing it in the UI sends '' which the
        // ConvertEmptyStringsToNull middleware turns into null; store '' so the
        // "Clear stale value" review action (and a manually-emptied textarea)
        // don't trip the DB constraint.
        if (array_key_exists('abstract', $data) && $data['abstract'] === null) {
            $data['abstract'] = '';
        }

        Thesis::withoutSyncingToSearch(fn () => $thesis->update($data));

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

    // Staff and above — replace the underlying PDF (wrong file uploaded, or
    // a better scan becomes available later). The previous file isn't
    // deleted immediately — it's archived as a restorable ThesisFileVersion
    // for config('thesis.old_file_retention_days') days (see
    // ThesisFilePurger), so a mistaken replace can still be undone.
    public function replaceFile(Request $request, $id)
    {
        $thesis = Thesis::findOrFail($id);

        $request->validate([
            'pdf_file' => 'required|mimes:pdf|max:153600',
        ]);

        $oldPath = $thesis->file_path;
        $newPath = $request->file('pdf_file')->store('theses', 'local');

        // Rewrite into an FPDI-compatible structure if needed (see
        // PdfNormalizer) — best-effort, never blocks the replace if qpdf
        // isn't installed on this machine yet.
        PdfNormalizer::normalize(Storage::disk('local')->path($newPath));

        // Fixity hash of the new file (see store()).
        $newChecksum = hash_file('sha256', Storage::disk('local')->path($newPath));

        DB::transaction(function () use ($thesis, $oldPath, $newPath, $newChecksum, $request) {
            ThesisFileVersion::create([
                'thesis_id'   => $thesis->id,
                'file_path'   => $oldPath,
                'replaced_by' => $request->user()->id,
                'replaced_at' => now(),
                'purge_after' => now()->addDays(config('thesis.old_file_retention_days')),
                'status'      => 'pending',
            ]);

            // withoutSyncingToSearch: swapping the file doesn't change any
            // searchable field (title/abstract/keywords/status), so there's
            // nothing to re-push — and without this, a down Meilisearch would
            // throw inside the transaction and 500 the whole replace. If the
            // OCR re-dispatch below does fill abstract/keywords, that job runs
            // its own best-effort sync.
            Thesis::withoutSyncingToSearch(fn () => $thesis->update([
                'file_path' => $newPath,
                'checksum'  => $newChecksum,
            ]));
        });

        AuditLog::create([
            'user_id'     => $request->user()->id,
            'action'      => 'replace_thesis_file',
            'target_type' => 'thesis',
            'target_id'   => $thesis->id,
            'description' => "{$request->user()->name} replaced the file for thesis: {$thesis->title}",
            'ip_address'  => $request->ip(),
        ]);

        // Run OCR on the NEW file once, up front. It does double duty:
        //  (a) auto-fills any field the staff left blank (same convenience a
        //      fresh upload gets — nothing to lose when a field is empty), and
        //  (b) is returned to the caller as `detected` so the edit page can
        //      warn when the new file's content differs from metadata still on
        //      the record (e.g. the previous file's abstract lingering after a
        //      replace, or a new file with no detectable abstract at all).
        // A non-blank field is never overwritten here — refreshing stale
        // metadata is a human review decision (see extractMetadata()).
        // Citations need nothing similar: they derive from title/authors/year,
        // never the file, so a swap can't make them stale.
        $ocr = app(\App\Services\OcrService::class);
        $extracted = $ocr->extract($thesis->file_path);
        $detected = [
            'abstract' => $extracted['abstract'] ?? '',
            'keywords' => $ocr->cleanKeywords($extracted['keywords'] ?? ''),
            'method'   => $extracted['method'] ?? 'unknown',
        ];

        Thesis::withoutSyncingToSearch(fn () => $thesis->update([
            'abstract' => $thesis->abstract ?: $detected['abstract'],
            'keywords' => $thesis->keywords ?: $detected['keywords'],
        ]));

        ThesisFilePurger::sweepIfDue();

        return response()->json([
            'thesis'   => $thesis->fresh(),
            'detected' => $detected,
        ]);
    }

    // Staff and above — re-run OCR against the thesis's CURRENT file and
    // return what it detects WITHOUT saving anything. Powers the edit page's
    // "Re-extract from current file" review: staff see what the active file
    // actually contains and choose, per field, whether to apply it — so a
    // stale abstract/keywords left over from a previously-replaced file can be
    // refreshed under human review instead of being silently overwritten.
    public function extractMetadata(Request $request, $id)
    {
        $thesis = Thesis::findOrFail($id);

        $ocr = app(\App\Services\OcrService::class);
        $extracted = $ocr->extract($thesis->file_path);

        return response()->json([
            'abstract' => $extracted['abstract'] ?? '',
            'keywords' => $ocr->cleanKeywords($extracted['keywords'] ?? ''),
            'method'   => $extracted['method'] ?? 'unknown',
        ]);
    }

    // Staff and above — list restorable file versions for a thesis, most
    // recently replaced first, alongside the currently-active file. Each row
    // carries the file's byte size: a random hashed storage name tells staff
    // nothing, but size (plus the Preview endpoint below) lets them actually
    // tell which file is which when deciding what to restore.
    public function listFileVersions($id)
    {
        $thesis = Thesis::findOrFail($id);
        ThesisFilePurger::sweepIfDue();

        $versions = ThesisFileVersion::where('thesis_id', $id)
            ->with('replacer:id,name')
            ->orderByDesc('replaced_at')
            ->get()
            ->map(function ($v) {
                // null size = the underlying file is gone (a purged version) —
                // the frontend uses this to hide Preview for those rows.
                $v->size = Storage::disk('local')->exists($v->file_path)
                    ? Storage::disk('local')->size($v->file_path)
                    : null;
                return $v;
            });

        return response()->json([
            'current' => [
                'size' => Storage::disk('local')->exists($thesis->file_path)
                    ? Storage::disk('local')->size($thesis->file_path)
                    : null,
            ],
            'versions' => $versions,
        ]);
    }

    // Staff and above — open the CURRENT active file inline so it can be
    // compared against archived versions before restoring. Raw (un-watermarked),
    // same as the staff download() — this is internal verification, not the
    // reader-facing secure viewer.
    public function previewFile(Request $request, $id)
    {
        $thesis = Thesis::findOrFail($id);
        return $this->streamPdfInline($thesis->file_path, $thesis->title);
    }

    // Staff and above — open a specific archived version inline, same purpose.
    public function previewFileVersion(Request $request, $id, $versionId)
    {
        $version = ThesisFileVersion::where('thesis_id', $id)->findOrFail($versionId);
        return $this->streamPdfInline($version->file_path, 'version-' . $version->id);
    }

    private function streamPdfInline(?string $path, string $label)
    {
        if (! $path || ! Storage::disk('local')->exists($path)) {
            return response()->json(['message' => 'File not found.'], 404);
        }

        return response()->file(Storage::disk('local')->path($path), [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => 'inline; filename="' . $label . '.pdf"',
            'Cache-Control'       => 'no-store, no-cache',
        ]);
    }

    // Staff and above — promote a superseded version back to being the
    // active file. The file that's active right now (about to be displaced)
    // is archived the same way a normal replace would archive it — restoring
    // never destroys data either, it's just another swap.
    public function restoreFileVersion(Request $request, $id, $versionId)
    {
        $thesis  = Thesis::findOrFail($id);
        $version = ThesisFileVersion::where('thesis_id', $id)
            ->where('status', 'pending')
            ->findOrFail($versionId);

        $currentPath = $thesis->file_path;
        // Fixity hash of the file being restored (becomes the active file).
        $restoredChecksum = Storage::disk('local')->exists($version->file_path)
            ? hash_file('sha256', Storage::disk('local')->path($version->file_path))
            : null;

        DB::transaction(function () use ($thesis, $version, $currentPath, $restoredChecksum, $request) {
            ThesisFileVersion::create([
                'thesis_id'   => $thesis->id,
                'file_path'   => $currentPath,
                'replaced_by' => $request->user()->id,
                'replaced_at' => now(),
                'purge_after' => now()->addDays(config('thesis.old_file_retention_days')),
                'status'      => 'pending',
            ]);

            // Same reasoning as replaceFile(): only file_path changes, no
            // searchable field, so skip the Scout sync (and don't let a down
            // Meilisearch 500 the restore).
            Thesis::withoutSyncingToSearch(fn () => $thesis->update([
                'file_path' => $version->file_path,
                'checksum'  => $restoredChecksum,
            ]));
            $version->update(['status' => 'restored']);
        });

        AuditLog::create([
            'user_id'     => $request->user()->id,
            'action'      => 'restore_thesis_file',
            'target_type' => 'thesis',
            'target_id'   => $thesis->id,
            'description' => "{$request->user()->name} restored a previous file version for thesis: {$thesis->title}",
            'ip_address'  => $request->ip(),
        ]);

        return response()->json($thesis->fresh());
    }

    // Staff and above — permanently delete a superseded version before its
    // grace period naturally expires ("Delete Now").
    public function deleteFileVersion(Request $request, $id, $versionId)
    {
        $version = ThesisFileVersion::where('thesis_id', $id)
            ->where('status', 'pending')
            ->findOrFail($versionId);

        Storage::disk('local')->delete($version->file_path);
        $version->update(['status' => 'purged', 'purged_at' => now()]);

        AuditLog::create([
            'user_id'     => $request->user()->id,
            'action'      => 'purge_thesis_file_version',
            'target_type' => 'thesis',
            'target_id'   => $id,
            'description' => "{$request->user()->name} permanently deleted a superseded file version (thesis #{$id})",
            'ip_address'  => $request->ip(),
        ]);

        return response()->json(['message' => 'File version permanently deleted.']);
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