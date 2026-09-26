<?php

declare(strict_types=1);

use App\Models\ModerationAction;
use App\Modules\Invites\Models\Invite;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;

function scheduledEvent(string $needle): ?Event
{
    return collect(app(Schedule::class)->events())
        ->first(fn (Event $event) => str_contains((string) $event->command, $needle));
}

test('expired password reset tokens are cleared daily', function () {
    expect(scheduledEvent('auth:clear-resets')?->expression)->toBe('0 0 * * *');
});

test('old unaccepted invites are pruned daily', function () {
    // model:prune only discovers models under app/Models and silently drops
    // a class that doesn't exist, so the exact class has to be named.
    $event = scheduledEvent('model:prune');

    expect($event?->expression)->toBe('0 0 * * *')
        ->and((string) $event?->command)->toContain("--model='".Invite::class."'");
});

test('old moderation records are pruned daily', function () {
    expect((string) scheduledEvent('model:prune')?->command)->toContain("--model='".ModerationAction::class."'");
});
