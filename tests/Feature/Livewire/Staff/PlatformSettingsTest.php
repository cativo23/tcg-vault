<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Settings\Models\Setting;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

test('a guest cannot reach the platform settings page', function () {
    $this->get('/staff/settings')->assertRedirect('/login');
});

test('a regular user is forbidden from the platform settings route', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/staff/settings')->assertForbidden();
});

test('an admin can toggle registration mode from the settings page', function () {
    Permission::create(['name' => 'manage-platform-settings']);
    $admin = User::factory()->create();
    $admin->givePermissionTo('manage-platform-settings');

    Livewire::actingAs($admin)
        ->test('staff.platform-settings')
        ->set('registrationOpen', true)
        ->call('save');

    expect(Setting::get('registration.open'))->toBeTrue();
});

test('a regular user cannot toggle registration mode', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test('staff.platform-settings')
        ->assertForbidden();

    expect(Setting::get('registration.open', 'unset'))->toBe('unset');
});
