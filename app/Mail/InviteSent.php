<?php

declare(strict_types=1);

namespace App\Mail;

use App\Modules\Invites\Models\Invite;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Mail\Factory;
use Illuminate\Contracts\Mail\Mailer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\SentMessage;
use Illuminate\Queue\SerializesModels;

/**
 * The invite link, emailed to the address staff invited. The link is
 * built when the email is sent, from the invite's own signed URL, so it
 * is the same one the invite page shows.
 *
 * An invite revoked, used or expired while this waits in the queue is
 * not sent: the email would only lead to a dead link.
 */
final class InviteSent extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    /** A transient mail-provider failure retries instead of dropping the invite. */
    public int $tries = 3;

    public int $backoff = 60;

    public function __construct(public readonly Invite $invite) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'You’re invited to tcg-vault');
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.invite',
            text: 'mail.invite-text',
            with: [
                'url' => $this->invite->signedUrl(),
                'email' => $this->invite->email,
                'inviter' => $this->invite->creator?->username,
                'expiresOn' => $this->invite->expires_at->format('F j, Y'),
            ],
        );
    }

    /**
     * @param  Factory|Mailer  $mailer
     */
    public function send($mailer): ?SentMessage
    {
        if (! $this->invite->refresh()->isUsable()) {
            return null;
        }

        return parent::send($mailer);
    }
}
