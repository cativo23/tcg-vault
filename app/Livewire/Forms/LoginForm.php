<?php

namespace App\Livewire\Forms;

use App\Models\User;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Validate;
use Livewire\Form;

class LoginForm extends Form
{
    #[Validate('required|string')]
    public string $email = '';

    #[Validate('required|string')]
    public string $password = '';

    #[Validate('boolean')]
    public bool $remember = false;

    /**
     * Attempt to authenticate the request's credentials.
     *
     * @throws ValidationException
     */
    public function authenticate(): void
    {
        $this->ensureIsNotRateLimited();

        $column = str_contains($this->email, '@') ? 'email' : 'username';

        // attemptWhen runs the callback only after the password checks out,
        // so a wrong password never reveals that an account is suspended.
        $suspended = false;
        $signedIn = Auth::attemptWhen(
            [$column => $this->email, 'password' => $this->password],
            function (User $user) use (&$suspended): bool {
                $suspended = $user->isSuspended();

                return ! $suspended;
            },
            $this->remember,
        );

        if (! $signedIn) {
            RateLimiter::hit($this->throttleKey());

            throw ValidationException::withMessages([
                'form.email' => trans($suspended ? 'auth.suspended' : 'auth.failed'),
            ]);
        }

        RateLimiter::clear($this->throttleKey());
    }

    /**
     * Ensure the authentication request is not rate limited.
     */
    protected function ensureIsNotRateLimited(): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), 5)) {
            return;
        }

        event(new Lockout(request()));

        $seconds = RateLimiter::availableIn($this->throttleKey());

        throw ValidationException::withMessages([
            'form.email' => trans('auth.throttle', [
                'seconds' => $seconds,
                'minutes' => ceil($seconds / 60),
            ]),
        ]);
    }

    /**
     * Get the authentication rate limiting throttle key.
     *
     * Login now accepts either email or username for the same account
     * (Phase 3, Task 1) — throttling on the raw submitted string would let
     * an attacker locked out on one identifier immediately switch to the
     * other and get a fresh bucket of attempts against the same account.
     * Resolve to the account's canonical email first (checking both
     * columns) so both identifiers share one throttle bucket; a string
     * that matches no account at all still throttles on the raw input,
     * so bogus attempts remain rate-limited too.
     *
     * The lookup is case-insensitive on purpose: Postgres `=` is
     * case-sensitive by default, and lowering the submitted value only
     * AFTER resolution (rather than during the lookup) would let an
     * attacker locked out on 'testuser' bypass it by submitting
     * 'TESTUSER' — a case variant that fails to resolve, falls back to
     * the raw input, and lands in a fresh bucket once lowered. Comparing
     * `LOWER(column) = LOWER(input)` closes that without needing a
     * citext column or a case-insensitive collation on the schema.
     */
    protected function throttleKey(): string
    {
        $lowered = Str::lower($this->email);

        // orderBy('id') matters even though usernames are now validated
        // (User::usernameRules()'s `lowercase` rule) to be unique
        // case-insensitively going forward: it makes this lookup
        // deterministic against any row created before that validation
        // existed, rather than resolving to an arbitrary one of two
        // case-variant matches.
        $identifier = User::whereRaw('LOWER(email) = ?', [$lowered])
            ->orWhereRaw('LOWER(username) = ?', [$lowered])
            ->orderBy('id')
            ->value('email') ?? $this->email;

        return Str::transliterate(Str::lower($identifier).'|'.request()->ip());
    }
}
