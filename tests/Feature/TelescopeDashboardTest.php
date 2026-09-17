<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\Models\Permission;

// Telescope's dashboard routes are intentionally not registered during
// tests (TELESCOPE_ENABLED=false in phpunit.xml — standard Laravel
// convention, so test runs don't fill the telescope_entries table with
// every request the suite makes). So this tests the 'viewTelescope' gate
// directly, the same identity check the route's auth middleware defers
// to, rather than the HTTP route itself.

test('a guest cannot view Telescope', function () {
    expect(Gate::allows('viewTelescope'))->toBeFalse();
});

test('a user with the view-telescope permission can view Telescope', function () {
    Permission::create(['name' => 'view-telescope']);
    $user = User::factory()->create();
    $user->givePermissionTo('view-telescope');

    expect(Gate::forUser($user)->allows('viewTelescope'))->toBeTrue();
});

test('a logged-in user without the view-telescope permission cannot view Telescope', function () {
    $user = User::factory()->create();

    expect(Gate::forUser($user)->allows('viewTelescope'))->toBeFalse();
});
