<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Audit log retention
    |--------------------------------------------------------------------------
    |
    | audit_logs is the only table in the system that grows without bound: it
    | gains a row on every login, search, view and administrative action. Left
    | alone it reaches millions of rows, at which point the Audit Logs page and
    | the reports built on it slow to a crawl.
    |
    | The table mixes two things with genuinely different lifetimes:
    |
    |   * SECURITY / ADMINISTRATIVE events — logins, permission grants, uploads,
    |     deletions. Low volume, high value. An auditor will ask for these. They
    |     are NEVER pruned automatically.
    |
    |   * USAGE ANALYTICS — individual thesis views and searches. This is the
    |     overwhelming bulk of the rows, and nobody needs to know that one
    |     student opened one thesis fourteen months ago. The aggregate matters;
    |     the individual event does not.
    |
    | Only the actions listed below are ever pruned, and only after they are
    | ARCHIVED TO CSV. Nothing is destroyed outright — `audit:prune` refuses to
    | delete a single row unless the archive was written and verified first.
    |
    */

    'prune' => [

        // Pure usage analytics. Deliberately NOT login/logout: those are the
        // first thing anyone investigating an incident asks for, so they stay
        // even though they are high-volume. Add actions here only if you are
        // sure you would never need the individual event.
        'actions' => [
            'view_thesis',
            'search',
        ],

        // How long an individual analytics event is kept before it is archived
        // and removed. A year comfortably covers "last semester's usage" — the
        // longest window any of the reports actually offer.
        'days' => (int) env('AUDIT_RETENTION_DAYS', 365),

    ],

    'archive' => [

        // Where the CSV archives are written. `local` = storage/app/private.
        // At deploy this should point somewhere OFF the primary server (see the
        // backup note in PROJECT-STATUS §7) — an archive that dies with the
        // machine is not an archive.
        'disk' => env('AUDIT_ARCHIVE_DISK', 'local'),

        'path' => 'audit-archives',

    ],

];
