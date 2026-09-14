<?php

use App\Models\User;
use Livewire\Volt\Volt;

test('login screen can be rendered', function () {
    $response = $this->get('/login');

    $response
        ->assertOk()
        ->assertSeeVolt('pages.auth.login');
});

test('users can authenticate using the login screen', function () {
    $user = User::factory()->create();

    $component = Volt::test('pages.auth.login')
        ->set('form.email', $user->email)
        ->set('form.password', 'password');

    $component->call('login');

    $component
        ->assertHasNoErrors()
        ->assertRedirect(route('dashboard', absolute: false));

    $this->assertAuthenticated();
});

test('users can authenticate using their username instead of email', function () {
    $user = User::factory()->create(['username' => 'testuser']);

    $component = Volt::test('pages.auth.login')
        ->set('form.email', 'testuser') // same field, no @ present
        ->set('form.password', 'password');

    $component->call('login');

    $component
        ->assertHasNoErrors()
        ->assertRedirect(route('dashboard', absolute: false));

    $this->assertAuthenticated();
});

test('rate limiting a login by email also throttles the same account by username', function () {
    $user = User::factory()->create(['username' => 'testuser']);

    // 5 failed attempts using the EMAIL identifier trips the limiter.
    for ($i = 0; $i < 5; $i++) {
        Volt::test('pages.auth.login')
            ->set('form.email', $user->email)
            ->set('form.password', 'wrong-password')
            ->call('login');
    }

    // Switching to the USERNAME identifier for the same account must
    // still be throttled — a bypass here would let an attacker locked
    // out on one identifier immediately get a fresh attempt bucket by
    // switching to the other.
    $component = Volt::test('pages.auth.login')
        ->set('form.email', 'testuser')
        ->set('form.password', 'password'); // the account's real password — must still be rejected while throttled

    $component->call('login');

    $component->assertHasErrors();
    $this->assertGuest();
});

test('rate limiting cannot be bypassed with a case variant of the username', function () {
    $user = User::factory()->create(['username' => 'testuser']);

    for ($i = 0; $i < 5; $i++) {
        Volt::test('pages.auth.login')
            ->set('form.email', $user->email)
            ->set('form.password', 'wrong-password')
            ->call('login');
    }

    // Postgres `=` is case-sensitive — submitting an UPPERCASE variant of
    // the stored username must still resolve to the same account/bucket,
    // not silently fail to match and fall back to a fresh throttle key.
    $component = Volt::test('pages.auth.login')
        ->set('form.email', 'TESTUSER')
        ->set('form.password', 'password');

    $component->call('login');

    $component->assertHasErrors();
    $this->assertGuest();
});

test('users can not authenticate with invalid password', function () {
    $user = User::factory()->create();

    $component = Volt::test('pages.auth.login')
        ->set('form.email', $user->email)
        ->set('form.password', 'wrong-password');

    $component->call('login');

    $component
        ->assertHasErrors()
        ->assertNoRedirect();

    $this->assertGuest();
});

test('navigation menu can be rendered', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    // Task 6 made '/dashboard' forward to '/admin' (the real landing page);
    // exercise the actual rendered page directly rather than following the redirect.
    $response = $this->get('/admin');

    $response
        ->assertOk()
        ->assertSeeVolt('layout.navigation');
});

test('users can logout', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    $component = Volt::test('layout.navigation');

    $component->call('logout');

    $component
        ->assertHasNoErrors()
        ->assertRedirect('/');

    $this->assertGuest();
});
