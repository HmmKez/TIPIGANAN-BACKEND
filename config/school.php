<?php

return [

    /*
    |--------------------------------------------------------------------------
    | School directory driver
    |--------------------------------------------------------------------------
    |
    | Decides where TIPIGANAN checks whether an ID number belongs to a real,
    | current student or faculty member.
    |
    |   off   Verification disabled. Registration behaves exactly as it did
    |         before this feature existed. THIS IS THE DEFAULT, so pulling this
    |         branch changes nothing for teammates until someone opts in.
    |
    |   fake  Verified against database/fixtures/school-directory.json. The full
    |         flow works end to end, which is how it is developed, demonstrated
    |         and tested while the school's own system is unavailable.
    |
    |   http  The school's real records system. Requires `url` below. See
    |         app/Support/SchoolDirectory/HttpSchoolDirectory.php - switching to
    |         this is expected to need edits in that one file and nowhere else.
    |
    */

    'directory' => [

        'driver' => env('SCHOOL_DIRECTORY_DRIVER', 'off'),

        'url'     => env('SCHOOL_DIRECTORY_URL'),
        'key'     => env('SCHOOL_DIRECTORY_KEY'),
        'timeout' => (int) env('SCHOOL_DIRECTORY_TIMEOUT', 8),

        'fixture' => database_path('fixtures/school-directory.json'),

        /*
        | Simulates the school's system being unreachable, so the refuse path
        | can be demonstrated on demand rather than only when something is
        | genuinely broken. Applies to the fake driver only.
        */
        'simulate_outage' => (bool) env('SCHOOL_DIRECTORY_UNAVAILABLE', false),

        /*
        | NOTE: lookups are deliberately NOT cached.
        |
        | Registration checks an ID twice - once when the person types it, once
        | when the account is actually created - and caching would collapse that
        | into one call. It is not worth it: registration is rare, so the saving
        | is negligible, while a cache opens a window where somebody verified
        | moments before the registrar deactivated them could still register on
        | a stale answer. Caching also does nothing against ID enumeration,
        | since that sweeps different numbers every time. Checking live at both
        | steps is simpler and strictly more correct.
        */

        /*
        | Failed ID-check attempts allowed per minute, per IP.
        |
        | The check endpoint takes an ID number and says whether it is real,
        | which is exactly the shape of thing somebody would run from 00000 to
        | 99999 to discover valid IDs. This ceiling is what makes that
        | impractical; every attempt is written to the audit log as well.
        */
        'verify_attempts_per_minute' => (int) env('SCHOOL_DIRECTORY_VERIFY_ATTEMPTS', 8),
    ],

];
