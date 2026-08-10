<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Spatie\Permission\Middleware\RoleMiddleware;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleOrPermissionMiddleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // Trust the reverse proxy's X-Forwarded-* headers so Laravel sees the
        // real client IP and the original https scheme when running behind
        // Nginx/a load balancer. Safe with 'at: *' on a standard single-host
        // deploy where only the proxy faces the internet and PHP-FPM is bound
        // to localhost; restrict to the proxy's IP if your topology differs.
        $middleware->trustProxies(at: '*');
        $middleware->statefulApi();
        // Adds security headers (nosniff, frame-options, referrer-policy,
        // permissions-policy, HSTS-over-https) to every response.
        $middleware->append(\App\Http\Middleware\SecurityHeaders::class);
        $middleware->alias([
            'role'               => RoleMiddleware::class,
            'permission'         => PermissionMiddleware::class,
            'role_or_permission' => RoleOrPermissionMiddleware::class,
            // Overrides the framework default so throttle:api / throttle:auth
            // degrade gracefully instead of 500ing when the cache backend
            // (Redis) is unreachable. See app/Http/Middleware/SafeThrottleRequests.php.
            'throttle'           => \App\Http\Middleware\SafeThrottleRequests::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();