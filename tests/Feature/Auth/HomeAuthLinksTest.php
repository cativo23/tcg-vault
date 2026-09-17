<?php

declare(strict_types=1);

use App\Settings\RegistrationSettings;

test('a guest always sees a login link on the home page', function () {
    app(RegistrationSettings::class)->open = false;
    app(RegistrationSettings::class)->save();

    $this->get('/')->assertOk()->assertSee('Log in');
});

test('a guest sees a sign-up link and open-registration copy when registration is open', function () {
    app(RegistrationSettings::class)->open = true;
    app(RegistrationSettings::class)->save();

    $response = $this->get('/');

    $response->assertOk();
    $response->assertSee('Sign up');
    $response->assertSee('Create your account');
    $response->assertDontSee('Invite-only');
});

test('a guest sees invite-only copy and no sign-up link when registration is closed', function () {
    app(RegistrationSettings::class)->open = false;
    app(RegistrationSettings::class)->save();

    $response = $this->get('/');

    $response->assertOk();
    $response->assertDontSee('Sign up');
    $response->assertSee('Invite-only');
});
