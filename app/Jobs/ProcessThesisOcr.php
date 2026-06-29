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

        // Update the thesis with extracted content
        $this->thesis->update([
            'keywords' => $mergedKeywords,
            'abstract' => $this->thesis->abstract ?: $extracted['abstract'],
        ]);

        // Store the full extracted text for search indexing
        $this->thesis->searchable(); // re-index in Scout

        Log::info("OCR: Completed for thesis ID {$this->thesis->id} using method: {$extracted['method']}");
    }

    public function failed(\Throwable $e): void
    {
        Log::error("OCR job failed for thesis ID {$this->thesis->id}: " . $e->getMessage());
    }
}