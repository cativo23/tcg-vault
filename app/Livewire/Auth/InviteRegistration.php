<?php

declare(strict_types=1);

namespace App\Livewire\Auth;

use App\Models\User;
use App\Modules\Invites\Models\Invite;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Symfony\Component\HttpKernel\Exception\HttpException;

#[Layout('layouts.guest')]
final class InviteRegistration extends Component
{
    /**
     * #[Locked], not plain protected: a plain protected/private property
     * doesn't survive Livewire's hydrate/dehydrate cycle at all between
     * requests (only public properties round-trip), but a public
     * property is otherwise settable by the client to any value via
     * Livewire's update protocol regardless of whether a wire:model in
     * the blade binds to it — the exact mechanism a tampered request
     * could use to swap which invite a later register() call redeems,
     * without ever needing a fresh signed URL for that other invite.
     * #[Locked] keeps it public (so it persists normally) while making
     * the server reject any client-sent update targeting it.
     */
    #[Locked]
    public Invite $invite;

    #[Locked]
    public string $hash;

    public string $name = '';

    public string $username = '';

    public string $password = '';

    public string $password_confirmation = '';

    public function mount(Invite $invite, string $hash): void
    {
        $this->authorizeInvite($invite, $hash);

        $this->invite = $invite;
        $this->hash = $hash;
    }

    /**
     * The signed URL carrying this invite stays cryptographically valid
     * until its own internal expiration even after the row is used or
     * revoked — this row's own state, re-checked on every request, is
     * what actually decides whether the invite still works, never the
     * signature alone.
     */
    private function authorizeInvite(Invite $invite, string $hash): void
    {
        if (! hash_equals(sha1($invite->email), $hash) || ! $invite->isUsable()) {
            throw new HttpException(403, 'This invite is no longer valid.');
        }
    }

    public function register(): void
    {
        $this->authorizeInvite($this->invite, $this->hash);

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'username' => User::usernameRules(),
            'password' => ['required', 'string', 'confirmed', Rules\Password::defaults()],
        ]);

        $user = DB::transaction(function () use ($validated) {
            // Re-fetch and lock the invite row inside the transaction —
            // closes the race between two requests accepting the same
            // invite at once (e.g. two tabs on the same link).
            $invite = Invite::whereKey($this->invite->id)->lockForUpdate()->firstOrFail();

            if (! $invite->isUsable()) {
                throw new HttpException(403, 'This invite is no longer valid.');
            }

            $user = User::create([
                'name' => $validated['name'],
                'username' => $validated['username'],
                'email' => $invite->email,
                'password' => Hash::make($validated['password']),
            ]);

            // User::$fillable deliberately excludes email_verified_at —
            // mass assignment must never let an arbitrary write
            // self-verify an email — so passing it to create() above
            // would silently be dropped, not persisted. forceFill() is
            // the explicit, narrow bypass for this one trusted,
            // server-side write: an invite is proof of email ownership
            // (the invite was sent TO this address), so marking it
            // verified here is correct, not a shortcut.
            $user->forceFill(['email_verified_at' => now()])->save();

            $user->assignRole('user');

            $invite->update(['used_at' => now(), 'accepted_by' => $user->id]);

            return $user;
        });

        event(new Registered($user));

        Auth::login($user);

        $this->redirect(route('dashboard', absolute: false), navigate: false);
    }

    public function render()
    {
        return view('livewire.auth.invite-registration');
    }
}
