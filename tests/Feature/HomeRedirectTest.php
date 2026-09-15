<?php

declare(strict_types=1);

use App\Models\User;

test('an anonymous visitor sees the home page even when an admin username is configured', function () {
    config(['tcgvault.admin_username' => 'carlos']);
    User::factory()->create(['username' => 'someone-else']);
    User::factory()->create(['username' => 'carlos']);

    $this->get('/')->assertOk()->assertSee('Track every');
});

test('an anonymous visitor sees the home page when no admin username is configured', function () {
    config(['tcgvault.admin_username' => null]);
    User::factory()->create(['username' => 'first']);
    User::factory()->create(['username' => 'second']);

    $this->get('/')->assertOk()->assertSee('Track every');
});

test('an anonymous visitor sees the home page on a fresh install with no users at all', function () {
    config(['tcgvault.admin_username' => null]);

    $this->get('/')->assertOk()->assertSee('Track every');
});

test('an authenticated visitor is redirected straight to their own gallery', function () {
    $user = User::factory()->create(['username' => 'carlos']);

    $this->actingAs($user)->get('/')->assertRedirect('/carlos');
});

test('the home page uses the site name as its title, with no page-specific override', function () {
    $this->get('/')->assertOk()->assertSee('<title>tcg-vault</title>', escape: false);
});

test('the hero shows at least 5 real tcgdex-hosted card images', function () {
    $response = $this->get('/');

    $response->assertOk();
    $count = substr_count($response->getContent(), 'assets.tcgdex.net');

    expect($count)->toBeGreaterThanOrEqual(5);
});

test('the features section describes all three real features', function () {
    $response = $this->get('/');

    $response->assertOk();
    $response->assertSee('Real-time pricing');
    $response->assertSee('Your own photo, not a placeholder');
    $response->assertSee('Complete sets, at a glance');
});

test('the closing sections show data coverage, FAQ, and repeat the CTA', function () {
    $response = $this->get('/');

    $response->assertOk();
    $response->assertSee('tcgdex.dev');
    $response->assertSee('Does it cost anything?');
    $response->assertSeeInOrder(['Track every', 'Real-time pricing', 'Does it cost anything?', 'Notify me when it opens']);
});
