<?php

use App\Models\User;
use App\Modules\Invites\Models\Invite;

test('an unaccepted invite is deleted a month after it expired or was revoked, and no sooner', function () {
    $expiredLongAgo = Invite::factory()->create(['expires_at' => now()->subDays(31)]);
    $revokedLongAgo = Invite::factory()->create(['revoked_at' => now()->subDays(31)]);
    $expiredRecently = Invite::factory()->create(['expires_at' => now()->subDays(5)]);
    $revokedRecently = Invite::factory()->create(['revoked_at' => now()->subDays(5)]);
    $pending = Invite::factory()->create();

    $this->artisan('model:prune', ['--model' => [Invite::class]])->assertSuccessful();

    expect(Invite::pluck('id')->sort()->values()->all())->toBe(
        collect([$expiredRecently->id, $revokedRecently->id, $pending->id])->sort()->values()->all()
    );
});

test('an accepted invite is kept however old it is, since it records who invited whom', function () {
    $invite = Invite::factory()->create([
        'expires_at' => now()->subYear(),
        'used_at' => now()->subYear()->addDay(),
        'accepted_by' => User::factory()->create()->id,
    ]);

    $this->artisan('model:prune', ['--model' => [Invite::class]])->assertSuccessful();

    expect(Invite::whereKey($invite->id)->exists())->toBeTrue();
});
