<?php

namespace App\Http\Middleware;

use App\Support\SafeCache;
use Closure;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

// Route-level throttling (throttle:api, throttle:auth) runs as middleware,
// so it executes before any controller code — before SafeCache::hit() etc.
// would ever get a chance to intercept a Redis failure. This wraps Laravel's
// built-in ThrottleRequests with the same "degrade gracefully, never let a
// down cache backend take down the whole request" rule used everywhere else
// (see SafeCache), applied at the middleware layer instead.
//
// Registered in bootstrap/app.php as the 'throttle' alias, overriding the
// framework default, so both throttle:api and throttle:auth pick it up
// automatically without touching routes/api.php.
class SafeThrottleRequests extends ThrottleRequests
{
    public function handle($request, Closure $next, $maxAttempts = 60, $decayMinutes = 1, $prefix = '')
    {
        // Skip straight to letting the request through if we already know
        // the cache backend is down — avoids paying Predis's connect/retry
        // cost on every single request during an outage.
        if (SafeCache::isDown()) {
            return $next($request);
        }

        // Laravel's ThrottleRequests::handle() decides whether $maxAttempts is
        // a *named* limiter (e.g. "api", registered via RateLimiter::for())
        // by checking func_num_args() === 3 — i.e. it only treats it as a
        // named limiter when the route middleware was declared as
        // throttle:api (just the name, no decay/prefix args). That check
        // inspects how many arguments THIS call actually received, not how
        // many parameters are declared above. So we must forward only the
        // arguments this method itself was called with — always passing all
        // 5 would make Laravel treat "api"/"auth" as a literal number of
        // attempts instead of looking them up, causing
        // MissingRateLimiterException.
        $args = array_slice([$request, $next, $maxAttempts, $decayMinutes, $prefix], 0, func_num_args());

        // IMPORTANT: we do NOT wrap parent::handle(...$args) itself in a
        // try/catch. ThrottleRequests::handle() calls $next($request)
        // *internally* once the rate-limit check passes — that's how the
        // request continues on to the controller. If we caught Throwable
        // around the whole parent::handle() call, we'd also catch any
        // exception the controller itself throws (e.g. a failed-login
        // ValidationException) and our catch block would call $next($request)
        // a SECOND time, running the controller twice for one request.
        //
        // Instead, we do a cheap, isolated connectivity probe first. If the
        // cache backend is unreachable, we mark it down and call $next()
        // ourselves exactly once. If the probe succeeds, we know the cache is
        // reachable right now, so it's safe to let parent::handle() run
        // normally (including its own call to $next()).
        try {
            Cache::store()->get('__throttle_probe__');
        } catch (Throwable $e) {
            // Deliberately broad: Predis alone throws several different
            // exception classes depending on *how* the connection fails
            // (e.g. Predis\Connection\Resource\Exception\StreamInitException
            // on a refused connection, which does NOT extend
            // Predis\Connection\ConnectionException — catching that alone
            // missed this case). Rather than chase every possible subclass,
            // catch anything and let it through, same as SafeCache does.
            SafeCache::markDown();
            Log::warning('Cache unavailable, skipping route-level throttling for this request.', [
                'error' => $e->getMessage(),
            ]);

            return $next($request);
        }

        return parent::handle(...$args);
    }
}