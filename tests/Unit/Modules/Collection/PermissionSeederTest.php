<?php

declare(strict_types=1);

use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

test('seeding creates the core permissions and roles', function () {
    config(['tcgvault.admin_email' => 'cativo23.kt@gmail.com']);
    config(['tcgvault.admin_password' => 'a-real-password']);

    $this->seed();

    foreach (['view-horizon', 'view-telescope', 'manage-invites', 'manage-platform-settings', 'use-collection'] as $permission) {
        expect(Permission::where('name', $permission)->exists())->toBeTrue();
    }

    expect(Role::where('name', 'super-admin')->exists())->toBeTrue();
    expect(Role::where('name', 'user')->exists())->toBeTrue();
});

test('the user role carries the use-collection permission', function () {
    config(['tcgvault.admin_email' => 'cativo23.kt@gmail.com']);
    config(['tcgvault.admin_password' => 'a-real-password']);

    $this->seed();

    $role = Role::where('name', 'user')->firstOrFail();
    expect($role->hasPermissionTo('use-collection'))->toBeTrue();
});

test('re-seeding does not duplicate permissions or roles', function () {
    config(['tcgvault.admin_email' => 'cativo23.kt@gmail.com']);
    config(['tcgvault.admin_password' => 'a-real-password']);

    $this->seed();
    $this->seed();

    expect(Permission::where('name', 'use-collection')->count())->toBe(1);
    expect(Role::where('name', 'user')->count())->toBe(1);
});
