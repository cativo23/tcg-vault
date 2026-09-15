<?php

declare(strict_types=1);

use App\Models\User;

test('a guest cannot view the Horizon dashboard', function () {
    $this->get('/horizon')->assertForbidden();
});

test('a logged-in user can view the Horizon dashboard', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/horizon')->assertOk();
});
