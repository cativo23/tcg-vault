<?php

declare(strict_types=1);

use App\Models\ModerationAction;
use App\Modules\Invites\Models\Invite;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Http;

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

test('the Activity value series is queued hourly, with a lock that expires within the hour', function () {
    $event = scheduledEvent('gallery:warm-value-series');

    expect($event?->expression)->toBe('0 * * * *')
        ->and($event?->withoutOverlapping)->toBeTrue()
        ->and($event?->expiresAt)->toBe(50);
});

test('price coverage is reported nightly after the tcgcsv run has finished', function () {
    // The tcgcsv job starts at 20:30 UTC and takes a few minutes; the report must read its result.
    expect(scheduledEvent('catalog:report-price-coverage')?->expression)->toBe('45 21 * * *');
});

test('a failing warm-up dispatch alerts Discord', function () {
    config(['services.discord.alert_webhook_url' => 'https://discord.com/api/webhooks/test']);
    Http::fake();
    $event = scheduledEvent('gallery:warm-value-series');
    $event->exitCode = 1;

    $event->callAfterCallbacks(app());

    Http::assertSent(fn ($request) => str_contains($request['content'], 'gallery:warm-value-series'));
});
