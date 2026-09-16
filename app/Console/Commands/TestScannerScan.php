<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Drives a real scan on an eSCL scanner and saves what comes back.
 *
 * This is the proof that the whole "scan straight into TIPIGANAN" idea works
 * against the library's actual hardware. scanner:discover only asks the device
 * what it claims it can do; this makes it actually do it, which is a different
 * question - plenty of scanners advertise PDF and then return something else,
 * or refuse a job from anything but the vendor's own software.
 *
 * Deliberately a standalone command rather than part of the upload flow: the
 * point is to answer "will this work here at all?" in a single visit to the
 * library, before any of the UI around it is built.
 *
 *   php artisan scanner:scan --host=192.168.1.50
 *   php artisan scanner:scan --host=192.168.1.50 --source=adf --resolution=200
 */
class TestScannerScan extends Command
{
    protected $signature = 'scanner:scan
                            {--host= : Scanner IP or hostname (from scanner:discover)}
                            {--source=platen : platen (glass) or adf (document feeder)}
                            {--resolution=300 : Dots per inch}
                            {--format=pdf : pdf or jpeg}
                            {--out= : Where to save (default: storage/app/scans)}
                            {--timeout=180 : Seconds to wait for the scan itself}';

    protected $description = 'Perform a real scan on an eSCL scanner and save the result';

    /** eSCL speaks two XML namespaces; both are needed in the job request. */
    private const NS_PWG  = 'http://www.pwg.org/schemas/2010/12/sm';
    private const NS_SCAN = 'http://schemas.hp.com/imaging/escl/2011/05/03';

    public function handle(): int
    {
        $host = $this->option('host');

        if (! $host) {
            $this->error('Which scanner? Pass --host=<ip>.');
            $this->line('Find it with: php artisan scanner:discover');

            return self::FAILURE;
        }

        $base    = (str_starts_with($host, 'http') ? rtrim($host, '/') : "http://{$host}").'/eSCL';
        $timeout = (int) $this->option('timeout');

        // 1. Is it there, and is it free? -----------------------------------
        $this->line("Checking scanner at {$base} ...");

        try {
            $status = $this->client(10)->get("{$base}/ScannerStatus");
        } catch (Throwable $e) {
            $this->error('Could not reach the scanner: '.$e->getMessage());
            $this->line('Is this machine on the same network as the scanner?');

            return self::FAILURE;
        }

        if (! $status->successful()) {
            $this->error("Scanner answered HTTP {$status->status()} - it may not support eSCL.");

            return self::FAILURE;
        }

        $state = $this->tagValue($status->body(), 'State') ?? 'Unknown';
        $this->line("  Scanner state: {$state}");

        if (strcasecmp($state, 'Processing') === 0) {
            $this->warn('  The scanner is busy with another job. Wait for it to finish.');
        }

        // 2. Ask it to scan --------------------------------------------------
        $this->newLine();
        $this->info('Starting the scan...');

        if ($this->option('source') === 'adf') {
            $this->line('  Using the document feeder - put the pages in the tray.');
        } else {
            $this->line('  Using the glass - put ONE page face down and close the lid.');
        }

        try {
            $job = $this->client(30)
                ->withBody($this->scanSettingsXml(), 'text/xml')
                ->post("{$base}/ScanJobs");
        } catch (Throwable $e) {
            $this->error('The scan request failed: '.$e->getMessage());

            return self::FAILURE;
        }

        // eSCL answers 201 Created and puts the job's address in Location.
        if ($job->status() !== 201) {
            $this->error("The scanner refused the job (HTTP {$job->status()}).");
            $this->line('Common causes: the requested format or resolution is not supported,');
            $this->line('or the feeder is empty when --source=adf was asked for.');
            $this->line(trim(substr($job->body(), 0, 400)));

            return self::FAILURE;
        }

        $jobUrl = $job->header('Location');

        if (! $jobUrl) {
            $this->error('The scanner accepted the job but did not say where to collect it.');

            return self::FAILURE;
        }

        // Some firmware returns an unreachable internal hostname in Location;
        // keep the path but talk to the address we know works.
        $jobPath = parse_url($jobUrl, PHP_URL_PATH);
        $jobUrl  = preg_replace('#/eSCL$#', '', $base).$jobPath;

        $this->line("  Job accepted: {$jobPath}");

        // 3. Collect the pages -----------------------------------------------
        $this->newLine();
        $this->info('Waiting for the scanner (this is the slow part)...');

        $outDir = $this->option('out') ?: storage_path('app/scans');
        if (! is_dir($outDir)) {
            mkdir($outDir, 0777, true);
        }

        $ext     = $this->option('format') === 'jpeg' ? 'jpg' : 'pdf';
        $saved   = [];
        $page    = 0;
        $started = time();

        while (true) {
            if (time() - $started > $timeout) {
                $this->error("Gave up after {$timeout}s.");
                break;
            }

            try {
                $doc = $this->client($timeout)->get("{$jobUrl}/NextDocument");
            } catch (Throwable $e) {
                $this->error('Failed while collecting the document: '.$e->getMessage());
                break;
            }

            // 404 is the documented way of saying "that was the last page".
            if ($doc->status() === 404) {
                break;
            }

            // 503 means "not ready yet" - the scanner is still warming up or
            // pulling the sheet through. Wait rather than treating it as failure.
            if ($doc->status() === 503) {
                usleep(700_000);
                continue;
            }

            if (! $doc->successful()) {
                $this->error("Unexpected response while collecting: HTTP {$doc->status()}");
                break;
            }

            $body = $doc->body();

            if ($body === '') {
                break;
            }

            $page++;
            $path = rtrim($outDir, '/\\').DIRECTORY_SEPARATOR.'scan-'.date('Ymd-His')."-p{$page}.{$ext}";
            file_put_contents($path, $body);
            $saved[] = $path;

            $this->line(sprintf('  Received page %d - %s KB', $page, number_format(strlen($body) / 1024, 1)));

            // The glass holds one sheet, so there is never a second document;
            // asking again just makes the scanner wait for a timeout.
            if ($this->option('source') !== 'adf') {
                break;
            }
        }

        // 4. Report -----------------------------------------------------------
        $this->newLine();

        if (empty($saved)) {
            $this->error('Nothing was scanned.');
            $this->line('If the scanner made noise but produced no file, the format may be');
            $this->line('unsupported - try: --format=jpeg');

            return self::FAILURE;
        }

        $this->info('SCAN SUCCEEDED');

        foreach ($saved as $path) {
            $isPdf = str_starts_with((string) file_get_contents($path, false, null, 0, 5), '%PDF-');
            $this->line('  '.$path);
            $this->line('    '.($isPdf
                ? '<fg=green>Genuine PDF</> - this can go straight into the upload flow'
                : '<fg=yellow>Not a PDF</> - the server would have to convert it first'));
        }

        $this->newLine();
        $this->line('<options=bold>Open the file and check it is readable.</> If it is, the library scanner');
        $this->line('can be driven directly by TIPIGANAN and the Scan Inbox feature is viable.');

        return self::SUCCESS;
    }

