<?php

declare(strict_types=1);

namespace App\Livewire\Staff;

use App\Modules\Invites\Models\Invite;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Component;

#[Layout('layouts.app')]
final class InviteManager extends Component
{
    #[Validate('required|email')]
    public string $email = '';

    public function mount(): void
    {
        Gate::authorize('manage-invites');
    }

    public function createInvite(): void
    {
        Gate::authorize('manage-invites');

        $this->validate();

        Invite::create([
            'email' => $this->email,
            'created_by' => auth()->id(),
            'expires_at' => now()->addDays(config('tcgvault.invite_ttl_days', 7)),
        ]);

        $this->reset('email');
    }

    public function render()
    {
        return view('livewire.staff.invite-manager', [
            'invites' => Invite::latest()->get(),
        ]);
    }
}
