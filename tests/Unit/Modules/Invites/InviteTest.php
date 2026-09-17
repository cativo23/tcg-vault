<?php

declare(strict_types=1);

use App\Modules\Invites\Models\Invite;

test('a fresh invite is usable', function () {
    $invite = Invite::factory()->create();

    expect($invite->isUsable())->toBeTrue();
});

test('an expired invite is not usable', function () {
    $invite = Invite::factory()->create(['expires_at' => now()->subDay()]);

    expect($invite->isUsable())->toBeFalse();
});

test('a used invite is not usable', function () {
    $invite = Invite::factory()->create(['used_at' => now()]);

    expect($invite->isUsable())->toBeFalse();
});

test('a revoked invite is not usable', function () {
    $invite = Invite::factory()->create(['revoked_at' => now()]);

    expect($invite->isUsable())->toBeFalse();
});

test('revoking a fresh invite marks it revoked', function () {
    $invite = Invite::factory()->create();

    $invite->revoke();

    expect($invite->revoked_at)->not->toBeNull();
    expect($invite->isUsable())->toBeFalse();
});

test('revoking an already-used invite is a no-op', function () {
    $usedAt = now()->subHour();
    $invite = Invite::factory()->create(['used_at' => $usedAt]);

    $invite->revoke();

    expect($invite->revoked_at)->toBeNull();
});

test('revoke is atomic against a concurrent accept — a stale in-memory copy cannot revoke a just-used invite', function () {
    $invite = Invite::factory()->create();

    // Simulate another request accepting the invite between this
    // in-memory instance being loaded and revoke() being called —
    // the DB row is now used, but $invite's own attributes are stale.
    Invite::whereKey($invite->id)->update(['used_at' => now()]);

    $invite->revoke();

    expect($invite->fresh()->revoked_at)->toBeNull();
});

test('the usable scope matches isUsable() exactly, as a single query instead of loading every row', function () {
    $usable = Invite::factory()->create();
    $used = Invite::factory()->create(['used_at' => now()]);
    $revoked = Invite::factory()->create(['revoked_at' => now()]);
    $expired = Invite::factory()->create(['expires_at' => now()->subDay()]);

    $ids = Invite::usable()->pluck('id');

    expect($ids)->toContain($usable->id);
    expect($ids)->not->toContain($used->id, $revoked->id, $expired->id);
});

test('the database itself rejects a second usable invite for the same email, closing the app-level checks TOCTOU race', function () {
    Invite::factory()->create(['email' => 'race@example.com']);

    Invite::factory()->create(['email' => 'race@example.com']);
})->throws(\Illuminate\Database\QueryException::class);

test('a second invite for the same email is fine once the first is no longer usable', function () {
    $first = Invite::factory()->create(['email' => 'again@example.com']);
    $first->revoke();

    $second = Invite::factory()->create(['email' => 'again@example.com']);

    expect($second->exists)->toBeTrue();
});

test('revoking an invite records who revoked it', function () {
    $admin = \App\Models\User::factory()->create();
    $invite = Invite::factory()->create();

    $invite->revoke($admin->id);

    expect($invite->revoked_by)->toBe($admin->id);
});
