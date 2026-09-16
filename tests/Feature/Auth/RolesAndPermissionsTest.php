<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\Models\Role;

test('a role assigned to a user persists', function () {
    Role::create(['name' => 'user']);
    $user = User::factory()->create();

    $user->assignRole('user');

    expect($user->hasRole('user'))->toBeTrue();
    expect($user->refresh()->hasRole('user'))->toBeTrue();
});

test('a super-admin passes any gate, even one that maps to no real permission', function () {
    Role::create(['name' => 'super-admin']);
    $user = User::factory()->create();
    $user->assignRole('super-admin');

    Gate::define('some-permission-that-does-not-exist-yet', fn () => false);

    expect(Gate::forUser($user)->allows('some-permission-that-does-not-exist-yet'))->toBeTrue();
});

test('a regular user does not pass a gate it has no permission for', function () {
    Role::create(['name' => 'user']);
    $user = User::factory()->create();
    $user->assignRole('user');

    Gate::define('some-permission-that-does-not-exist-yet', fn () => false);

    expect(Gate::forUser($user)->allows('some-permission-that-does-not-exist-yet'))->toBeFalse();
});
