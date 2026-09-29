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

/**
 * The invite link, emailed to the address staff invited. The link is
 * built when the email is sent, from the invite's own signed URL, so it
 * is the same one the invite page shows and never sits in the queue.
 *
 * Only the invite's id is queued. An invite revoked, used, expired or
 * deleted while this waits is not sent: the email would only lead to a
 * dead link.
 */
final class InviteSent extends Mailable implements ShouldQueue
{
    use Queueable;

    /** A transient mail-provider failure retries instead of dropping the invite. */
    public int $tries = 3;

    public int $backoff = 60;

    public readonly int $inviteId;

    private ?Invite $invite = null;

    public function __construct(Invite $invite)
    {
        $this->inviteId = $invite->id;
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'You’re invited to tcg-vault');
    }

    public function content(): Content
    {
        $invite = $this->invite ??= Invite::findOrFail($this->inviteId);

        return new Content(
            view: 'mail.invite',
            text: 'mail.invite-text',
            with: [
                'url' => $invite->signedUrl(),
                'email' => $invite->email,
                'inviter' => $invite->creator?->username,
                'expiresOn' => $invite->expires_at->format('F j, Y'),
            ],
        );
    }

    /**
     * @param  Factory|Mailer  $mailer
     */
    public function send($mailer): ?SentMessage
    {
        $this->invite = Invite::find($this->inviteId);

        if ($this->invite === null || ! $this->invite->isUsable()) {
            return null;
        }

        return parent::send($mailer);
    }

    /** @return list<string> */
    public function __sleep(): array
    {
        return array_values(array_diff(array_keys(get_object_vars($this)), ['invite']));
    }
}
