<?php

return [
    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => [
        'http://localhost:5173',
        'http://127.0.0.1:5173',
    ],

    // Vite bumps to 5174, 5175, etc. whenever 5173 is already taken (e.g. a
    // leftover dev server from an earlier session) — matching by pattern
    // means CORS doesn't silently break every time that happens. Safe to
    // leave in as-is since it only ever matches localhost/127.0.0.1 origins.
    'allowed_origins_patterns' => [
        '#^http://(localhost|127\.0\.0\.1):\d+$#',
    ],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => true,
];