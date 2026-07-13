<?php

namespace App\Support;

use App\Models\AuditLog;

/**
 * The one definition of what an audit-log CSV row looks like.
 *
 * Two things write these files — the on-demand export in AuditLogController and
 * the archive written by `audit:prune` before it deletes anything — and they
 * must agree exactly: the archive is the *only* remaining copy of a pruned row,
 * so it cannot be missing a column or leave an injection payload live. Keeping
 * one implementation is not tidiness here; a second copy is how the "most
 * searched" parser silently drifted out of sync with its own export.
 */
class AuditCsv
{
    public const COLUMNS = ['ID', 'Timestamp', 'User', 'Role', 'Action', 'Description', 'IP Address'];

    /**
     * UTF-8 BOM. Without it Excel reads the file in the local ANSI codepage and
     * mangles every non-ASCII name and search term. Every other tool ignores it.
     */
    public static function writeHeader($handle): void
    {
        fwrite($handle, "\xEF\xBB\xBF");
        fputcsv($handle, self::COLUMNS);
    }

    public static function writeRow($handle, AuditLog $log): void
    {
        fputcsv($handle, array_map([self::class, 'safe'], [
            (string) $log->id,
            $log->created_at?->format('Y-m-d H:i:s') ?? '',
            $log->user?->name ?? 'System',
            $log->user?->role ?? '',
            (string) $log->action,
            (string) ($log->description ?? ''),
            (string) ($log->ip_address ?? ''),
        ]));
    }

    /**
     * Defuses CSV formula injection.
     *
     * Excel, LibreOffice and Sheets execute any cell whose text begins with `=`,
     * `+`, `-` or `@` as a formula. Account names and search terms reach these
     * columns verbatim, so registering as `=cmd|'/c calc'!A0` would plant a live
     * formula that fires when an administrator opens the file. The attacker
     * never touches the admin's machine — they just wait for the export.
     *
     * A leading single quote makes the spreadsheet treat the cell as literal
     * text. The value itself is preserved: an audit log must not silently
     * rewrite what it recorded. (Quoting and comma-escaping are fputcsv's job
     * and it already does them; this is only about the first character.)
     */
    public static function safe(string $value): string
    {
        if ($value !== '' && str_contains("=+-@\t\r", $value[0])) {
            return "'" . $value;
        }

        return $value;
    }
}
