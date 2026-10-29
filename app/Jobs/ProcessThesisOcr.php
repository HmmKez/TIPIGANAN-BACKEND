<?php

namespace App\Jobs;

use App\Models\Thesis;
use App\Services\OcrService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProcessThesisOcr implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Ceiling on one document, not a target.
     *
     * A real 36-page scanned thesis measured 4m41s - 281 of the 300 seconds
     * this used to allow. Nothing enforced that while OCR ran inside the upload
     * request (the sync queue ignores timeouts), so the near-miss was invisible;
     * on a worker it would have killed a document only slightly longer. 15
     * minutes leaves genuine room, and a document that really is stuck still
     * gets cut off rather than holding a worker forever.
     */
    public int $timeout = 900;

    /**
     * No automatic retry. A failed OCR pass has usually already spent minutes
     * on a document that cannot be read; running it again costs the same again
     * and almost always fails the same way. The record is marked failed instead,
     * and staff can re-run it themselves from the edit page.
     */
    public int $tries = 1;

    public function __construct(public Thesis $thesis) {}

    public function handle(OcrService $ocr): void
    {
        Log::info("OCR: Starting extraction for thesis ID {$this->thesis->id}");

        $this->markStatus('processing');

        $extracted = $ocr->extract($this->thesis->file_path);
        $cleanedKeywords = $ocr->cleanKeywords($extracted['keywords'] ?? '');

        // Only fill in what the staff member left blank — same rule for
        // both fields now. Previously keywords were unconditionally merged
        // with whatever OCR found on *every* upload, even when the staff
        // had already typed a real list, which is how earlier test uploads
        // ended up with long/garbled keywords in the first place.
        // The year follows the same rule: filled only when staff left it blank.
        Thesis::withoutSyncingToSearch(function () use ($cleanedKeywords, $extracted) {
            $this->thesis->update([
                'keywords' => $this->thesis->keywords ?: $cleanedKeywords,
                'abstract' => $this->thesis->abstract ?: $extracted['abstract'],
                'year'     => $this->thesis->year ?: ($extracted['year'] ?? null),
            ]);
        });

        try {
            $this->thesis->searchable();
        } catch (Throwable $e) {
            Log::warning("OCR: Meilisearch sync failed for thesis ID {$this->thesis->id}: " . $e->getMessage());
        }

        // 'done' means the reading finished, not that anything was found. A
        // scan too poor to read leaves the abstract empty and is still a
        // completed job - the alternative, calling it a failure, would push
        // staff to re-run something that will never produce more.
        $this->markStatus('done');

        Log::info("OCR: Completed for thesis ID {$this->thesis->id} using method: {$extracted['method']}");
    }

    public function failed(\Throwable $e): void
    {
        Log::error("OCR job failed for thesis ID {$this->thesis->id}: " . $e->getMessage());

        // Runs when the job errors or exceeds its time limit. Without this the
        // record would sit on 'processing' forever and the upload screen would
        // keep telling staff to wait for something that already died.
        $this->markStatus('failed');
    }

    /**
     * Record progress without disturbing the thesis itself.
     *
     * Deliberately a direct query rather than $thesis->update(): the model in
     * this job is a snapshot taken when the upload happened, and saving it
     * whole could write stale title/abstract values over edits a staff member
     * made in the minutes the OCR was running. This touches two columns and
     * nothing else, and skips the search index, which has no interest in them.
     */
    private function markStatus(string $status): void
    {
        Thesis::withoutSyncingToSearch(fn () => Thesis::withTrashed()
            ->whereKey($this->thesis->id)
            ->update([
                'ocr_status'       => $status,
                'ocr_completed_at' => in_array($status, ['done', 'failed'], true) ? now() : null,
            ]));
    }
}