<?php

declare(strict_types=1);

use App\Models\User;
use Database\Seeders\PermissionSeeder;

test('manage-catalog-prices exists but a regular collector does not get it', function () {
    $this->seed(PermissionSeeder::class);

    $collector = User::factory()->create();
    $collector->assignRole('user');

    expect($collector->can('use-collection'))->toBeTrue();
    expect($collector->can('manage-catalog-prices'))->toBeFalse();
});

test('a super-admin may set catalog prices without being granted it explicitly', function () {
    $this->seed(PermissionSeeder::class);

    $admin = User::factory()->create();
    $admin->assignRole('super-admin');

    expect($admin->can('manage-catalog-prices'))->toBeTrue();
});

test('a collector granted manage-catalog-prices may set catalog prices', function () {
    $this->seed(PermissionSeeder::class);

    $curator = User::factory()->create();
    $curator->givePermissionTo('manage-catalog-prices');

    expect($curator->can('manage-catalog-prices'))->toBeTrue();
});
