<?php

declare(strict_types=1);

use App\Models\User;
use Spatie\Permission\Models\Role;

test('a role assigned to a user persists', function () {
    Role::create(['name' => 'user']);
    $user = User::factory()->create();

    $user->assignRole('user');

    expect($user->hasRole('user'))->toBeTrue();
    expect($user->refresh()->hasRole('user'))->toBeTrue();
});
