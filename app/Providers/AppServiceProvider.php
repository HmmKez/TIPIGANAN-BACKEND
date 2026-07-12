<?php

namespace App\Providers;

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
    }
}
