<?php

namespace App\Support;

use RuntimeException;

/**
 * The folder the scanner drops finished PDFs into.
 *
 * TIPIGANAN never talks to the scanner itself - the library's ScanSnap SV600 is
 * USB-only and reports a vendor-specific USB class, so no generic scanning
 * protocol can reach it. ScanSnap Home writes a PDF here instead, and this class
 * is the only thing that reads the folder.
 *
 * Everything a request can name is funnelled through resolve(), which is the
 * single place path traversal is stopped. Treat any new method that takes a
 * filename as suspect until it goes through there too.
 */
class ScanInbox
{
    public function enabled(): bool
    {
        return (bool) config('scanner.inbox.enabled');
    }

    public function path(): string
    {
        return rtrim((string) config('scanner.inbox.path'), '/\\');
    }

    public function exists(): bool
    {
        return is_dir($this->path());
    }

    /**
     * Create the folder if it is missing.
     *
     * Only meaningful for the local default - a NAS share that is not mounted
     * must NOT be silently created as an empty local directory, or scans would
     * appear to vanish while actually landing somewhere nobody is watching.
     */
    public function ensureExists(): bool
    {
        return $this->exists() || @mkdir($this->path(), 0777, true);
    }

    /**
     * Files that are ready to be attached, newest first.
     *
     * @return array<int, array{name:string, size:int, scanned_at:string, pages:?int}>
     */
    public function files(): array
    {
        return $this->listing()['files'];
    }

    /**
     * What is waiting, and how much of it is being shown.
     *
     * `total` is every file ready to attach; `files` is the newest of them, up
     * to `max_listed`. They are reported separately because the screen used to
     * count the rows it was sent, so with 150 scans waiting it announced 100.
     *
     * Skips anything written too recently: a scanner producing a large PDF
     * leaves a file that exists but is not finished, and attaching one
     * mid-write yields a corrupt document.
     *
     * The page count - the only expensive part, since it reads up to 2 MB of
     * each file - is worked out AFTER the cap is applied, for the listed files
     * only. It used to be computed for every file in the folder before the cap,
     * so a backlog of 500 scans meant reading up to a gigabyte of PDFs on every
     * page load just to display the first 100.
     *
     * @return array{total:int, files:array<int, array{name:string, size:int, scanned_at:string, pages:?int}>}
     */
    public function listing(): array
    {
        if (! $this->enabled() || ! $this->exists()) {
            return ['total' => 0, 'files' => []];
        }

        $settle = (int) config('scanner.inbox.settle_seconds');
        $cutoff = time() - max(0, $settle);
        $ready  = [];

        // Every entry, filtered by extension here rather than by a '*.pdf'
        // glob. PHP's glob is case-sensitive - verified on Windows as well as
        // Linux, despite the Windows filesystem itself ignoring case - so a
        // scanner that names its files SCAN001.PDF produced an empty inbox
        // with no error, on every machine.
        foreach ((array) glob($this->path().DIRECTORY_SEPARATOR.'*', GLOB_NOSORT) as $absolute) {
            if (! is_file($absolute) || strtolower(pathinfo($absolute, PATHINFO_EXTENSION)) !== 'pdf') {
                continue;
            }

            $modified = @filemtime($absolute) ?: 0;

            if ($modified > $cutoff) {
                continue; // still being written
            }

            // Only cheap facts here: name, size and time come from the
            // directory entry, so this stays fast however full the folder is.
            $ready[] = ['absolute' => $absolute, 'modified' => $modified];
        }

        usort($ready, fn ($a, $b) => $b['modified'] <=> $a['modified']);

        $shown = array_slice($ready, 0, max(1, (int) config('scanner.inbox.max_listed')));

        return [
            'total' => count($ready),
            'files' => array_map(fn ($entry) => [
                'name'       => basename($entry['absolute']),
                'size'       => (int) @filesize($entry['absolute']),
                'scanned_at' => date(DATE_ATOM, $entry['modified']),
                'pages'      => $this->countPages($entry['absolute']),
            ], $shown),
        ];
    }

    /**
     * Turn a filename from a request into a safe absolute path.
     *
     * The name arrives from the browser, so it is hostile until proven
     * otherwise. basename() strips any directory part, and the resolved path is
     * then checked to be inside the inbox - which also catches a symlink
     * pointing somewhere else entirely.
     *
     * @throws RuntimeException when the name does not identify a readable PDF
     *         inside the inbox.
     */
    public function resolve(string $name): string
    {
        if (! $this->enabled()) {
            throw new RuntimeException('The scan inbox is switched off.');
        }

        $name = basename(trim($name));

        if ($name === '' || ! str_ends_with(strtolower($name), '.pdf')) {
            throw new RuntimeException('That is not a scanned PDF.');
        }

        $absolute = realpath($this->path().DIRECTORY_SEPARATOR.$name);
        $root     = realpath($this->path());

        if ($absolute === false || $root === false || ! is_file($absolute)) {
            throw new RuntimeException('That scanned file is no longer in the inbox. It may have already been used.');
        }

        // Compare resolved paths, so a symlink out of the folder is refused
        // even though its name looked ordinary.
        if (! str_starts_with($absolute, $root.DIRECTORY_SEPARATOR)) {
            throw new RuntimeException('That scanned file is not in the inbox.');
        }

        return $absolute;
    }

    /** Remove a file from the inbox once it has been taken into the repository. */
    public function discard(string $name): bool
    {
        try {
            return @unlink($this->resolve($name));
        } catch (RuntimeException) {
            return false;
        }
    }

    /**
     * Page count, read straight from the PDF's own structure.
     *
     * Only the first 2 MB is scanned: it is a convenience shown in a list, not
     * something worth reading a 150 MB file for. Null simply means "unknown",
     * and the UI omits it rather than guessing.
     */
    private function countPages(string $absolute): ?int
    {
        $head = @file_get_contents($absolute, false, null, 0, 2_000_000);

        if ($head === false) {
            return null;
        }

        if (preg_match('/\/Type\s*\/Pages?[^>]*?\/Count\s+(\d+)/s', $head, $m)) {
            return (int) $m[1];
        }

        $pages = preg_match_all('/\/Type\s*\/Page[^s]/', $head);

        return $pages > 0 ? $pages : null;
    }
}
