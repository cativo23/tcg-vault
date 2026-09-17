<?php

declare(strict_types=1);

namespace App\Livewire\Staff;

use App\Settings\RegistrationSettings;
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

        $this->registrationOpen = app(RegistrationSettings::class)->open;
    }

    public function save(): void
    {
        Gate::authorize('manage-platform-settings');

        $settings = app(RegistrationSettings::class);
        $settings->open = $this->registrationOpen;
        $settings->save();
    }

    public function render()
    {
        return view('livewire.staff.platform-settings');
    }
}
