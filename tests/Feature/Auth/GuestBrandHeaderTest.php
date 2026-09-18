<?php

declare(strict_types=1);

use Illuminate\Support\Str;

// The Vault Line wordmark used to only show up ad hoc — login still had
// the old flat-dot mark, forgot/reset-password had none at all. These pin
// the same .nw-mark wordmark onto every guest auth page so a visitor
// always has a consistent "who am I dealing with" cue, regardless of
// which page they land on first.

test('the login page shows the brand wordmark', function () {
    $this->get(route('login'))
        ->assertOk()
        ->assertSee('nw-mark', escape: false);
});

test('the forgot-password page shows the brand wordmark', function () {
    $this->get(route('password.request'))
        ->assertOk()
        ->assertSee('nw-mark', escape: false);
});

test('the reset-password page shows the brand wordmark', function () {
    $token = Str::random(64);

    $this->get(route('password.reset', ['token' => $token]))
        ->assertOk()
        ->assertSee('nw-mark', escape: false);
});
