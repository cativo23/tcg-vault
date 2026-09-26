<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Invites\Models\Invite;
use Illuminate\Support\Facades\URL;

// Laravel falls back to its default whitescreen error page for any status
// without a matching view under resources/views/errors/ — these pin the
// brand layout (guest.blade.php: --bone background, Archivo/Martian Mono,
// Vault Line favicon) onto the codes a visitor can actually hit, instead of
// the framework default.

test('a 403 (e.g. a revoked invite link) renders the branded error page', function () {
    $invite = Invite::factory()->create(['revoked_at' => now()]);

    $url = URL::temporarySignedRoute('invite.accept', $invite->expires_at, [
        'invite' => $invite->id,
        'hash' => sha1($invite->email),
    ]);

    $this->get($url)
        ->assertForbidden()
        ->assertSee('This invite is no longer valid.')
        ->assertSee('tcg-vault', escape: false);
});

test('a 404 renders the branded error page', function () {
    $this->get('/this-route-does-not-exist')
        ->assertNotFound()
        ->assertSee('Page not found')
        ->assertSee('tcg-vault', escape: false);
});

test('a guest hitting a 404 is sent home, not asked to log in', function () {
    // A stale or mistyped gallery link is a normal, expected 404 on a
    // public site — the visitor may have no account to log into at all.
    $this->get('/this-route-does-not-exist')
        ->assertNotFound()
        ->assertSee('Go home')
        ->assertDontSee('Back to login')
        ->assertSee(route('home'), false);
});

test('an authenticated user hitting a 404 is sent back to their own collection', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/this-route-does-not-exist')
        ->assertNotFound()
        ->assertSee('Back to your collection')
        ->assertDontSee('Go home')
        ->assertSee(route('admin.collection.index'), false);
});

test('the 419 error view is branded', function () {
    $this->view('errors.419')
        ->assertSee('Page expired')
        ->assertSee('tcg-vault', escape: false);
});

test('the 500 error view is branded', function () {
    $this->view('errors.500')
        ->assertSee('Something went wrong')
        ->assertSee('tcg-vault', escape: false);
});
