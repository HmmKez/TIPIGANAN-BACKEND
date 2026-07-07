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

    public int $timeout = 300; // 5 minutes max for OCR

    public function __construct(public Thesis $thesis) {}

    public function handle(OcrService $ocr): void
    {
        Log::info("OCR: Starting extraction for thesis ID {$this->thesis->id}");

        $extracted = $ocr->extract($this->thesis->file_path);

        // Merge extracted keywords with manually entered ones
        $existingKeywords = $this->thesis->keywords ?? '';
        $ocrKeywords      = $extracted['keywords'];

        $mergedKeywords = implode(', ', array_unique(array_filter(
            array_merge(
                array_map('trim', explode(',', $existingKeywords)),
                array_map('trim', explode(',', $ocrKeywords))
            )
        )));

        // QUEUE_CONNECTION=sync means this job runs inline with the upload
        // request — an unguarded Meilisearch call here (cURL error 7 when
        // it's offline) would fail the whole request even though OCR and
        // the DB write already succeeded. Same safe pattern as the
        // controller: skip the automatic sync, then retry it best-effort.
        Thesis::withoutSyncingToSearch(function () use ($mergedKeywords, $extracted) {
            $this->thesis->update([
                'keywords' => $mergedKeywords,
                'abstract' => $this->thesis->abstract ?: $extracted['abstract'],
            ]);
        });

        try {
            $this->thesis->searchable();
        } catch (Throwable $e) {
            Log::warning("OCR: Meilisearch sync failed for thesis ID {$this->thesis->id}: " . $e->getMessage());
        }

        Log::info("OCR: Completed for thesis ID {$this->thesis->id} using method: {$extracted['method']}");
    }

    public function failed(\Throwable $e): void
    {
        Log::error("OCR job failed for thesis ID {$this->thesis->id}: " . $e->getMessage());
    }
}