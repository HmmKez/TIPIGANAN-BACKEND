<?php

return [

    // How many days a superseded PDF (from a file replace) stays restorable
    // before the automatic purge sweep deletes it for good. Staff can also
    // delete one early via the "Delete Now" action regardless of this window.
    'old_file_retention_days' => (int) env('THESIS_OLD_FILE_RETENTION_DAYS', 30),

    // The qpdf executable used to rewrite uploaded PDFs into an
    // FPDI-readable structure (see App\Support\PdfNormalizer). Defaults to
    // resolving it from PATH; set QPDF_BINARY to an absolute path on machines
    // where the installer didn't add it (the Windows MSVC installer does not).
    // Same pattern as DB_DUMP_BINARY in config/database.php.
    'qpdf_binary' => env('QPDF_BINARY', 'qpdf'),

];
