<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Support\ScanInbox;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * What the scanner has left waiting.
 *
 * Staff-only: the inbox is a window onto a folder on the library's storage, and
 * listing it reveals what has been scanned recently. Route protection is in
 * routes/api.php alongside the other staff endpoints.
 */
class ScanInboxController extends Controller
{
    public function __construct(private readonly ScanInbox $inbox)
    {
    }

    public function index()
    {
        if (! $this->inbox->enabled()) {
            return response()->json([
                'enabled' => false,
                'files'   => [],
            ]);
        }

        // Distinguishes "nothing scanned yet" from "the folder isn't there",
        // which are the same empty list to a user but completely different
        // problems: the second usually means the NAS share is not mounted, and
        // silently showing "no scans" would send staff hunting for a fault at
        // the scanner instead.
        if (! $this->inbox->exists()) {
            return response()->json([
                'enabled'   => true,
                'available' => false,
                'message'   => 'The scan folder cannot be reached. It may not be mounted - ask whoever set up the scanner station.',
                'files'     => [],
            ]);
        }

        $listing = $this->inbox->listing();

        return response()->json([
            'enabled'   => true,
            'available' => true,
            // Everything waiting, not just what is listed - the screen shows
            // "N waiting, showing the newest 100" when they differ.
            'total'     => $listing['total'],
            'files'     => $listing['files'],
        ]);
    }

    /**
     * Open a waiting scan so staff can see which one it is before filing it.
     *
     * File names straight off a scanner ("Scan_2026-10-06_0914.pdf") say
     * nothing about which thesis is inside, and with several waiting there is
     * no other way to tell them apart.
     *
     * The name arrives from the browser, so it goes through resolve() - the
     * single place a requested name is made safe - and only a PDF inside the
     * inbox can ever be served. Inline rather than as a download: staff are
     * looking, not taking a copy.
     */
    public function preview(string $name)
    {
        try {
            $absolute = $this->inbox->resolve($name);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 404);
        }

        return response()->file($absolute, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.str_replace('"', '', basename($absolute)).'"',
            'Cache-Control'       => 'no-store, no-cache',
        ]);
    }

    /** Discard a scan without importing it - a misfeed, a test page, a duplicate. */
    public function destroy(Request $request, string $name)
    {
        try {
            $this->inbox->resolve($name);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 404);
        }

        $this->inbox->discard($name);

        AuditLog::create([
            'user_id'     => $request->user()->id,
            'action'      => 'discard_scan',
            'target_type' => 'scan',
            'target_id'   => null,
            'description' => "{$request->user()->display_name} discarded scanned file: {$name}",
            'ip_address'  => $request->ip(),
        ]);

        return response()->json(['message' => 'Scan discarded.']);
    }
}
