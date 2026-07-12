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
        $cleanedKeywords = $ocr->cleanKeywords($extracted['keywords'] ?? '');

        // Only fill in what the staff member left blank — same rule for
        // both fields now. Previously keywords were unconditionally merged
        // with whatever OCR found on *every* upload, even when the staff
        // had already typed a real list, which is how earlier test uploads
        // ended up with long/garbled keywords in the first place.
        Thesis::withoutSyncingToSearch(function () use ($cleanedKeywords, $extracted) {
            $this->thesis->update([
                'keywords' => $this->thesis->keywords ?: $cleanedKeywords,
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