<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Gate;
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
        // sync/import, pricing refresh) to a safe combined rate, regardless
        // of how many Horizon workers run in parallel — tcgdex treats
        // concurrent bursts as failures even though a single ad-hoc request
        // succeeds instantly.
        //
        // Split into two separate budgets (2/sec + 1/sec = the same 3/sec
        // ceiling tcgdex needs) rather than one shared 'tcgdex' bucket.
        // Horizon's queue priority ('default' before 'imports',
        // config/horizon.php) only controls which job gets popped first —
        // a single shared rate-limit bucket is queue-blind, so a large
        // 'imports' backlog could exhaust the whole budget before a
        // 'default' job gets a turn, making the priority ordering
        // cosmetic. Giving 'default' its own reserved slice guarantees it
        // real throughput no matter how much 'imports' work is pending.
        RateLimiter::for('tcgdex-default', fn () => Limit::perSecond(2));
        RateLimiter::for('tcgdex-imports', fn () => Limit::perSecond(1));

        if ($this->app->isProduction()) {
            // Always generate URLs (including Breeze's password-reset
            // links) from the configured APP_URL, never from a possibly
            // spoofed request Host header — closes the host-header
            // injection half of the trustProxies finding independent of
            // whatever else shares the Traefik network.
            URL::forceRootUrl(config('app.url'));
            URL::forceScheme('https');
        }

        // super-admin passes every gate and permission check, present or
        // future, without needing its own explicit permission list kept
        // in sync as new ones are added — see PermissionSeeder's
        // docblock for why that list is deliberately never seeded.
        Gate::before(function (?Authenticatable $user, string $ability) {
            return $user?->hasRole('super-admin') ? true : null;
        });
    }
}
