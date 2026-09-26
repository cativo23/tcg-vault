<?php

use App\Support\SensitiveInput;
use App\Support\SentryEventScrubber;
use Sentry\Breadcrumb;
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

test('a password in a Livewire breadcrumb is masked before the report is sent', function () {
    // sentry-laravel records a component's public properties on hydrate; a
    // Livewire Form is an object, so it arrives as one here.
    $form = new class
    {
        public string $email = 'ash@example.com';

        public string $password = 'hunter1';
    };

    $event = Event::createEvent();
    $event->setBreadcrumb([
        new Breadcrumb(Breadcrumb::LEVEL_INFO, Breadcrumb::TYPE_DEFAULT, 'livewire', 'Component hydrate: pages.auth.login', ['form' => $form]),
        new Breadcrumb(Breadcrumb::LEVEL_INFO, Breadcrumb::TYPE_DEFAULT, 'livewire', 'Component mount: pages.auth.reset-password', ['token' => 'reset-abc']),
    ]);

    $sent = SentryEventScrubber::beforeSend($event);
    $metadata = array_map(fn (Breadcrumb $b) => $b->getMetadata(), $sent->getBreadcrumbs());

    expect(json_encode($metadata))->not->toContain('hunter1')->not->toContain('reset-abc')
        ->and($metadata[0]['form'])->toBe(['email' => 'ash@example.com', 'password' => SensitiveInput::MASK])
        ->and($sent->getBreadcrumbs()[0]->getMessage())->toBe('Component hydrate: pages.auth.login');
});

test('an event with no request data is sent unchanged', function () {
    $event = Event::createEvent();

    expect(SentryEventScrubber::beforeSend($event)->getRequest())->toBe([]);
});

test('the Sentry client is configured to run the scrubber before sending', function () {
    expect(config('sentry.before_send'))->toBe([SentryEventScrubber::class, 'beforeSend']);
});
