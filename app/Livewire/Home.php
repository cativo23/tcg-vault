<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Models\User;
use App\Modules\Collection\Services\PublicCollection;
use App\Settings\RegistrationSettings;
use Illuminate\Contracts\View\View;
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

    /**
     * The example collection's username, or null while it hasn't been
     * seeded, isn't public, or has no cards — the link only shows when it
     * would land on a real collection.
     */
    public ?string $exampleUsername = null;

    public function mount(): void
    {
        /** @var User|null $user */
        $user = Auth::user();

        if ($user !== null) {
            $this->redirectRoute('gallery.index', ['username' => $user->username], navigate: false);

            return;
        }

        $this->registrationOpen = app(RegistrationSettings::class)->open;

        // Matched on the demo email too: the username alone is claimable by
        // any member, and this link must never advertise someone's own
        // collection as the example.
        $demo = User::where('username', config('tcgvault.demo.username'))
            ->where('email', config('tcgvault.demo.email'))
            ->first();
        if ($demo !== null && PublicCollection::for($demo)->itemsQuery()->exists()) {
            $this->exampleUsername = $demo->username;
        }
    }

    public function render(): View
    {
        return view('livewire.home');
    }
}
