<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Collection\Models\Collection;
use App\Modules\Collection\Scopes\TenantScope;

test('seeding generates a username from the admin email and a public default collection', function () {
    config(['tcgvault.admin_email' => 'cativo23.kt@gmail.com']);
    config(['tcgvault.admin_password' => 'a-real-password']);

    $this->seed();

    $user = User::where('email', 'cativo23.kt@gmail.com')->firstOrFail();
    expect($user->username)->toBe('cativo23kt');

    $collection = Collection::withoutGlobalScope(TenantScope::class)
        ->where('user_id', $user->id)->firstOrFail();
    expect($collection->is_public)->toBeTrue();
});

test('an explicit admin_username config overrides the email-derived default', function () {
    config(['tcgvault.admin_email' => 'cativo23.kt@gmail.com']);
    config(['tcgvault.admin_password' => 'a-real-password']);
    config(['tcgvault.admin_username' => 'cativo23']);

    $this->seed();

    $user = User::where('email', 'cativo23.kt@gmail.com')->firstOrFail();
    expect($user->username)->toBe('cativo23');
});

test('the seeded bootstrap admin gets the super-admin role', function () {
    config(['tcgvault.admin_email' => 'cativo23.kt@gmail.com']);
    config(['tcgvault.admin_password' => 'a-real-password']);

    $this->seed();

    $user = User::where('email', 'cativo23.kt@gmail.com')->firstOrFail();
    expect($user->hasRole('super-admin'))->toBeTrue();
});

test('re-seeding does not duplicate the bootstrap admins super-admin role assignment', function () {
    config(['tcgvault.admin_email' => 'cativo23.kt@gmail.com']);
    config(['tcgvault.admin_password' => 'a-real-password']);

    $this->seed();
    $this->seed();

    $user = User::where('email', 'cativo23.kt@gmail.com')->firstOrFail();
    expect($user->roles()->where('name', 'super-admin')->count())->toBe(1);
});
