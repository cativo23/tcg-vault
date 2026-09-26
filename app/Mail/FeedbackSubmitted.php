<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * A beta member's feedback, sent to the owner. Reply-To is the member,
 * so answering the email answers them directly. The message is always
 * rendered escaped — it is untrusted user text.
 */
final class FeedbackSubmitted extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public const TYPES = [
        'bug' => 'Bug',
        'idea' => 'Idea',
        'confusing' => 'Something’s confusing',
    ];

    /** A transient mail-provider failure retries instead of dropping the report. */
    public int $tries = 3;

    public int $backoff = 60;

    public function __construct(
        public readonly string $type,
        public readonly string $body,
        public readonly ?string $username,
        public readonly string $userEmail,
        public readonly ?string $pagePath,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            replyTo: [new Address($this->userEmail)],
            subject: '[tcg-vault] '.self::TYPES[$this->type].' from '.($this->username ?? $this->userEmail),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.feedback-submitted',
            text: 'mail.feedback-submitted-text',
            with: ['typeLabel' => self::TYPES[$this->type]],
        );
    }
}
