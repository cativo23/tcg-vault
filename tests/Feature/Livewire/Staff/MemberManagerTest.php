<?php

declare(strict_types=1);

use App\Models\User;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

function staffMember(): User
{
    Permission::findOrCreate('manage-members');
    $staff = User::factory()->create(['username' => 'staff-sue']);
    $staff->givePermissionTo('manage-members');

    return $staff;
}

test('a guest cannot reach the members page', function () {
    $this->get('/staff/members')->assertRedirect('/login');
});

test('a regular member is forbidden from the members page', function () {
    $this->actingAs(User::factory()->create())->get('/staff/members')->assertForbidden();
});

test('a regular member cannot mount the component or suspend anyone through it', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)->test('staff.member-manager')->assertForbidden();
});

test('staff see every member with their status', function () {
    $staff = staffMember();
    User::factory()->create(['username' => 'active-ash']);
    User::factory()->create(['username' => 'suspended-sam', 'suspended_at' => now()]);

    Livewire::actingAs($staff)
        ->test('staff.member-manager')
        ->assertSee('active-ash')
        ->assertSee('suspended-sam')
        ->assertSee('Suspended');
});

test('staff can suspend a member and lift the suspension', function () {
    $staff = staffMember();
    $member = User::factory()->create();

    $component = Livewire::actingAs($staff)->test('staff.member-manager')->call('suspend', $member->id);
    expect($member->fresh()->isSuspended())->toBeTrue();

    $component->call('unsuspend', $member->id);
    expect($member->fresh()->isSuspended())->toBeFalse();
});

test('staff cannot suspend themselves', function () {
    $staff = staffMember();

    Livewire::actingAs($staff)->test('staff.member-manager')
        ->call('suspend', $staff->id)
        ->assertHasErrors(['members']);

    expect($staff->fresh()->isSuspended())->toBeFalse();
});

test('staff cannot suspend or delete a super-admin', function () {
    $staff = staffMember();
    $admin = User::factory()->create(['username' => 'the-admin']);
    $admin->assignRole(Role::findOrCreate('super-admin'));

    Livewire::actingAs($staff)->test('staff.member-manager')
        ->call('suspend', $admin->id)
        ->assertHasErrors(['members'])
        ->call('confirmDelete', $admin->id)
        ->set('deleteConfirmation', 'the-admin')
        ->call('deleteMember')
        ->assertHasErrors(['members']);

    expect($admin->fresh())->not->toBeNull()
        ->and($admin->fresh()->isSuspended())->toBeFalse();
});

test('deleting a member requires typing their username', function () {
    $staff = staffMember();
    $member = User::factory()->create(['username' => 'delete-dee']);

    Livewire::actingAs($staff)->test('staff.member-manager')
        ->call('confirmDelete', $member->id)
        ->set('deleteConfirmation', 'someone-else')
        ->call('deleteMember')
        ->assertHasErrors(['deleteConfirmation']);

    expect(User::whereKey($member->id)->exists())->toBeTrue();
});

test('typing the member’s username deletes their account', function () {
    $staff = staffMember();
    $member = User::factory()->create(['username' => 'delete-dee']);

    Livewire::actingAs($staff)->test('staff.member-manager')
        ->call('confirmDelete', $member->id)
        ->set('deleteConfirmation', 'delete-dee')
        ->call('deleteMember')
        ->assertHasNoErrors();

    expect(User::whereKey($member->id)->exists())->toBeFalse();
});

test('staff cannot delete themselves from the members page', function () {
    $staff = staffMember();

    Livewire::actingAs($staff)->test('staff.member-manager')
        ->call('confirmDelete', $staff->id)
        ->set('deleteConfirmation', 'staff-sue')
        ->call('deleteMember')
        ->assertHasErrors(['members']);

    expect(User::whereKey($staff->id)->exists())->toBeTrue();
});

test('staff cannot suspend or delete another staff member; only a super-admin can', function () {
    $staff = staffMember();
    $otherStaff = User::factory()->create(['username' => 'staff-bo']);
    $otherStaff->givePermissionTo('manage-members');

    Livewire::actingAs($staff)->test('staff.member-manager')
        ->call('suspend', $otherStaff->id)
        ->assertHasErrors(['members'])
        ->call('confirmDelete', $otherStaff->id)
        ->set('deleteConfirmation', 'staff-bo')
        ->call('deleteMember')
        ->assertHasErrors(['members']);

    expect($otherStaff->fresh()->isSuspended())->toBeFalse();

    $admin = User::factory()->create();
    $admin->assignRole(Role::findOrCreate('super-admin'));

    Livewire::actingAs($admin)->test('staff.member-manager')->call('suspend', $otherStaff->id)->assertHasNoErrors();

    expect($otherStaff->fresh()->isSuspended())->toBeTrue();
});
