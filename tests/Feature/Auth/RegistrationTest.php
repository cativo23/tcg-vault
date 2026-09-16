<?php

namespace Tests\Feature\Auth;

use Livewire\Volt\Volt;

// This is a single-admin personal vault: /register is gated behind
// config('tcgvault.allow_registration'), off by default (see
// config/tcgvault.php and routes/auth.php). Routes are registered once,
// at application boot, from env() — so the "on" state used by these two
// tests comes from phpunit.xml's TCGVAULT_ALLOW_REGISTRATION=true, which
// makes registration enabled for the test environment by default. The
// dedicated "off" test below flips it back off for a single test via
// putenv() + refreshApplication(), which forces a fresh route boot.

test('registration screen can be rendered', function () {
    $response = $this->get('/register');

    $response
        ->assertOk()
        ->assertSeeVolt('pages.auth.register');
});

test('new users can register', function () {
    $this->seed(\Database\Seeders\PermissionSeeder::class);

    $component = Volt::test('pages.auth.register')
        ->set('name', 'Test User')
        ->set('username', 'testuser')
        ->set('email', 'test@example.com')
        ->set('password', 'password')
        ->set('password_confirmation', 'password');

    $component->call('register');

    $component->assertRedirect(route('dashboard', absolute: false));

    $this->assertAuthenticated();

    // Registration must never create a NULL-username account — that
    // account would 500 on its own /profile page (fixed post-final-review,
    // this test pins it so it can't regress).
    expect(\App\Models\User::where('email', 'test@example.com')->firstOrFail()->username)->toBe('testuser');
});

test('registration requires a valid username', function () {
    $component = Volt::test('pages.auth.register')
        ->set('name', 'Test User')
        ->set('username', 'admin') // reserved word
        ->set('email', 'test@example.com')
        ->set('password', 'password')
        ->set('password_confirmation', 'password');

    $component->call('register');

    $component->assertHasErrors('username');
    $this->assertGuest();
});

test('staff is reserved as a username, matching the /staff platform route', function () {
    expect(\App\Models\User::reservedUsernames())->toContain('staff');
});

// /register is now ALWAYS registered — a runtime-togglable setting
// decides whether it shows the real form or an invite-only notice, not
// whether the route exists. This is deliberate: an admin flipping the
// setting off must never lock out someone mid-registration by making
// the route itself disappear, and toggling it needs no deploy.

test('registration shows an invite-only notice, not a 404, when closed', function () {
    config(['tcgvault.allow_registration' => false]);

    $response = $this->get('/register');

    $response->assertOk();
    $response->assertDontSee('wire:submit="register"', false);
});

test('the invite-only notice has no request-access form or CTA — invites are handed out manually', function () {
    config(['tcgvault.allow_registration' => false]);

    $response = $this->get('/register');

    $response->assertDontSeeText(__('Request access'));
    $response->assertDontSee('<input', false);
});

test('registration is reachable when the runtime setting overrides a closed config default', function () {
    config(['tcgvault.allow_registration' => false]);
    \App\Modules\Settings\Models\Setting::set('registration.open', true);

    $this->get('/register')
        ->assertOk()
        ->assertSeeVolt('pages.auth.register')
        ->assertSee('wire:submit="register"', false);
});
