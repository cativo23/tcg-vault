<?php

declare(strict_types=1);

namespace App\Livewire\Staff;

use App\Models\User;
use App\Modules\Invites\Models\Invite;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
final class InviteManager extends Component
{
    public string $email = '';

    public function mount(): void
    {
        Gate::authorize('manage-invites');
    }

    public function createInvite(): void
    {
        Gate::authorize('manage-invites');

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

        try {
            Invite::create([
                'email' => $this->email,
                'created_by' => auth()->id(),
                'expires_at' => now()->addDays(config('tcgvault.invite_ttl_days', 7)),
            ]);
        } catch (QueryException $exception) {
            // The validation check above already covers the common
            // sequential case; this catches the genuine race it can't
            // (two concurrent creates for the same email both passing
            // that check before either commits) — the database's own
            // partial unique index (see the invites migration) is the
            // real guarantee, this just turns its violation into a
            // normal validation error instead of a 500.
            if (! str_contains($exception->getMessage(), 'invites_usable_email_unique')) {
                throw $exception;
            }

            $this->addError('email', 'This email already has a pending invite.');

            return;
        }

        $this->reset('email');
    }

    public function revokeInvite(int $inviteId): void
    {
        Gate::authorize('manage-invites');

        Invite::findOrFail($inviteId)->revoke(auth()->id());
    }

    public function render()
    {
        return view('livewire.staff.invite-manager', [
            'invites' => Invite::latest()->get(),
        ]);
    }
}
