<?php

use App\Support\SensitiveInput;
use App\Telescope\ScrubbingRequestWatcher;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Laravel\Telescope\Telescope;
use Laravel\Telescope\Watchers\RequestWatcher;

afterEach(function () {
    Telescope::stopRecording();
    Telescope::$entriesQueue = [];
});

/** Records one failed Livewire request through the watcher and returns the stored entry's content. */
function recordFailedLivewireRequest(array $body): array
{
    $request = Request::create('/livewire/update', 'POST', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode($body));
    $response = new Response('Server Error', 500);

    Telescope::startRecording();
    (new ScrubbingRequestWatcher(['size_limit' => 64]))->recordRequest(new RequestHandled($request, $response));

    return end(Telescope::$entriesQueue)->content;
}

test('a password typed into a Livewire form never reaches a Telescope request entry', function () {
    $content = recordFailedLivewireRequest([
        'components' => [[
            'snapshot' => json_encode(['data' => ['form' => [['email' => 'ash@example.com', 'password' => 'hunter2'], ['s' => 'form']]]]),
            'updates' => ['form.password' => 'hunter2'],
            'calls' => [['method' => 'login', 'params' => []]],
        ]],
    ]);

    expect(json_encode($content['payload']))->not->toContain('hunter2')
        ->and($content['payload']['components'][0]['updates']['form.password'])->toBe(SensitiveInput::MASK)
        ->and(json_encode($content['payload']))->toContain('ash@example.com');
});

test('the app registers the scrubbing watcher in place of the stock request watcher', function () {
    $watchers = array_keys(config('telescope.watchers'));

    expect($watchers)->toContain(ScrubbingRequestWatcher::class)
        ->not->toContain(RequestWatcher::class);
});
