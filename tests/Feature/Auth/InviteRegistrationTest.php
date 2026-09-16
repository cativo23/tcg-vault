<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Invites\Models\Invite;
use Illuminate\Support\Facades\URL;
use Spatie\Permission\Models\Role;

function signedInviteUrl(Invite $invite): string
{
    return URL::temporarySignedRoute('invite.accept', $invite->expires_at, [
        'invite' => $invite->id,
        'hash' => sha1($invite->email),
    ]);
}

test('a usable invite link shows the registration form with the email locked', function () {
    $invite = Invite::factory()->create(['email' => 'invitee@example.com']);

    $this->get(signedInviteUrl($invite))
        ->assertOk()
        ->assertSee('invitee@example.com');
});

test('an expired invite link is rejected', function () {
    $invite = Invite::factory()->create(['expires_at' => now()->addDay()]);
    $url = signedInviteUrl($invite);
    $invite->update(['expires_at' => now()->subDay()]);

    $this->get($url)->assertForbidden();
});

test('a used invite link is rejected', function () {
    $invite = Invite::factory()->create(['used_at' => now()]);

    $this->get(signedInviteUrl($invite))->assertForbidden();
});

test('a revoked invite link is rejected', function () {
    $invite = Invite::factory()->create(['revoked_at' => now()]);

    $this->get(signedInviteUrl($invite))->assertForbidden();
});

test('a tampered signature is rejected', function () {
    $invite = Invite::factory()->create();
    $url = signedInviteUrl($invite).'tampered';

    $this->get($url)->assertForbidden();
});

test('completing an invite creates the user with the user role, marks the invite used, and works even with public registration closed', function () {
    Role::create(['name' => 'user']);
    config(['tcgvault.allow_registration' => false]);

    $invite = Invite::factory()->create(['email' => 'invitee@example.com']);

    \Livewire\Livewire::test(\App\Livewire\Auth\InviteRegistration::class, [
        'invite' => $invite,
        'hash' => sha1($invite->email),
    ])
        ->set('name', 'Invitee Person')
        ->set('username', 'invitee')
        ->set('password', 'a-real-password')
        ->set('password_confirmation', 'a-real-password')
        ->call('register')
        ->assertRedirect();

    $user = User::where('email', 'invitee@example.com')->firstOrFail();
    expect($user->hasRole('user'))->toBeTrue();

    $invite->refresh();
    expect($invite->used_at)->not->toBeNull();
    expect($invite->accepted_by)->toBe($user->id);
});

test('an invite already used cannot be used again', function () {
    Role::create(['name' => 'user']);
    $invite = Invite::factory()->create(['used_at' => now(), 'accepted_by' => User::factory()->create()->id]);

    \Livewire\Livewire::test(\App\Livewire\Auth\InviteRegistration::class, [
        'invite' => $invite,
        'hash' => sha1($invite->email),
    ])->assertForbidden();

    expect(User::where('email', $invite->email)->exists())->toBeFalse();
});
