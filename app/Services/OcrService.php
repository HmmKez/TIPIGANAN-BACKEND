<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class OcrService
{
    public function extract(string $filePath): array
    {
        if (! Storage::disk('local')->exists($filePath)) {
            Log::error("OCR: File not found in storage: {$filePath}");
            return $this->emptyResult();
        }

        $absolutePath = Storage::disk('local')->path($filePath);

        Log::info("OCR: Checking resolved path: " . $absolutePath);

        $scriptPath = base_path('ocr/extract.cjs');
        $escapedPath = escapeshellarg($absolutePath);

        $output = shell_exec("node {$scriptPath} {$escapedPath} 2>&1");

        Log::info("OCR: Raw output from Node script: " . $output);

        if (! $output) {
            Log::error("OCR: No output from script for {$filePath}");
            return $this->emptyResult();
        }

        $result = json_decode($output, true);

        if ($result === null) {
            Log::error("OCR: Failed to parse JSON. Raw output was: " . $output);
            return $this->emptyResult();
        }

        return [
            'title'        => $result['title']        ?? '',
            'abstract'     => $result['abstract']      ?? '',
            'introduction' => $result['introduction']  ?? '',
            'keywords'     => $result['keywords']      ?? '',
            'conclusion'   => $result['conclusion']    ?? '',
            'method'       => $result['method']        ?? 'unknown',
        ];
    }

    // Turns raw OCR "keywords" output into a sane short list, or '' if the
    // extraction clearly didn't find a real keyword line. extract.cjs grabs a
    // few lines after a "Keywords" heading — when that match is wrong (or the
    // PDF has no clean list), what comes back is a run of body text, not short
    // comma-separated terms. A genuine list is delimited short terms, so
    // requiring real delimiters + a sane term length filters out the misses.
    public function cleanKeywords(string $raw): string
    {
        $terms = preg_split('/[,;\n]+/', $raw);
        $terms = array_map('trim', $terms);
        $terms = array_filter($terms, fn ($t) => $t !== '' && strlen($t) <= 40);
        $terms = array_slice(array_values(array_unique($terms)), 0, 8);

        // Fewer than 2 terms after splitting on real delimiters means the
        // extracted text almost certainly wasn't a genuine keyword list —
        // safer to return nothing than a single long fragment.
        if (count($terms) < 2) {
            return '';
        }

        return implode(', ', $terms);
    }

    public function toSearchableText(array $extracted): string
    {
        return implode(' ', array_filter([
            $extracted['title'],
            $extracted['abstract'],
            $extracted['introduction'],
            $extracted['keywords'],
            $extracted['conclusion'],
        ]));
    }

    private function emptyResult(): array
    {
        return [
            'title'        => '',
            'abstract'     => '',
            'introduction' => '',
            'keywords'     => '',
            'conclusion'   => '',
            'method'       => 'failed',
        ];
    }
}