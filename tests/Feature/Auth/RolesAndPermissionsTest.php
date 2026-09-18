<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\Models\Permission;
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

test('a user with use-collection can reach the admin collection routes', function () {
    Role::create(['name' => 'user'])->givePermissionTo(
        Permission::create(['name' => 'use-collection']),
    );
    $user = User::factory()->create();
    $user->assignRole('user');

    $this->actingAs($user)->get('/admin')->assertOk();
});

test('a user without use-collection is forbidden from the admin collection routes', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/admin')->assertForbidden();
});

test('a super-admin can reach the admin collection routes without the use-collection permission', function () {
    Role::create(['name' => 'super-admin']);
    $user = User::factory()->create();
    $user->assignRole('super-admin');

    $this->actingAs($user)->get('/admin')->assertOk();
});

test('a user with manage-platform-settings passes the gate, a plain user does not', function () {
    Permission::create(['name' => 'manage-platform-settings']);
    $admin = User::factory()->create();
    $admin->givePermissionTo('manage-platform-settings');
    $plainUser = User::factory()->create();

    expect(Gate::forUser($admin)->allows('manage-platform-settings'))->toBeTrue();
    expect(Gate::forUser($plainUser)->allows('manage-platform-settings'))->toBeFalse();
});
