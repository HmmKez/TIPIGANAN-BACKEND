<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * Turns a `YYYY-MM-DD` filter into datetime bounds an index can actually seek.
 *
 * The queries used to say whereDate('created_at', '<=', $to), which compiles to
 * `DATE(created_at) <= ?`. Wrapping the column in a function forces the database
 * to evaluate it row by row instead of seeking the index — on 200k audit rows
 * that was 193ms, versus 25ms for the plain range comparison below.
 *
 * The catch, and the reason this is a named helper rather than an inline
 * `->where('created_at', '<=', $to)`: whereDate(<=, '2026-07-13') means the
 * WHOLE of the 13th, but a plain `<= '2026-07-13'` means midnight *starting* the
 * 13th — silently dropping every entry from that entire day. So the upper bound
 * is exclusive-next-midnight, not inclusive-same-day.
 */
class DateRange
{
    /** Inclusive lower bound: the first instant of that day. */
    public static function start(string $date): Carbon
    {
        return Carbon::parse($date)->startOfDay();
    }

    /** EXCLUSIVE upper bound: the first instant of the NEXT day. Use with `<`. */
    public static function endExclusive(string $date): Carbon
    {
        return Carbon::parse($date)->addDay()->startOfDay();
    }
}
