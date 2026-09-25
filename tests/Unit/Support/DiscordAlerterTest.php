<?php

declare(strict_types=1);

use App\Support\DiscordAlerter;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

test('posts the message to the configured webhook', function () {
    config(['services.discord.alert_webhook_url' => 'https://discord.com/api/webhooks/test']);
    Http::fake();

    app(DiscordAlerter::class)->send('the daily sync did not run');

    Http::assertSent(function ($request) {
        return $request->url() === 'https://discord.com/api/webhooks/test'
            && $request['content'] === 'the daily sync did not run';
    });
});

test('sends nothing when no webhook is configured, so alerting stays opt-in', function () {
    config(['services.discord.alert_webhook_url' => null]);
    Http::fake();

    app(DiscordAlerter::class)->send('should never leave the process');

    Http::assertNothingSent();
});

test('a failed webhook request is logged, never thrown, so an alert can never take down the caller', function () {
    config(['services.discord.alert_webhook_url' => 'https://discord.com/api/webhooks/test']);
    Http::fake(['discord.com/*' => Http::response('bad gateway', 502)]);
    Log::shouldReceive('warning')
        ->once()
        ->with('Discord alert failed to send', Mockery::type('array'));

    app(DiscordAlerter::class)->send('anything');
})->throwsNoExceptions();
