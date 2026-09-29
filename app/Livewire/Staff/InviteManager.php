<?php

declare(strict_types=1);

namespace App\Livewire\Staff;

use App\Mail\InviteSent;
use App\Models\User;
use App\Modules\Invites\Models\Invite;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\View\View;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;
use RuntimeException;
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

    /** One lock for every invite-email counter, so both limits move together. */
    private const LIMITS_LOCK = 'invite-mail-limits';

    private const LIMITS_LOCK_WAIT_SECONDS = 3;

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
        // Validation below runs on a normalised copy, not through
        // $this->validate(), so it doesn't clear an earlier refusal itself.
        $this->resetErrorBag(['email', 'resend']);

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

        // Mail systems ignore an address's case, so one spelling is kept:
        // otherwise `A@x.com` would pass every check `a@x.com` fails. The
        // field keeps what staff typed, so a refusal shows their input.
        $email = Str::lower(trim($this->email));

        Validator::make(['email' => $email], [
            'email' => [
                'required',
                // `filter` refuses spaces, comments and other RFC forms
                // that name the same mailbox under a different string; a
                // quoted local part is refused for the same reason.
                'email:rfc,filter',
                'not_regex:/"/',
                // Fail fast rather than issue a signed link that will
                // always dead-end at "email already taken" on submit.
                function (string $attribute, mixed $value, callable $fail) {
                    if (User::whereRaw('LOWER(email) = ?', [$value])->exists()) {
                        $fail('An account with this email already exists.');
                    } elseif (Invite::forEmail($value)->usable()->exists()) {
                        $fail('This email already has a pending invite.');
                    }
                },
            ],
        ])->validate();

        $reservation = $this->reserveEmail($email);

        if (is_string($reservation)) {
            $this->addError('email', $reservation);

            return;
        }

        $attributes = [
            'email' => $email,
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
                $this->releaseEmail($reservation);

                throw $exception;
            }

            // The index has no way to exclude merely-expired rows
            // (Postgres requires an IMMUTABLE predicate; `expires_at >
            // now()` isn't) — so the row it's actually complaining
            // about may be expired-and-forgotten, not a real pending
            // invite. Self-heal that case instead of permanently
            // locking the email out until someone remembers to revoke
            // the stale row by hand.
            $stale = Invite::forEmail($email)
                ->whereNull('used_at')
                ->whereNull('revoked_at')
                ->first();

            if ($stale === null || $stale->isUsable()) {
                $this->releaseEmail($reservation);
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
                    $this->releaseEmail($reservation);

                    throw $retryException;
                }

                $this->releaseEmail($reservation);
                $this->addError('email', 'This email already has a pending invite.');

                return;
            }
        }

        $this->reset('email');
        $this->sendInviteEmail($invite, $reservation);
    }

    /** For an invite email that never arrived or got lost. */
    public function resendInvite(int $inviteId): void
    {
        Gate::authorize('manage-invites');

        $this->sentTo = null;
        $this->resetErrorBag(['email', 'resend']);

        // Same per-staff pace as creating: resends also go through the
        // shared limit lock, so an unthrottled loop would crowd out
        // everyone else's invite emails.
        $limiterKey = 'resend-invite:'.auth()->id();

        if (RateLimiter::tooManyAttempts($limiterKey, 20)) {
            $this->addError('resend', 'Too many resends — try again in a few minutes.');

            return;
        }

        RateLimiter::hit($limiterKey, 60);

        $invite = Invite::findOrFail($inviteId);

        if (! $invite->isUsable()) {
            return;
        }

        $reservation = $this->reserveEmail($invite->email);

        if (is_string($reservation)) {
            $this->addError('resend', $reservation);

            return;
        }

        $this->sendInviteEmail($invite, $reservation);
    }

    /**
     * Counts the email against both limits before it is sent, under one
     * lock: a read-then-write counter is only safe when no other request
     * can read it in between. Returns why it was refused, or the
     * reservation to give back if the email never goes out.
     *
     * @return string|array<string, int> refusal message, or key => window expiry
     */
    private function reserveEmail(string $email): string|array
    {
        $limits = [
            $this->addressLimiterKey($email) => [
                self::EMAILS_PER_ADDRESS_PER_HOUR, 3600,
                'This address was already sent '.self::EMAILS_PER_ADDRESS_PER_HOUR.' invite emails in the last hour.',
            ],
            $this->staffLimiterKey() => [
                self::EMAILS_PER_STAFF_PER_DAY, 86400,
                'You’ve sent '.self::EMAILS_PER_STAFF_PER_DAY.' invite emails today. Try again tomorrow.',
            ],
        ];

        try {
            return Cache::lock(self::LIMITS_LOCK, 10)->block(self::LIMITS_LOCK_WAIT_SECONDS, function () use ($limits): string|array {
                $windows = [];

                foreach ($limits as $key => [$max, $decay, $refusal]) {
                    $window = $this->currentWindow($key)
                        ?? $this->legacyWindow($key, $decay)
                        ?? ['hits' => 0, 'expires' => now()->getTimestamp() + $decay];

                    if ($window['hits'] >= $max) {
                        return $refusal;
                    }

                    $windows[$key] = $window;
                }

                foreach ($windows as $key => $window) {
                    $this->storeWindow($key, $window['hits'] + 1, $window['expires']);
                }

                return array_map(fn (array $window): int => $window['expires'], $windows);
            });
        } catch (LockTimeoutException) {
            return 'Another invite email is being sent right now. Try again in a moment.';
        }
    }

    /**
     * Gives back a reservation whose email never went out — but only to
     * the window it was taken from. Once that window has ended, the
     * counter under the same key belongs to later emails, and taking a
     * slot off it would let one more through than the limit allows.
     *
     * @param  array<string, int>  $reservation
     */
    private function releaseEmail(array $reservation): void
    {
        try {
            Cache::lock(self::LIMITS_LOCK, 10)->block(self::LIMITS_LOCK_WAIT_SECONDS, function () use ($reservation): void {
                foreach ($reservation as $key => $expires) {
                    $window = $this->currentWindow($key);

                    if ($window !== null && $window['expires'] === $expires && $window['hits'] > 0) {
                        $this->storeWindow($key, $window['hits'] - 1, $expires);
                    }
                }
            });
        } catch (LockTimeoutException) {
            // Keeping the slot fails closed: the limit only gets stricter.
            // Reported so a lock that keeps timing out doesn't go unseen.
            report(new RuntimeException('Invite-email limit lock timed out while giving back a slot.'));
        }
    }

    /** @return array{hits: int, expires: int}|null */
    private function currentWindow(string $key): ?array
    {
        $window = Cache::get($key);

        if (! is_array($window) || ! is_int($window['hits'] ?? null) || ! is_int($window['expires'] ?? null)) {
            return null;
        }

        return $window['expires'] > now()->getTimestamp()
            ? ['hits' => $window['hits'], 'expires' => $window['expires']]
            : null;
    }

    /**
     * A bare count under the same key is RateLimiter's format, which these
     * limits used before. Its own expiry can't be read back, so it is
     * treated as a window starting now: the limit can only get stricter.
     *
     * @return array{hits: int, expires: int}|null
     */
    private function legacyWindow(string $key, int $decay): ?array
    {
        $hits = Cache::get($key);

        if (! is_numeric($hits) || (int) $hits <= 0) {
            return null;
        }

        // RateLimiter kept the window's end under `:timer`; without it the
        // window is treated as starting now, which only makes it stricter.
        $timer = Cache::get($key.':timer');
        $expires = is_numeric($timer) && (int) $timer > now()->getTimestamp()
            ? (int) $timer
            : now()->getTimestamp() + $decay;

        return ['hits' => (int) $hits, 'expires' => $expires];
    }

    private function storeWindow(string $key, int $hits, int $expires): void
    {
        Cache::put($key, ['hits' => $hits, 'expires' => $expires], Carbon::createFromTimestamp($expires));
    }

    /**
     * The invite already exists when this runs, so a queue that can't be
     * reached leaves a pending invite whose email can be resent, and its
     * reservation is given back.
     *
     * @param  array<string, int>  $reservation
     */
    private function sendInviteEmail(Invite $invite, array $reservation): void
    {
        try {
            Mail::to($invite->email)->queue(new InviteSent($invite));
        } catch (Throwable $exception) {
            report($exception);
            $this->releaseEmail($reservation);
            $this->addError('resend', 'The invite for '.$invite->email.' was saved, but its email couldn’t be sent. Use Resend to try again.');

            return;
        }

        $this->sentTo = $invite->email;
    }

    private function addressLimiterKey(string $email): string
    {
        return 'invite-mail-address:'.sha1($this->mailbox($email));
    }

    /**
     * The inbox an address delivers to, for the per-address limit only
     * (the invite keeps the address as typed): a `+tag` is dropped, and
     * for Gmail the dots too, since `a.b+x@gmail.com` and `ab@gmail.com`
     * are the same inbox and would otherwise each get their own budget.
     */
    private function mailbox(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', Str::lower(trim($email)), 2), 2, '');
        $local = Str::before($local, '+');

        if (in_array($domain, ['gmail.com', 'googlemail.com'], true)) {
            return str_replace('.', '', $local).'@gmail.com';
        }

        return $local.'@'.$domain;
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
