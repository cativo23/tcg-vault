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

test('registration is inaccessible when TCGVAULT_ALLOW_REGISTRATION is off', function () {
    putenv('TCGVAULT_ALLOW_REGISTRATION=false');
    $_ENV['TCGVAULT_ALLOW_REGISTRATION'] = 'false';
    $_SERVER['TCGVAULT_ALLOW_REGISTRATION'] = 'false';

    try {
        $this->refreshApplication();

        $this->get('/register')->assertNotFound();
    } finally {
        putenv('TCGVAULT_ALLOW_REGISTRATION');
        unset($_ENV['TCGVAULT_ALLOW_REGISTRATION'], $_SERVER['TCGVAULT_ALLOW_REGISTRATION']);
        $this->refreshApplication();
    }
});
