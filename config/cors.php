<?php

// Origins allowed to call the API from a browser.
//   Production: locked to FRONTEND_URL — the exact domain(s) your built
//               frontend is served from. Comma-separate to allow more than one
//               (e.g. "https://repo.example.com,https://www.repo.example.com").
//   Local dev:  the pattern below additionally allows any localhost/127.0.0.1
//               port, so it doesn't matter which port Vite lands on.
$frontendOrigins = array_values(array_filter(array_map(
    'trim',
    explode(',', (string) env('FRONTEND_URL', ''))
)));

// Localhost-any-port is permitted ONLY in local dev — never in production,
// where FRONTEND_URL is the single source of truth for who may call the API.
$localPatterns = env('APP_ENV') === 'local'
    ? ['#^http://(localhost|127\.0\.0\.1):\d+$#']
    : [];

return [
    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    // Explicit production origin(s) from FRONTEND_URL; empty in local dev
    // (the localhost pattern below covers dev instead).
    'allowed_origins' => $frontendOrigins,

    // Any localhost port in dev only; empty (locked down) in production.
    'allowed_origins_patterns' => $localPatterns,

    'allowed_headers' => ['*'],

    // CORS hides every response header from frontend JS by default except a
    // small standard set — Retry-After (set by the throttle middleware on a
    // 429) isn't in that set, so without this the login/register pages can
    // see the request was rate-limited but not how long to wait.
    'exposed_headers' => ['Retry-After'],

    'max_age' => 0,

    'supports_credentials' => true,
];
