<?php

namespace App\Providers;

use App\Support\SchoolDirectory\FakeSchoolDirectory;
use App\Support\SchoolDirectory\HttpSchoolDirectory;
use App\Support\SchoolDirectory\NullSchoolDirectory;
use App\Support\SchoolDirectory\SchoolDirectory;
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
        // Pick the source that ID numbers are verified against. Everything else
        // in the app depends on the SchoolDirectory interface and never on a
        // concrete class, so moving from the fixture to the school's real
        // system is this one config value - see config/school.php.
        $this->app->singleton(SchoolDirectory::class, function () {
            $config = config('school.directory');

            return match ($config['driver']) {
                'fake' => new FakeSchoolDirectory(
                    fixturePath:     $config['fixture'],
                    simulateOutage:  (bool) $config['simulate_outage'],
                ),
                // The school's list imported from a CSV - see App\Support\SchoolRoster.
                'csv'  => new \App\Support\SchoolDirectory\CsvSchoolDirectory(),
                'http' => new HttpSchoolDirectory(
                    baseUrl: (string) $config['url'],
                    apiKey:  $config['key'],
                    timeout: (int) $config['timeout'],
                ),
                default => new NullSchoolDirectory(),
            };
        });
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
        //
        // The 72 is bcrypt's limit, in bytes: it ignores everything after that,
        // so a longer password would also accept any text sharing its first 72.
        Password::defaults(fn () => Password::min(8)->mixedCase()->numbers()->rules([
            function (string $attribute, mixed $value, \Closure $fail) {
                if (strlen((string) $value) > 72) {
                    $fail('The password must not be longer than 72 characters.');
                }
            },
        ]));

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

        // Each lookup shows a name from the school's list. Plenty for a busy
        // desk, far too slow for going through the list to collect names.
        RateLimiter::for('activation-lookup', fn (Request $request) => Limit::perMinute(30)
            ->by($request->user()?->id ?: $request->ip()));
    }
}