    private function client(int $timeout)
    {
        return Http::timeout($timeout)
            ->withoutVerifying()   // scanners universally self-sign
            ->withHeaders(['User-Agent' => 'TIPIGANAN-scan-test/1.0']);
    }

    /**
     * The scan job request.
     *
     * Region is expressed in 1/300-inch units regardless of the scan resolution,
     * which is an easy thing to get wrong: 2480 x 3508 is A4, not a pixel count.
     * Both DocumentFormat spellings are sent because vendors disagree about
     * which one is current, and sending only the newer one makes older firmware
     * silently fall back to JPEG.
     */
    private function scanSettingsXml(): string
    {
        $dpi    = (int) $this->option('resolution');
        $source = $this->option('source') === 'adf' ? 'Feeder' : 'Platen';
        $mime   = $this->option('format') === 'jpeg' ? 'image/jpeg' : 'application/pdf';

        $pwg  = self::NS_PWG;
        $scan = self::NS_SCAN;

        return <<<XML
        <?xml version="1.0" encoding="UTF-8"?>
        <scan:ScanSettings xmlns:pwg="{$pwg}" xmlns:scan="{$scan}">
          <pwg:Version>2.63</pwg:Version>
          <pwg:ScanRegions>
            <pwg:ScanRegion>
              <pwg:XOffset>0</pwg:XOffset>
              <pwg:YOffset>0</pwg:YOffset>
              <pwg:Width>2480</pwg:Width>
              <pwg:Height>3508</pwg:Height>
              <pwg:ContentRegionUnits>escl:ThreeHundredthsOfInches</pwg:ContentRegionUnits>
            </pwg:ScanRegion>
          </pwg:ScanRegions>
          <pwg:InputSource>{$source}</pwg:InputSource>
          <pwg:DocumentFormat>{$mime}</pwg:DocumentFormat>
          <scan:DocumentFormatExt>{$mime}</scan:DocumentFormatExt>
          <scan:ColorMode>RGB24</scan:ColorMode>
          <scan:XResolution>{$dpi}</scan:XResolution>
          <scan:YResolution>{$dpi}</scan:YResolution>
        </scan:ScanSettings>
        XML;
    }

    /** Pull one tag's text out of an XML document without caring about prefixes. */
    private function tagValue(string $xml, string $tag): ?string
    {
        return preg_match('#<(?:\w+:)?'.preg_quote($tag, '#').'>([^<]*)<#i', $xml, $m)
            ? trim($m[1])
            : null;
    }
}
