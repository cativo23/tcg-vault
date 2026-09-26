<?php

declare(strict_types=1);

use App\Livewire\FeedbackForm;
use App\Mail\FeedbackSubmitted;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    config(['tcgvault.feedback_email' => 'owner@example.test']);
    Mail::fake();
});

test('the logged-in top bar and mobile menu both offer Feedback', function () {
    Role::findOrCreate('user')->givePermissionTo(Permission::findOrCreate('use-collection'));
    $user = User::factory()->create();
    $user->assignRole('user');

    $html = $this->actingAs($user)->get(route('admin.collection.index'))->assertOk()->getContent();

    expect(substr_count($html, "\$dispatch('open-modal', 'feedback')"))->toBe(2)
        ->and($html)->toContain('wire:submit="send"');
});

test('the public home page has no feedback form', function () {
    $this->get('/')->assertOk()->assertDontSee('wire:submit="send"', false);
});

test('sending emails the owner the message, type, username and page, with the user as reply-to', function () {
    $user = User::factory()->create(['username' => 'ash', 'email' => 'ash@example.test']);
    $this->actingAs($user);

    Livewire::test(FeedbackForm::class)
        ->set('type', 'bug')
        ->set('message', 'The export button does nothing on my phone.')
        ->set('pageUrl', url('/admin?page=2'))
        ->call('send')
        ->assertHasNoErrors()
        ->assertDispatched('feedback-sent')
        ->assertSet('message', '');

    Mail::assertQueued(FeedbackSubmitted::class, function (FeedbackSubmitted $mail) {
        return $mail->hasTo('owner@example.test')
            && $mail->hasReplyTo('ash@example.test')
            && $mail->type === 'bug'
            && $mail->body === 'The export button does nothing on my phone.'
            && $mail->username === 'ash'
            && $mail->pagePath === '/admin?page=2';
    });
});

test('a message is required and has a length limit', function (string $message) {
    $this->actingAs(User::factory()->create());

    Livewire::test(FeedbackForm::class)
        ->set('type', 'idea')
        ->set('message', $message)
        ->call('send')
        ->assertHasErrors('message');

    Mail::assertNothingQueued();
})->with(['empty' => [''], 'too long' => [str_repeat('a', 5001)]]);

test('the type must be one of the offered options', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test(FeedbackForm::class)
        ->set('type', 'spam')
        ->set('message', 'hello')
        ->call('send')
        ->assertHasErrors('type');

    Mail::assertNothingQueued();
});

test('a page URL pointing at another site is not passed along', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test(FeedbackForm::class)
        ->set('type', 'bug')
        ->set('message', 'hello')
        ->set('pageUrl', 'https://evil.example/phish')
        ->call('send');

    Mail::assertQueued(FeedbackSubmitted::class, fn (FeedbackSubmitted $mail) => $mail->pagePath === null);
});

test('a guest cannot send feedback', function () {
    Livewire::test(FeedbackForm::class)
        ->set('type', 'bug')
        ->set('message', 'hello')
        ->call('send')
        ->assertForbidden();

    Mail::assertNothingQueued();
});

test('more than five messages an hour are refused', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    RateLimiter::clear('feedback:'.$user->id);

    $component = Livewire::test(FeedbackForm::class);
    foreach (range(1, 6) as $i) {
        $component->set('type', 'bug')->set('message', "report {$i}")->call('send');
    }

    $component->assertHasErrors('message');
    Mail::assertQueued(FeedbackSubmitted::class, 5);
});

test('the email shows the message as text, never as HTML', function () {
    $mail = new FeedbackSubmitted(
        type: 'bug', body: '<script>alert(1)</script>', username: 'ash', userEmail: 'ash@example.test', pagePath: '/admin',
    );

    $mail->assertSeeInHtml('&lt;script&gt;alert(1)&lt;/script&gt;', false)
        ->assertDontSeeInHtml('<script>alert(1)</script>', false)
        ->assertSeeInText('ash')
        ->assertSeeInText('/admin');
});

test('with no feedback address configured it says so instead of pretending to send', function (?string $address) {
    config(['tcgvault.feedback_email' => $address]);
    $this->actingAs(User::factory()->create());

    Livewire::test(FeedbackForm::class)
        ->set('type', 'bug')
        ->set('message', 'hello')
        ->call('send')
        ->assertHasErrors('message')
        ->assertNotDispatched('feedback-sent');

    Mail::assertNothingQueued();
})->with(['null' => [null], 'empty' => ['']]);

test('a transient mail failure is retried rather than dropped', function () {
    $mail = new FeedbackSubmitted(type: 'bug', body: 'x', username: 'ash', userEmail: 'ash@example.test', pagePath: null);

    expect($mail->tries)->toBe(3)->and($mail->backoff)->toBe(60);
});

test('only a same-site path survives, whatever shape the URL takes', function (string $url, ?string $expected) {
    $this->actingAs(User::factory()->create());

    Livewire::test(FeedbackForm::class)
        ->set('type', 'bug')->set('message', 'hello')->set('pageUrl', $url)
        ->call('send');

    Mail::assertQueued(FeedbackSubmitted::class, fn (FeedbackSubmitted $mail) => $mail->pagePath === $expected);
})->with([
    'scheme-relative other host' => ['//evil.example/x', null],
    'userinfo pointing elsewhere' => ['http://localhost:8000@evil.example/x', null],
    'userinfo and fragment dropped' => ['http://evil@localhost/x?a=1#frag', '/x?a=1'],
    'javascript scheme' => ['javascript:alert(1)', null],
]);

test('reopening the modal after sending resets it client-side, without a server round trip', function () {
    $html = file_get_contents(resource_path('views/livewire/feedback-form.blade.php'));

    // A server action on open re-renders the modal and closes it again, so
    // the reset has to happen in the open-modal handler itself.
    expect($html)->toContain("x-on:open-modal.window=\"if (\$event.detail === 'feedback') { \$wire.pageUrl = window.location.href; sent = false }\"");
});

test('the modal focus trap skips elements that are not rendered', function () {
    $html = file_get_contents(resource_path('views/components/modal.blade.php'));

    expect($html)->toContain('el.getClientRects().length');
});
