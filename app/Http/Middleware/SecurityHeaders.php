<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

// Defence-in-depth response headers on every API response. Note: the headers
// that matter most for clickjacking/HTTPS-pinning belong on the user-facing
// HTML, which is served by the frontend host (Nginx), not Laravel — the deploy
// checklist (§7 of PROJECT-STATUS.md) sets the same headers there. These cover
// the API surface.
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // Don't let the browser MIME-sniff a response into something executable.
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        // Block framing by other origins (clickjacking).
        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        // Don't leak full URLs to other sites in the Referer header.
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        // Disable browser features the app never uses.
        $response->headers->set('Permissions-Policy', 'geolocation=(), microphone=(), camera=()');

        // HSTS only over HTTPS — pointless/inert on plain-http local dev, and
        // sending it on http could wrongly pin a dev domain to https.
        if ($request->isSecure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }
}
