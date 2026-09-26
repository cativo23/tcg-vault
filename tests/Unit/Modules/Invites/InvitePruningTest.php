<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Invites\Models\Invite;

test('an unaccepted invite is deleted once it has been expired or revoked for 30 days, and no sooner', function () {
    $this->travelTo(now()->startOfDay()->addHours(12));
    $days = Invite::PRUNE_AFTER_DAYS;

    $expiredLongAgo = Invite::factory()->create(['expires_at' => now()->subDays($days + 1)]);
    $revokedLongAgo = Invite::factory()->create(['revoked_at' => now()->subDays($days + 1)]);
    $expiredRecently = Invite::factory()->create(['expires_at' => now()->subDays($days - 1)]);
    $revokedRecently = Invite::factory()->create(['revoked_at' => now()->subDays($days - 1)]);
    $pending = Invite::factory()->create();

    $this->artisan('model:prune', ['--model' => [Invite::class]])->assertSuccessful();

    expect($days)->toBe(30)
        ->and(Invite::pluck('id')->sort()->values()->all())->toBe(
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
