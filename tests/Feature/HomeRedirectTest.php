<?php

declare(strict_types=1);

use App\Models\User;

test('the root redirects to the configured admin username\'s gallery', function () {
    config(['tcgvault.admin_username' => 'carlos']);
    User::factory()->create(['username' => 'someone-else']);
    User::factory()->create(['username' => 'carlos']);

    $this->get('/')->assertRedirect('/carlos/gallery');
});

test('without a configured admin username the root falls back to the first account with a username', function () {
    config(['tcgvault.admin_username' => null]);
    User::factory()->create(['username' => 'first']);
    User::factory()->create(['username' => 'second']);

    $this->get('/')->assertRedirect('/first/gallery');
});

test('a fresh install with no users sends the root to login', function () {
    config(['tcgvault.admin_username' => null]);

    $this->get('/')->assertRedirect('/login');
});
