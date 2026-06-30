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