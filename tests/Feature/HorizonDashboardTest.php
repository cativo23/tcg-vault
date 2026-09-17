<?php

declare(strict_types=1);

use App\Models\User;
use Spatie\Permission\Models\Permission;

test('a guest cannot view the Horizon dashboard', function () {
    $this->get('/horizon')->assertForbidden();
});

test('a user with the view-horizon permission can view the Horizon dashboard', function () {
    Permission::create(['name' => 'view-horizon']);
    $user = User::factory()->create();
    $user->givePermissionTo('view-horizon');

    $this->actingAs($user)->get('/horizon')->assertOk();
});

test('a logged-in user without the view-horizon permission cannot view the Horizon dashboard', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/horizon')->assertForbidden();
});
