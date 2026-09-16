<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Finds network scanners that speak eSCL (Apple's AirScan, a.k.a. "driverless
 * scanning") on the local network, and reports whether each one can do what
 * TIPIGANAN needs: return a PDF, ideally from a document feeder.
 *
 * Why this exists: a browser cannot talk to a scanner - the web platform
 * exposes a camera and a microphone, and nothing else. But eSCL is plain HTTP,
 * so the SERVER can drive the scanner directly with no driver and no installed
 * helper program. That only holds if the scanner actually supports eSCL, which
 * varies by model, so this command answers that question before any feature is
 * built on top of it.
 *
 * Discovery sweeps the local /24 for the eSCL endpoint rather than using
 * mDNS/Bonjour, deliberately: mDNS needs either the raw sockets extension (not
 * enabled in this PHP build) or Bonjour's dns-sd.exe (absent on a stock Windows
 * machine), while an HTTP probe needs only cURL and behaves identically
 * everywhere.
 */
class DiscoverScanners extends Command
{
    protected $signature = 'scanner:discover
                            {--host=* : Probe these IPs/hostnames only, skipping the sweep}
                            {--subnet= : Network to sweep, e.g. 192.168.1 (default: this machine subnet)}
                            {--timeout=2 : Seconds to wait for each device}';

    protected $description = 'Find eSCL/AirScan-capable network scanners and report their capabilities';

    private const CAPABILITIES_PATH = '/eSCL/ScannerCapabilities';

    public function handle(): int
    {
        $timeout = max(1, (int) $this->option('timeout'));
        $targets = $this->option('host') ?: $this->sweepTargets();

        if (empty($targets)) {
            $this->error('Could not work out which network to search.');
            $this->line('Pass one explicitly: --subnet=192.168.1  or  --host=<scanner ip>');

            return self::FAILURE;
        }

        $this->info(sprintf('Probing %d address(es) for eSCL scanners (timeout %ds)...', count($targets), $timeout));

        $found = $this->probeAll($targets, $timeout);

        $this->newLine(2);

        if (empty($found)) {
            $this->warn('No eSCL scanners answered.');
            $this->newLine();
            $this->line('That does NOT prove the scanner cannot do this. Check, in order:');
            $this->line('  1. Is this machine on the same network/VLAN as the scanner?');
            $this->line('  2. Is the scanner switched on and awake? (they sleep deeply)');
            $this->line('  3. Read its IP off its own control panel, then probe it directly:');
            $this->line('     php artisan scanner:discover --host=<ip>');
            $this->line('  4. Many models ship with AirPrint/AirScan or "Mopria" turned OFF');
            $this->line('     in their web admin page. Turning it on is what enables eSCL.');

            return self::SUCCESS;
        }

        foreach ($found as $scanner) {
            $this->reportScanner($scanner);
        }

        return self::SUCCESS;
    }

    /**
     * Build the list of addresses to sweep. Finds this machine's own LAN
     * address by asking the OS which interface it would use to reach the
     * internet - no packet is actually sent, a UDP "connect" is purely local.
     */
    private function sweepTargets(): array
    {
        $base = $this->option('subnet');

        if (! $base) {
            $sock = @stream_socket_client('udp://8.8.8.8:53', $errno, $errstr, 1);
            if (! $sock) {
                return [];
            }
            $local = stream_socket_get_name($sock, false);
            fclose($sock);

            // stream_socket_get_name returns "ip:port"; strip the port.
            $ip = strstr((string) $local, ':', true) ?: (string) $local;
            if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                return [];
            }

            $base = implode('.', array_slice(explode('.', $ip), 0, 3));
            $this->line("  This machine is {$ip} - sweeping {$base}.0/24");
        }

