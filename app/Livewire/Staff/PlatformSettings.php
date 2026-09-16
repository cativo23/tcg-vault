<?php

declare(strict_types=1);

namespace App\Livewire\Staff;

use App\Modules\Settings\Models\Setting;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
final class PlatformSettings extends Component
{
    public bool $registrationOpen;

    public function mount(): void
    {
        Gate::authorize('manage-platform-settings');

        $this->registrationOpen = (bool) Setting::get('registration.open', config('tcgvault.allow_registration'));
    }

    public function save(): void
    {
        Gate::authorize('manage-platform-settings');

        Setting::set('registration.open', $this->registrationOpen, auth()->id());
    }

    public function render()
    {
        return view('livewire.staff.platform-settings');
    }
}
