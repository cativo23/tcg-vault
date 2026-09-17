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

test('the invite registration page shows a brand header so an invitee knows where they landed', function () {
    $invite = Invite::factory()->create();

    $this->get(signedInviteUrl($invite))
        ->assertOk()
        ->assertSee("You're invited")
        ->assertSee('Create your account to start tracking your collection.');
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
    // User::$fillable deliberately excludes email_verified_at (mass
    // assignment must never let an arbitrary write self-verify an
    // email) — User::create() silently drops it rather than persisting
    // it, so this must be set through an explicit, non-mass-assignment
    // write instead.
    expect($user->fresh()->email_verified_at)->not->toBeNull();

    $invite->refresh();
    expect($invite->used_at)->not->toBeNull();
    expect($invite->accepted_by)->toBe($user->id);
});

test('a client cannot swap invite to hijack a different invite — the property is locked', function () {
    $myInvite = Invite::factory()->create(['email' => 'me@example.com']);
    $othersInvite = Invite::factory()->create(['email' => 'victim@example.com']);

    // Mounted with MY own legitimately signed invite. The real
    // client-side attack this guards against: Livewire's update
    // protocol otherwise lets a client set ANY public property
    // directly, regardless of what wire:model binds to in the blade —
    // which would let a live session swap which invite a later
    // register() call redeems, without ever needing a fresh signed URL
    // for that other invite. #[Locked] rejects this outright.
    \Livewire\Livewire::test(\App\Livewire\Auth\InviteRegistration::class, [
        'invite' => $myInvite,
        'hash' => sha1($myInvite->email),
    ])->set('invite', $othersInvite);
})->throws(\Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException::class);

test('a client cannot overwrite the locked hash either', function () {
    $invite = Invite::factory()->create();

    \Livewire\Livewire::test(\App\Livewire\Auth\InviteRegistration::class, [
        'invite' => $invite,
        'hash' => sha1($invite->email),
    ])->set('hash', 'anything');
})->throws(\Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException::class);

test('an invite whose email now belongs to an existing account is rejected instead of raising a database error', function () {
    // The invite-creation check in InviteManager only rules out an
    // existing account at the moment the invite is issued — an invite
    // is valid for days afterward, long enough for that same email to
    // land on an account some other way in the meantime (the public
    // register route re-opening, or an admin creating the account
    // directly). Redemption must re-check, not assume the email is
    // still free just because the invite itself is still usable.
    Role::create(['name' => 'user']);
    $invite = Invite::factory()->create(['email' => 'already-registered@example.com']);
    User::factory()->create(['email' => 'already-registered@example.com']);

    \Livewire\Livewire::test(\App\Livewire\Auth\InviteRegistration::class, [
        'invite' => $invite,
        'hash' => sha1($invite->email),
    ])->assertForbidden();

    expect($invite->refresh()->used_at)->toBeNull();
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
