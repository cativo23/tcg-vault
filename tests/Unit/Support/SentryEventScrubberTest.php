<?php

use App\Support\SensitiveInput;
use App\Support\SentryEventScrubber;
use Sentry\Event;

test('a password in the request body of an error report is masked before it is sent', function () {
    $event = Event::createEvent();
    $event->setRequest([
        'url' => 'https://tcgvault.cativo.dev/livewire/update',
        'method' => 'POST',
        'data' => [
            'components' => [[
                'snapshot' => json_encode(['data' => ['form' => [['password' => 'hunter2'], ['s' => 'form']]]]),
                'updates' => ['form.password' => 'hunter2', 'form.email' => 'ash@example.com'],
            ]],
        ],
    ]);

    $sent = SentryEventScrubber::beforeSend($event);

    expect(json_encode($sent->getRequest()))->not->toContain('hunter2')
        ->and($sent->getRequest()['data']['components'][0]['updates']['form.password'])->toBe(SensitiveInput::MASK)
        ->and($sent->getRequest()['data']['components'][0]['updates']['form.email'])->toBe('ash@example.com')
        ->and($sent->getRequest()['url'])->toBe('https://tcgvault.cativo.dev/livewire/update');
});

test('an event with no request data is sent unchanged', function () {
    $event = Event::createEvent();

    expect(SentryEventScrubber::beforeSend($event)->getRequest())->toBe([]);
});

test('the Sentry client is configured to run the scrubber before sending', function () {
    expect(config('sentry.before_send'))->toBe([SentryEventScrubber::class, 'beforeSend']);
});
