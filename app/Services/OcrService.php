<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

class OcrService
{
    /**
     * Extract text sections from a PDF file.
     * Returns array with: title, abstract, introduction, keywords, conclusion, method
     */
    public function extract(string $filePath): array
    {
        $absolutePath = storage_path('app/' . $filePath);

        if (! file_exists($absolutePath)) {
            Log::error("OCR: File not found at {$absolutePath}");
            return $this->emptyResult();
        }

        $scriptPath = base_path('ocr/extract.js');
        $escapedPath = escapeshellarg($absolutePath);

        // Run the Node.js OCR script
        $output = shell_exec("node {$scriptPath} {$escapedPath} 2>&1");

        if (! $output) {
            Log::error("OCR: No output from script for {$filePath}");
            return $this->emptyResult();
        }

        $result = json_decode($output, true);

        if (isset($result['error'])) {
            Log::error("OCR error: " . $result['error']);
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

    /**
     * Combine extracted sections into a single searchable string.
     */
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