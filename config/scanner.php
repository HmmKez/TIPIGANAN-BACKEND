<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Scan inbox
    |--------------------------------------------------------------------------
    |
    | The folder the scanner drops finished PDFs into. Staff scan a thesis at
    | the scanner, and the file appears here for them to attach without ever
    | opening a file browser.
    |
    | The library's scanner is a ScanSnap SV600: USB-only, and it reports a
    | vendor-specific USB class, meaning no generic scanning protocol can reach
    | it - it is driven solely by Fujitsu's ScanSnap Home. So the system never
    | talks to the scanner. It watches a folder, which is also why any number of
    | staff machines can use this even though the scanner has exactly one host.
    |
    | In production this should point at the NAS share that ScanSnap Home writes
    | to, so the scanning station can be switched off without the waiting scans
    | becoming unreachable.
    |
    */

    'inbox' => [

        'enabled' => (bool) env('SCAN_INBOX_ENABLED', true),

        /*
        | Absolute path, or blank to use storage/app/scan-inbox for local
        | development. On Windows use FORWARD SLASHES - a backslash is an escape
        | character to dotenv and will break the entire .env file.
        |
        | A UNC path to the NAS works: //nas-host/scans/inbox
        */
        'path' => env('SCAN_INBOX_PATH') ?: storage_path('app/scan-inbox'),

        /*
        | A file is ignored until it has been untouched for this many seconds.
        |
        | A scanner writing a large PDF produces a file that exists but is not
        | finished, and attaching one mid-write yields a corrupt document. Age
        | is used rather than watching the size change because listing has to
        | stay cheap - the folder is polled every time the page opens.
        */
        'settle_seconds' => (int) env('SCAN_INBOX_SETTLE_SECONDS', 5),

        /*
        | Safety ceiling on how many files are listed at once, so a folder
        | somebody has been dumping into for months cannot stall the page.
        */
        'max_listed' => (int) env('SCAN_INBOX_MAX_LISTED', 100),
    ],

];
