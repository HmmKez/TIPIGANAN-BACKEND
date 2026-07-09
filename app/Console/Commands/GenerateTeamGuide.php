<?php

namespace App\Console\Commands;

use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Console\Command;

class GenerateTeamGuide extends Command
{
    protected $signature = 'guide:generate {--output= : Full path to write the PDF to}';

    protected $description = 'Generate the TIPIGANAN team guide as a PDF using the app\'s own DomPDF pipeline';

    public function handle(): int
    {
        $pdf = Pdf::loadView('pdf.team_guide', [
            'generatedAt' => now()->format('F Y'),
        ])->setPaper('a4', 'portrait');

        $output = $this->option('output')
            ?? base_path('..' . DIRECTORY_SEPARATOR . 'TIPIGANAN-Team-Guide-Updated.pdf');

        file_put_contents($output, $pdf->output());

        $this->info("Team guide generated: {$output}");

        return self::SUCCESS;
    }
}
