<?php

declare(strict_types=1);

use App\Modules\Invites\Models\Invite;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

test('a guest cannot reach the invite manager', function () {
    $this->get('/staff/invites')->assertRedirect('/login');
});

test('a regular user is forbidden from the invite manager route', function () {
    $user = \App\Models\User::factory()->create();

    $this->actingAs($user)->get('/staff/invites')->assertForbidden();
});

test('an admin can create an invite', function () {
    Permission::create(['name' => 'manage-invites']);
    $admin = \App\Models\User::factory()->create();
    $admin->givePermissionTo('manage-invites');

    Livewire::actingAs($admin)
        ->test('staff.invite-manager')
        ->set('email', 'someone@example.com')
        ->call('createInvite')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('invites', [
        'email' => 'someone@example.com',
        'created_by' => $admin->id,
    ]);
});

test('a regular user cannot even mount the invite manager component directly', function () {
    $user = \App\Models\User::factory()->create();

    Livewire::actingAs($user)
        ->test('staff.invite-manager')
        ->assertForbidden();

    expect(Invite::count())->toBe(0);
});

test('an admin can revoke an unused invite', function () {
    Permission::create(['name' => 'manage-invites']);
    $admin = \App\Models\User::factory()->create();
    $admin->givePermissionTo('manage-invites');
    $invite = Invite::factory()->create();

    Livewire::actingAs($admin)
        ->test('staff.invite-manager')
        ->call('revokeInvite', $invite->id);

    expect($invite->refresh()->revoked_at)->not->toBeNull();
});

test('an admin cannot invite an email that already has an account', function () {
    Permission::create(['name' => 'manage-invites']);
    $admin = \App\Models\User::factory()->create();
    $admin->givePermissionTo('manage-invites');
    $existing = \App\Models\User::factory()->create(['email' => 'taken@example.com']);

    Livewire::actingAs($admin)
        ->test('staff.invite-manager')
        ->set('email', 'taken@example.com')
        ->call('createInvite')
        ->assertHasErrors('email');

    expect(Invite::count())->toBe(0);
});

test('an admin cannot double-invite an email with an existing usable invite', function () {
    Permission::create(['name' => 'manage-invites']);
    $admin = \App\Models\User::factory()->create();
    $admin->givePermissionTo('manage-invites');
    Invite::factory()->create(['email' => 'pending@example.com']);

    Livewire::actingAs($admin)
        ->test('staff.invite-manager')
        ->set('email', 'pending@example.com')
        ->call('createInvite')
        ->assertHasErrors('email');

    expect(Invite::count())->toBe(1);
});

test('a regular user cannot revoke an invite', function () {
    $user = \App\Models\User::factory()->create();
    $invite = Invite::factory()->create();

    Livewire::actingAs($user)
        ->test('staff.invite-manager')
        ->assertForbidden();

    expect($invite->refresh()->revoked_at)->toBeNull();
});
