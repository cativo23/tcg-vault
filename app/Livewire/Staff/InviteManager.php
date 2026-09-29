<?php

declare(strict_types=1);

namespace App\Livewire\Staff;

use App\Mail\InviteSent;
use App\Models\User;
use App\Modules\Invites\Models\Invite;
use Illuminate\Contracts\View\View;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;
use Throwable;

#[Layout('layouts.app')]
final class InviteManager extends Component
{
    use WithPagination;

    private const PER_PAGE = 24;

    /**
     * Every invite email, created or resent, counts against both limits:
     * per address, so revoking and re-inviting can't flood one inbox, and
     * per staff account, so a stolen account can't mail strangers at scale.
     */
    private const EMAILS_PER_ADDRESS_PER_HOUR = 3;

    private const EMAILS_PER_STAFF_PER_DAY = 50;

    public string $email = '';

    /** The address the last invite email was queued for, shown as a notice. */
    public ?string $sentTo = null;

    public function mount(): void
    {
        Gate::authorize('manage-invites');
    }

    public function createInvite(): void
    {
        Gate::authorize('manage-invites');

        $this->sentTo = null;

        // A Livewire action isn't reachable by the route's own
        // `throttle` middleware at all (it runs through
        // /livewire/update, not this component's GET route), so the
        // limit has to live here — a compromised or careless admin
        // account should not be able to mass-issue invites unbounded.
        $limiterKey = 'create-invite:'.auth()->id();

        if (RateLimiter::tooManyAttempts($limiterKey, 20)) {
            $this->addError('email', 'Too many invites created — try again in a few minutes.');

            return;
        }

        RateLimiter::hit($limiterKey, 60);

        $this->validate([
            'email' => [
                'required',
                'email',
                // Fail fast rather than issue a signed link that will
                // always dead-end at "email already taken" on submit.
                function (string $attribute, mixed $value, callable $fail) {
                    if (User::where('email', $value)->exists()) {
                        $fail('An account with this email already exists.');
                    } elseif (Invite::where('email', $value)->usable()->exists()) {
                        $fail('This email already has a pending invite.');
                    }
                },
            ],
        ]);

        if ($limit = $this->emailLimitReached($this->email)) {
            $this->addError('email', $limit);

            return;
        }

        $attributes = [
            'email' => $this->email,
            'created_by' => auth()->id(),
            'expires_at' => now()->addDays(config('tcgvault.invite_ttl_days', 7)),
        ];

        try {
            // Wrapped explicitly so a violation only rolls back THIS
            // statement (via a savepoint when nested inside a wider
            // transaction, e.g. under RefreshDatabase in tests) —
            // without this, a failed INSERT can otherwise poison every
            // later query in an enclosing transaction.
            $invite = DB::transaction(fn () => Invite::create($attributes));
        } catch (QueryException $exception) {
            // The validation check above already covers the common
            // sequential case; this catches the genuine race it can't
            // (two concurrent creates for the same email both passing
            // that check before either commits) — the database's own
            // partial unique index (see the invites migration) is the
            // real guarantee.
            if (! str_contains($exception->getMessage(), 'invites_usable_email_unique')) {
                throw $exception;
            }

            // The index has no way to exclude merely-expired rows
            // (Postgres requires an IMMUTABLE predicate; `expires_at >
            // now()` isn't) — so the row it's actually complaining
            // about may be expired-and-forgotten, not a real pending
            // invite. Self-heal that case instead of permanently
            // locking the email out until someone remembers to revoke
            // the stale row by hand.
            $stale = Invite::where('email', $this->email)
                ->whereNull('used_at')
                ->whereNull('revoked_at')
                ->first();

            if ($stale === null || $stale->isUsable()) {
                $this->addError('email', 'This email already has a pending invite.');

                return;
            }

            $stale->revoke(auth()->user()?->id);

            try {
                $invite = DB::transaction(fn () => Invite::create($attributes));
            } catch (QueryException $retryException) {
                // The row that conflicted a moment ago was the stale one
                // just revoked above — but between that revoke and this
                // retry, a different request can still have landed a
                // genuinely usable invite for the same email (the same
                // kind of race the first catch above exists for, just
                // one step later). That's real contention, not a bug:
                // report it the same way the sequential check would
                // have, rather than letting a second unhandled
                // QueryException surface as a raw database error.
                if (! str_contains($retryException->getMessage(), 'invites_usable_email_unique')) {
                    throw $retryException;
                }

                $this->addError('email', 'This email already has a pending invite.');

                return;
            }
        }

        $this->reset('email');
        $this->sendInviteEmail($invite);
    }

    /** For an invite email that never arrived or got lost. */
    public function resendInvite(int $inviteId): void
    {
        Gate::authorize('manage-invites');

        $this->sentTo = null;
        $invite = Invite::findOrFail($inviteId);

        if (! $invite->isUsable()) {
            return;
        }

        if ($limit = $this->emailLimitReached($invite->email)) {
            $this->addError('resend', $limit);

            return;
        }

        $this->sendInviteEmail($invite);
    }

    private function emailLimitReached(string $email): ?string
    {
        if (RateLimiter::tooManyAttempts($this->addressLimiterKey($email), self::EMAILS_PER_ADDRESS_PER_HOUR)) {
            return 'This address was already sent '.self::EMAILS_PER_ADDRESS_PER_HOUR.' invite emails in the last hour.';
        }

        if (RateLimiter::tooManyAttempts($this->staffLimiterKey(), self::EMAILS_PER_STAFF_PER_DAY)) {
            return 'You’ve sent '.self::EMAILS_PER_STAFF_PER_DAY.' invite emails today. Try again tomorrow.';
        }

        return null;
    }

    /**
     * The invite already exists when this runs, so a queue that can't be
     * reached leaves a pending invite whose email can be resent.
     */
    private function sendInviteEmail(Invite $invite): void
    {
        try {
            Mail::to($invite->email)->queue(new InviteSent($invite));
        } catch (Throwable $exception) {
            report($exception);
            $this->addError('resend', 'The invite for '.$invite->email.' was saved, but its email couldn’t be sent. Use Resend to try again.');

            return;
        }

        RateLimiter::hit($this->addressLimiterKey($invite->email), 3600);
        RateLimiter::hit($this->staffLimiterKey(), 86400);
        $this->sentTo = $invite->email;
    }

    private function addressLimiterKey(string $email): string
    {
        return 'invite-mail-address:'.sha1(Str::lower($email));
    }

    private function staffLimiterKey(): string
    {
        return 'invite-mail-admin:'.auth()->id();
    }

    public function revokeInvite(int $inviteId): void
    {
        Gate::authorize('manage-invites');

        Invite::findOrFail($inviteId)->revoke(auth()->user()?->id);
    }

    public function render(): View
    {
        return view('livewire.staff.invite-manager', [
            'invites' => Invite::latest()->paginate(self::PER_PAGE),
        ]);
    }
}
