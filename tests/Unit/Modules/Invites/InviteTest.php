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
