<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Mail\FeedbackSubmitted;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rule;
use Livewire\Component;

/**
 * The beta feedback modal, rendered once in the logged-in layout and
 * opened from the "Feedback" nav item. Emails the owner; the page the
 * member was on is captured client-side when the modal opens.
 */
final class FeedbackForm extends Component
{
    private const MAX_PER_HOUR = 5;

    public string $type = 'bug';

    public string $message = '';

    public string $pageUrl = '';

    public bool $sent = false;

    public function send(): void
    {
        $user = auth()->user();
        abort_if($user === null, 403);

        $this->validate([
            'type' => ['required', Rule::in(array_keys(FeedbackSubmitted::TYPES))],
            'message' => ['required', 'string', 'max:5000'],
        ]);

        $key = 'feedback:'.$user->id;
        if (RateLimiter::tooManyAttempts($key, self::MAX_PER_HOUR)) {
            $this->addError('message', 'That’s a lot of feedback in one hour — thank you! Please try again a bit later.');

            return;
        }
        RateLimiter::hit($key, 3600);

        Mail::to(config('tcgvault.feedback_email'))->queue(new FeedbackSubmitted(
            type: $this->type,
            body: $this->message,
            username: $user->username,
            userEmail: $user->email,
            pagePath: $this->samesitePath($this->pageUrl),
        ));

        $this->reset('message', 'type');
        $this->sent = true;
    }

    public function startOver(): void
    {
        $this->sent = false;
    }

    /**
     * The page URL comes from the browser, so keep it only when it points
     * at this app, and reduce it to path + query so nothing else rides
     * along into the owner's inbox.
     */
    private function samesitePath(string $url): ?string
    {
        $parts = parse_url($url);
        $appHost = parse_url(config('app.url'), PHP_URL_HOST);

        if ($parts === false || ($parts['host'] ?? null) !== $appHost) {
            return null;
        }

        $path = ($parts['path'] ?? '/').(isset($parts['query']) ? '?'.$parts['query'] : '');

        return mb_substr($path, 0, 500);
    }

    public function render()
    {
        return view('livewire.feedback-form');
    }
}
