<?php

namespace App\Providers;

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
