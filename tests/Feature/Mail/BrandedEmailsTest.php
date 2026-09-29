<?php

declare(strict_types=1);

use App\Mail\FeedbackSubmitted;
use App\Mail\InviteSent;
use App\Models\User;
use App\Modules\Invites\Models\Invite;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Mail\Mailable;
use Illuminate\Notifications\Messages\MailMessage;

function feedbackMail(string $body = 'The price chart is blank.'): FeedbackSubmitted
{
    return new FeedbackSubmitted('bug', $body, 'ash', 'ash@example.com', '/admin');
}

function resetMail(User $user, string $token = 'reset-token-123'): MailMessage
{
    return (new ResetPassword($token))->toMail($user);
}

/** @return array<string, Closure(): Mailable> */
function brandedMailables(): array
{
    return [
        'invite' => fn () => new InviteSent(Invite::factory()->create()),
        'feedback' => fn () => feedbackMail(),
    ];
}

test('every mailable uses the branded layout', function (Closure $make) {
    $mail = $make();

    $mail->assertSeeInHtml('TCG-VAULT');
    $mail->assertSeeInHtml(route('privacy'), false);
    $mail->assertSeeInText('tcg-vault');
    $mail->assertDontSeeInText('<table');
})->with(brandedMailables());

test('the password reset email uses the branded layout', function () {
    $html = (string) resetMail(User::factory()->create())->render();

    expect($html)->toContain('TCG-VAULT')->toContain(route('privacy'));
});

test('the password reset email links to the reset form with its token and says when it expires', function () {
    $user = User::factory()->create(['email' => 'ash@example.com']);
    $message = resetMail($user);

    expect($message->subject)->toBe('Reset your tcg-vault password');

    $url = route('password.reset', ['token' => 'reset-token-123', 'email' => 'ash@example.com']);
    $html = (string) $message->render();

    expect($html)->toContain(e($url))->toContain('60 minutes');
    $this->get($url)->assertOk();
});

test('a member’s feedback text stays escaped, never turned into markup or links', function () {
    $mail = feedbackMail('<b>bold</b> [click](https://evil.example)');

    $mail->assertSeeInHtml('&lt;b&gt;bold&lt;/b&gt;', false);
    $mail->assertDontSeeInHtml('<b>bold</b>', false);
    $mail->assertDontSeeInHtml('href="https://evil.example"', false);
    $mail->assertSeeInText('<b>bold</b> [click](https://evil.example)', false);
});

test('the branded emails load no remote images or fonts', function (Closure $make) {
    $html = $make()->render();

    expect($html)->not->toContain('<img')->not->toContain('fonts.googleapis')->not->toContain('@import');
})->with(brandedMailables());
