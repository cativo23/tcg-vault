<?php

use App\Models\User;
use Livewire\Volt\Volt;

test('profile page is displayed', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    $response = $this->get('/profile');

    $response
        ->assertOk()
        ->assertSeeVolt('profile.update-profile-information-form')
        ->assertSeeVolt('profile.update-password-form')
        ->assertSeeVolt('profile.delete-user-form');
});

test('the profile page uses the apps own display heading, not a generic Breeze one', function () {
    // Was the one page left rendering <x-slot name="header"> with plain
    // Tailwind text-xl — every other admin screen (Collection, Platform
    // Settings) puts a .nw-display .nw-h1 heading directly in the body.
    $user = User::factory()->create();
    $this->actingAs($user);

    $response = $this->get('/profile');

    $response->assertOk();
    $response->assertSee('class="nw-display nw-h1 nw-h1--sm mb-4"', false);
    $response->assertDontSee('text-xl font-semibold', false);
});

test('profile information can be updated', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    $component = Volt::test('profile.update-profile-information-form')
        ->set('name', 'Test User')
        ->set('email', 'test@example.com')
        ->call('updateProfileInformation');

    $component
        ->assertHasNoErrors()
        ->assertNoRedirect();

    $user->refresh();

    $this->assertSame('Test User', $user->name);
    $this->assertSame('test@example.com', $user->email);
    $this->assertNull($user->email_verified_at);
});

test('email verification status is unchanged when the email address is unchanged', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    $component = Volt::test('profile.update-profile-information-form')
        ->set('name', 'Test User')
        ->set('email', $user->email)
        ->call('updateProfileInformation');

    $component
        ->assertHasNoErrors()
        ->assertNoRedirect();

    $this->assertNotNull($user->refresh()->email_verified_at);
});

test('username can be updated within length and character limits', function () {
    $user = User::factory()->create(['username' => 'oldname']);
    $this->actingAs($user);

    Volt::test('profile.update-profile-information-form')
        ->set('username', 'new-handle')
        ->call('updateProfileInformation');

    expect($user->refresh()->username)->toBe('new-handle');
});

test('a username shorter than 3 characters is rejected', function () {
    $user = User::factory()->create(['username' => 'oldname']);
    $this->actingAs($user);

    Volt::test('profile.update-profile-information-form')
        ->set('username', 'ab')
        ->call('updateProfileInformation')
        ->assertHasErrors('username');

    expect($user->refresh()->username)->toBe('oldname');
});

test('a username with an illegal character is rejected', function () {
    $user = User::factory()->create(['username' => 'oldname']);
    $this->actingAs($user);

    Volt::test('profile.update-profile-information-form')
        ->set('username', 'not_valid!')
        ->call('updateProfileInformation')
        ->assertHasErrors('username');

    expect($user->refresh()->username)->toBe('oldname');
});

test('a username matching one of the apps own route segments is rejected', function () {
    $user = User::factory()->create(['username' => 'oldname']);
    $this->actingAs($user);

    Volt::test('profile.update-profile-information-form')
        ->set('username', 'admin')
        ->call('updateProfileInformation')
        ->assertHasErrors('username');

    expect($user->refresh()->username)->toBe('oldname');
});

test('a username already taken by another user is rejected', function () {
    User::factory()->create(['username' => 'taken']);
    $user = User::factory()->create(['username' => 'oldname']);
    $this->actingAs($user);

    Volt::test('profile.update-profile-information-form')
        ->set('username', 'taken')
        ->call('updateProfileInformation')
        ->assertHasErrors('username');

    expect($user->refresh()->username)->toBe('oldname');
});

test('user can delete their account', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    $component = Volt::test('profile.delete-user-form')
        ->set('password', 'password')
        ->call('deleteUser');

    $component
        ->assertHasNoErrors()
        ->assertRedirect('/');

    $this->assertGuest();
    $this->assertNull($user->fresh());
});

test('correct password must be provided to delete account', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    $component = Volt::test('profile.delete-user-form')
        ->set('password', 'wrong-password')
        ->call('deleteUser');

    $component
        ->assertHasErrors('password')
        ->assertNoRedirect();

    $this->assertNotNull($user->fresh());
});
