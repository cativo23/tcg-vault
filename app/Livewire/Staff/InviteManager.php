<?php

declare(strict_types=1);

namespace App\Livewire\Staff;

use App\Models\User;
use App\Modules\Invites\Models\Invite;
use Illuminate\Support\Facades\Gate;
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

        $this->validate([
            'email' => [
                'required',
                'email',
                // Fail fast rather than issue a signed link that will
                // always dead-end at "email already taken" on submit.
                function (string $attribute, mixed $value, callable $fail) {
                    if (User::where('email', $value)->exists()) {
                        $fail('An account with this email already exists.');
                    } elseif (Invite::where('email', $value)->get()->contains(fn (Invite $invite) => $invite->isUsable())) {
                        $fail('This email already has a pending invite.');
                    }
                },
            ],
        ]);

        Invite::create([
            'email' => $this->email,
            'created_by' => auth()->id(),
            'expires_at' => now()->addDays(config('tcgvault.invite_ttl_days', 7)),
        ]);

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
