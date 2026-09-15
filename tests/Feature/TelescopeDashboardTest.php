<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Gate;

// Telescope's dashboard routes are intentionally not registered during
// tests (TELESCOPE_ENABLED=false in phpunit.xml — standard Laravel
// convention, so test runs don't fill the telescope_entries table with
// every request the suite makes). So this tests the 'viewTelescope' gate
// directly, the same identity check the route's auth middleware defers
// to, rather than the HTTP route itself.

test('a guest cannot view Telescope', function () {
    expect(Gate::allows('viewTelescope'))->toBeFalse();
});

test('the configured admin can view Telescope', function () {
    config(['tcgvault.admin_email' => 'admin@example.com']);
    $user = User::factory()->create(['email' => 'admin@example.com']);

    expect(Gate::forUser($user)->allows('viewTelescope'))->toBeTrue();
});

test('a logged-in user who is not the configured admin cannot view Telescope', function () {
    config(['tcgvault.admin_email' => 'admin@example.com']);
    $user = User::factory()->create(['email' => 'someone-else@example.com']);

    expect(Gate::forUser($user)->allows('viewTelescope'))->toBeFalse();
});
