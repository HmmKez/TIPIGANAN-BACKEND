<?php

namespace App\Console\Commands;

use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Console\Command;

// Renders the complete system-functionality + technology reference as a real
// PDF, using the app's own DomPDF pipeline (same approach as guide:generate).
// This is the document handed in with the capstone write-up.
class GenerateFeatureCatalog extends Command
{
    protected $signature = 'docs:features {--output= : Full path to write the PDF to}';

    protected $description = 'Generate the complete system functionalities & technology reference as a PDF';

    public function handle(): int
    {
        $pdf = Pdf::loadView('pdf.feature_catalog', [
            'generatedAt' => now()->format('F j, Y'),
        ])->setPaper('a4', 'portrait');

        $output = $this->option('output')
            ?? base_path('..' . DIRECTORY_SEPARATOR . 'TIPIGANAN-System-Functionalities.pdf');

        file_put_contents($output, $pdf->output());

        $this->info("System functionalities reference generated: {$output}");

        return self::SUCCESS;
    }
}
