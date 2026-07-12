<?php

return [

    // How many days a superseded PDF (from a file replace) stays restorable
    // before the automatic purge sweep deletes it for good. Staff can also
    // delete one early via the "Delete Now" action regardless of this window.
    'old_file_retention_days' => (int) env('THESIS_OLD_FILE_RETENTION_DAYS', 30),

];
