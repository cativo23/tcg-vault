<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

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
        // Caps every tcgdex API call this app's queue makes (catalog
        // sync/import, pricing refresh) to a safe rate — regardless of
        // how many Horizon workers run in parallel. Found live
        // 2026-09-16: dispatching the whole ~1986-card catalog at once
        // drove near-100% "Could not resolve host" failures against
        // api.tcgdex.net even with only Horizon's default 2 workers (a
        // single ad-hoc request succeeded instantly — this is burst load,
        // not a broken endpoint). Named 'tcgdex' so every job that talks
        // to tcgdex shares the same budget, not one cap per job class.
        RateLimiter::for('tcgdex', fn () => Limit::perSecond(3));

        if ($this->app->isProduction()) {
            // Always generate URLs (including Breeze's password-reset
            // links) from the configured APP_URL, never from a possibly
            // spoofed request Host header — closes the host-header
            // injection half of the trustProxies finding independent of
            // whatever else shares the Traefik network.
            URL::forceRootUrl(config('app.url'));
            URL::forceScheme('https');
        }
    }
}
