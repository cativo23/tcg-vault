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
