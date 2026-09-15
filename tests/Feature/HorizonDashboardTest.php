<?php

declare(strict_types=1);

use App\Models\User;

test('a guest cannot view the Horizon dashboard', function () {
    $this->get('/horizon')->assertForbidden();
});

test('the configured admin can view the Horizon dashboard', function () {
    config(['tcgvault.admin_username' => 'testadmin']);
    $user = User::factory()->create(['username' => 'testadmin']);

    $this->actingAs($user)->get('/horizon')->assertOk();
});

test('a logged-in user who is not the configured admin cannot view the Horizon dashboard', function () {
    config(['tcgvault.admin_username' => 'testadmin']);
    $user = User::factory()->create(['username' => 'someone-else']);

    $this->actingAs($user)->get('/horizon')->assertForbidden();
});
