<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Collection\Models\Collection;
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

test('the privacy FAQ points to where the visibility control actually is', function () {
    $this->get('/')
        ->assertOk()
        ->assertSee('My Collection')
        ->assertDontSee('from your profile');
});

test('the home page links to the example collection once it is public', function () {
    config(['tcgvault.demo.username' => 'demo']);
    $demo = User::factory()->create(['username' => 'demo']);
    Collection::factory()->for($demo)->create(['is_public' => true]);

    $this->get('/')
        ->assertOk()
        ->assertSee(route('gallery.index', ['username' => 'demo']))
        ->assertSee('See an example collection');
});

test('the home page hides the example link while there is no public demo collection', function () {
    config(['tcgvault.demo.username' => 'demo']);
    $demo = User::factory()->create(['username' => 'demo']);
    Collection::factory()->for($demo)->create(['is_public' => false]);

    $this->get('/')
        ->assertOk()
        ->assertDontSee('See an example collection');
});
