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
        // Gated on the view-horizon permission (seeded in
        // database/seeders/PermissionSeeder.php), not a hardcoded email —
        // a registered account must not inherit queue payload visibility
        // (retry/delete controls) just by being logged in. super-admin
        // passes regardless via AppServiceProvider's Gate::before.
        Gate::define('viewHorizon', function ($user = null) {
            return $user !== null && $user->can('view-horizon');
        });
    }
}
