<?php

declare(strict_types=1);

use App\Models\User;

test('a guest cannot view the Horizon dashboard', function () {
    $this->get('/horizon')->assertForbidden();
});

test('the configured admin can view the Horizon dashboard', function () {
    config(['tcgvault.admin_email' => 'admin@example.com']);
    $user = User::factory()->create(['email' => 'admin@example.com']);

    $this->actingAs($user)->get('/horizon')->assertOk();
});

test('a logged-in user who is not the configured admin cannot view the Horizon dashboard', function () {
    config(['tcgvault.admin_email' => 'admin@example.com']);
    $user = User::factory()->create(['email' => 'someone-else@example.com']);

    $this->actingAs($user)->get('/horizon')->assertForbidden();
});