        return array_map(fn ($i) => "{$base}.{$i}", range(1, 254));
    }

    /**
     * Probe every address in parallel. Sequentially, 254 hosts at a 2s timeout
     * would take over eight minutes; curl_multi collapses that to roughly one
     * timeout in total.
     */
    private function probeAll(array $targets, int $timeout): array
    {
        $multi   = curl_multi_init();
        $handles = [];

        foreach ($targets as $target) {
            $url = $this->normalizeUrl($target) . self::CAPABILITIES_PATH;
            $ch  = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => $timeout,
                CURLOPT_TIMEOUT        => $timeout + 2,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS      => 3,
                // Scanners self-sign their TLS certificates as a matter of
                // course; rejecting those would rule out every HTTPS-only model.
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => 0,
                CURLOPT_USERAGENT      => 'TIPIGANAN-scanner-probe/1.0',
            ]);
            curl_multi_add_handle($multi, $ch);
            $handles[(int) $ch] = ['handle' => $ch, 'url' => $url, 'target' => $target];
        }

        $bar = $this->output->createProgressBar(count($handles));
        $bar->start();

        do {
            $status = curl_multi_exec($multi, $running);
            if ($running) {
                curl_multi_select($multi, 0.5);
            }
            while ($info = curl_multi_info_read($multi)) {
                $bar->advance();
                $id = (int) $info['handle'];
                $handles[$id]['body'] = $info['result'] === CURLE_OK
                    ? curl_multi_getcontent($info['handle'])
                    : null;
                $handles[$id]['code'] = curl_getinfo($info['handle'], CURLINFO_HTTP_CODE);
            }
        } while ($running && $status === CURLM_OK);

        $bar->finish();

        $found = [];
        foreach ($handles as $h) {
            curl_multi_remove_handle($multi, $h['handle']);
            curl_close($h['handle']);

            $body = $h['body'] ?? null;
            // The body has to actually look like eSCL capabilities: plenty of
            // devices cheerfully answer 200 with a login page for any path.
            if (($h['code'] ?? 0) === 200 && $body && str_contains($body, 'ScannerCapabilities')) {
                $found[$h['target']] = ['url' => $h['url'], 'target' => $h['target'], 'xml' => $body];
            }
        }

        curl_multi_close($multi);

        return array_values($found);
    }

    private function normalizeUrl(string $target): string
    {
        return str_starts_with($target, 'http') ? rtrim($target, '/') : "http://{$target}";
    }

    /** Pull out the few facts that decide whether this scanner is usable here. */
    private function reportScanner(array $scanner): void
    {
        $this->line("<fg=green;options=bold>FOUND</> {$scanner['target']}  <fg=gray>({$scanner['url']})</>");

        $xml = @simplexml_load_string($scanner['xml']);
        if (! $xml) {
            $this->warn('  Responded, but its capabilities XML could not be parsed.');

            return;
        }

        // eSCL splits its vocabulary across two namespaces: pwg (the Printer
        // Working Group's shared terms) and scan (HP's eSCL extensions, which
        // every other vendor then adopted).
        $namespaces = $xml->getNamespaces(true);
        $pwg        = $xml->children($namespaces['pwg'] ?? '');

        $model = trim((string) ($pwg->MakeAndModel ?? '')) ?: 'unknown model';

        $raw    = $scanner['xml'];
        $hasPdf = stripos($raw, 'application/pdf') !== false;
        $hasAdf = stripos($raw, 'Adf') !== false;
        $duplex = stripos($raw, 'Duplex') !== false;

        $this->line("  Model:   {$model}");

        $this->line('  PDF:     ' . ($hasPdf
            ? '<fg=green>yes</> - returns a PDF directly, so no conversion step at all'
            : '<fg=yellow>no</> - images only; the server would have to assemble the PDF'));

        $this->line('  Feeder:  ' . ($hasAdf
            ? '<fg=green>yes</>' . ($duplex ? ' (double-sided supported)' : ' (single-sided)')
            : '<fg=yellow>no</> - flatbed only, one page at a time'));

        $this->newLine();
        $this->line('  <options=bold>This scanner can be driven directly by the system.</>');
        $this->line("  Note its address: SCANNER_ESCL_URL={$this->normalizeUrl($scanner['target'])}");
        $this->newLine();
    }
}
