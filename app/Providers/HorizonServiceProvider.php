<?php

namespace App\Providers;

use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\Horizon;
use Laravel\Horizon\HorizonApplicationServiceProvider;

class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        parent::boot();

        // Horizon::routeSmsNotificationsTo('15556667777');
        // Horizon::routeMailNotificationsTo('example@example.com');
        // Horizon::routeSlackNotificationsTo('slack-webhook-url', '#channel');
    }

    /**
     * Register the Horizon gate.
     *
     * This gate determines who can access Horizon in non-local environments.
     */
    protected function gate(): void
    {
        // Single-admin app — gate on the CONFIGURED admin specifically, not
        // just "any authenticated user". TCGVAULT_ALLOW_REGISTRATION exists
        // (config/tcgvault.php) for a future second account; if that's ever
        // enabled, a non-admin account must not inherit Horizon access
        // (queue payload visibility, retry/delete controls) just by being
        // logged in.
        //
        // Gates on email rather than username so this matches
        // TelescopeServiceProvider's gate, which uses Telescope's stock
        // email-based check — both use the same
        // config('tcgvault.admin_email') value the seeder already requires.
        Gate::define('viewHorizon', function ($user = null) {
            return $user !== null && $user->email === config('tcgvault.admin_email');
        });
    }
}
