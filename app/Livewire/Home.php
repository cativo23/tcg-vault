<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Models\User;
use App\Settings\RegistrationSettings;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * The public front door at "/". An authenticated visitor already has a
 * gallery to look at — redirect straight there, same shortcut the old
 * plain-closure route gave a logged-in owner. Everyone else (anonymous,
 * whether or not an admin username is configured yet) sees the real
 * marketing home page — deliberately NOT auto-redirected to the owner's
 * gallery the way the old route did, since this page's whole point is to
 * speak to the general public, not funnel every visitor into one
 * specific collection.
 */
#[Layout('layouts.public')]
final class Home extends Component
{
    public bool $registrationOpen;

    public function mount(): void
    {
        /** @var User|null $user */
        $user = Auth::user();

        if ($user !== null) {
            $this->redirectRoute('gallery.index', ['username' => $user->username], navigate: false);
        }

        $this->registrationOpen = app(RegistrationSettings::class)->open;
    }

    public function render()
    {
        return view('livewire.home');
    }
}
