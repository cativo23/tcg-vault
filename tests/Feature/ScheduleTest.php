<?php

use Illuminate\Console\Scheduling\Schedule;

function scheduledCommands(): array
{
    return collect(app(Schedule::class)->events())
        ->map(fn ($event) => (string) $event->command)
        ->all();
}

test('expired password reset tokens are cleared daily', function () {
    expect(collect(scheduledCommands())->contains(fn (string $c) => str_contains($c, 'auth:clear-resets')))->toBeTrue();
});

test('old unaccepted invites are pruned daily', function () {
    // model:prune only discovers models under app/Models, so Invite (in a
    // module) has to be named explicitly.
    expect(collect(scheduledCommands())->contains(
        fn (string $c) => str_contains($c, 'model:prune') && str_contains($c, 'Invite')
    ))->toBeTrue();
});
