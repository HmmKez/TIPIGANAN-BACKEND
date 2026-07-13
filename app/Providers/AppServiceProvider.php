<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Behind a TLS-terminating reverse proxy (Nginx), the app receives
        // plain http internally, so Laravel would otherwise generate http://
        // links — breaking the signed PDF-viewer URLs and redirects. Forcing
        // https in production keeps every generated URL correct. Guarded to
        // production so local dev (plain http) is unaffected. Paired with the
        // trustProxies() call in bootstrap/app.php.
        if ($this->app->environment('production')) {
            URL::forceScheme('https');
        }

        // Single place to define the password policy — every `Password::default()`
        // rule (registration, change-password, admin create/reset) reads from
        // here, so tightening/loosening it later is a one-line change instead
        // of hunting down every validation call site. No uncompromised()
        // check (Have I Been Pwned lookup) — that's a live external HTTP call
        // on every password set, not worth the dependency/failure mode here.
        Password::defaults(fn () => Password::min(8)->mixedCase()->numbers());

        // Named limiters, not inline `throttle:120,1` / `throttle:5,1`. An inline
        // throttle keys purely on the client IP (see ThrottleRequests::resolve-
        // RequestSignature) — the route is NOT part of the key — so a strict
        // inline throttle nested inside a lenient one shares ONE counter with it.
        // Ordinary browsing would push that shared counter past the login limit
        // and 429 the first login attempt. Naming them gives each its own bucket.
        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(120)
            ->by($request->user()?->id ?: $request->ip()));

        // Ceiling on the auth endpoints per IP. Deliberately generous: it exists
        // to blunt credential-stuffing across many accounts, not to police one
        // user's typos. Per-account brute-force protection is enforced separately
        // in AuthController::login, which counts only FAILED attempts.
        RateLimiter::for('auth', fn (Request $request) => Limit::perMinute(20)
            ->by($request->ip()));
    }
}
