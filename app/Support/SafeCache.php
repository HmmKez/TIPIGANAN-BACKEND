<?php

namespace App\Support;

use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

// Wraps the Cache facade so Redis is a pure performance optimization, never
// a hard requirement — the same "degrade gracefully" pattern already used
// for Meilisearch. If the cache backend is unreachable (e.g. a developer
// hasn't installed Redis/Memurai locally yet), every call here just falls
// through to computing the value fresh instead of throwing and failing
// the whole request.
//
// Each PHP-FPM/artisan-serve request re-bootstraps the app from scratch, so
// an in-memory "is Redis down" flag wouldn't survive between requests — a
// tiny flag file on local disk does. Without this circuit breaker, every
// single request would pay Predis's full connect-retry-backoff cost (3
// retries by default — several seconds) before falling back, which is
// slower than having no cache layer at all.
class SafeCache
{
    private const CIRCUIT_OPEN_SECONDS = 15;

    public static function remember(string $key, int $ttl, Closure $callback): mixed
    {
        if (self::circuitOpen()) {
            return $callback();
        }

        try {
            $value = Cache::remember($key, $ttl, $callback);

            // A cached value can outlive the class shape it was built from
            // (e.g. a model gains/loses a column between when it was cached
            // and when it's read back). PHP's unserialize() doesn't throw
            // on that — it silently returns a __PHP_Incomplete_Class object
            // instead, which would otherwise pass straight through to the
            // response and break the frontend. Treat it as a miss and
            // recompute + overwrite instead.
            if (is_object($value) && is_incomplete_class($value)) {
                Log::warning("Cache returned an incomplete class for [{$key}], recomputing.");
                $value = $callback();
                Cache::put($key, $value, $ttl);
            }

            return $value;
        } catch (Throwable $e) {
            self::tripCircuit();
            Log::warning("Cache unavailable, computing [{$key}] without it.", [
                'error' => $e->getMessage(),
            ]);

            return $callback();
        }
    }

    public static function forget(string $key): void
    {
        if (self::circuitOpen()) {
            return;
        }

        try {
            Cache::forget($key);
        } catch (Throwable $e) {
            self::tripCircuit();
            Log::warning("Cache unavailable, could not clear [{$key}].", [
                'error' => $e->getMessage(),
            ]);
        }
    }

    // RateLimiter is also backed by the Cache facade, so it fails the exact
    // same way when Redis is unreachable. These wrappers apply the same
    // "degrade gracefully" rule: if we can't reliably count attempts, we
    // fail OPEN (let the request through) rather than failing closed and
    // locking everyone out just because the cache backend is down. That's
    // the right tradeoff for a login form — a rare missed rate-limit window
    // during a Redis outage is far cheaper than every user being unable to
    // log in at all.

    public static function tooManyAttempts(string $key, int $maxAttempts): bool
    {
        if (self::circuitOpen()) {
            return false;
        }

        try {
            return RateLimiter::tooManyAttempts($key, $maxAttempts);
        } catch (Throwable $e) {
            self::tripCircuit();
            Log::warning("Cache unavailable, skipping rate limit check for [{$key}].", [
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    public static function hit(string $key, int $decaySeconds): void
    {
        if (self::circuitOpen()) {
            return;
        }

        try {
            RateLimiter::hit($key, $decaySeconds);
        } catch (Throwable $e) {
            self::tripCircuit();
            Log::warning("Cache unavailable, could not record attempt for [{$key}].", [
                'error' => $e->getMessage(),
            ]);
        }
    }

    public static function clear(string $key): void
    {
        if (self::circuitOpen()) {
            return;
        }

        try {
            RateLimiter::clear($key);
        } catch (Throwable $e) {
            self::tripCircuit();
            Log::warning("Cache unavailable, could not clear rate limit for [{$key}].", [
                'error' => $e->getMessage(),
            ]);
        }
    }

    public static function availableIn(string $key): int
    {
        if (self::circuitOpen()) {
            return 0;
        }

        try {
            return RateLimiter::availableIn($key);
        } catch (Throwable $e) {
            self::tripCircuit();
            Log::warning("Cache unavailable, could not read lockout time for [{$key}].", [
                'error' => $e->getMessage(),
            ]);

            return 0;
        }
    }

    // Exposed so other layers that talk to the cache backend directly — e.g.
    // SafeThrottleRequests, which runs as route middleware before any
    // controller code (and therefore before it could call the methods
    // above) — can share the same circuit-breaker state instead of each
    // maintaining its own.
    public static function isDown(): bool
    {
        return self::circuitOpen();
    }

    public static function markDown(): void
    {
        self::tripCircuit();
    }

    private static function circuitPath(): string
    {
        return storage_path('framework/cache/redis-down.flag');
    }

    private static function circuitOpen(): bool
    {
        $path = self::circuitPath();

        return file_exists($path) && (time() - filemtime($path)) < self::CIRCUIT_OPEN_SECONDS;
    }

    private static function tripCircuit(): void
    {
        @touch(self::circuitPath());
    }
}
