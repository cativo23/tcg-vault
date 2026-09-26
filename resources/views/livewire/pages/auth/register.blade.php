<?php

use App\Livewire\Concerns\ScreensAge;
use App\Models\User;
use App\Settings\RegistrationSettings;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.guest')] class extends Component
{
    use ScreensAge;

    public string $name = '';
    public string $username = '';
    public string $email = '';
    public string $password = '';
    public string $password_confirmation = '';

    public bool $registrationOpen;

    /**
     * The single place this is read in this file — mount() and
     * register() both call it rather than each resolving
     * RegistrationSettings independently, so the two can never quietly
     * diverge.
     *
     * Runtime setting decides the CONTENT this route shows, not
     * whether the route exists — see routes/auth.php.
     */
    private function currentRegistrationOpen(): bool
    {
        return app(RegistrationSettings::class)->open;
    }

    public function mount(): void
    {
        $this->registrationOpen = $this->currentRegistrationOpen();
    }

    /**
     * Handle an incoming registration request.
     */
    public function register(): void
    {
        // $this->registrationOpen is a public Livewire property — the
        // client can set it to any value via the ordinary component
        // update mechanism regardless of whether a wire:model in the
        // blade binds to it, so it is display-only. The actual gate
        // re-reads the real setting server-side, every time.
        abort_unless($this->currentRegistrationOpen(), 403);

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'username' => User::usernameRules(),
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:'.User::class],
            'password' => ['required', 'string', 'confirmed', Rules\Password::defaults()],
        ]);

        $this->screenAge();

        $validated['password'] = Hash::make($validated['password']);

        event(new Registered($user = User::create($validated)));

        $user->assignRole('user');

        Auth::login($user);

        $this->redirect(route('dashboard', absolute: false), navigate: true);
    }
}; ?>

<div>
    @if ($registrationOpen)
        <form wire:submit="register">
            <!-- Name -->
            <div>
                <x-input-label for="name" :value="__('Name')" />
                <x-text-input wire:model="name" id="name" class="block mt-1 w-full" type="text" name="name" required autofocus autocomplete="name" />
                <x-input-error :messages="$errors->get('name')" class="mt-2" />
            </div>

            <!-- Username -->
            <div class="mt-4">
                <x-input-label for="username" :value="__('Username')" />
                <x-text-input wire:model="username" id="username" class="block mt-1 w-full" type="text" name="username" required autocomplete="username" />
                <x-input-error :messages="$errors->get('username')" class="mt-2" />
            </div>

            <!-- Email Address -->
            <div class="mt-4">
                <x-input-label for="email" :value="__('Email')" />
                <x-text-input wire:model="email" id="email" class="block mt-1 w-full" type="email" name="email" required autocomplete="username" />
                <x-input-error :messages="$errors->get('email')" class="mt-2" />
            </div>

            <!-- Password -->
            <div class="mt-4">
                <x-input-label for="password" :value="__('Password')" />

                <x-text-input wire:model="password" id="password" class="block mt-1 w-full"
                                type="password"
                                name="password"
                                required autocomplete="new-password" />

                <x-input-error :messages="$errors->get('password')" class="mt-2" />
            </div>

            <!-- Confirm Password -->
            <div class="mt-4">
                <x-input-label for="password_confirmation" :value="__('Confirm Password')" />

                <x-text-input wire:model="password_confirmation" id="password_confirmation" class="block mt-1 w-full"
                                type="password"
                                name="password_confirmation" required autocomplete="new-password" />

                <x-input-error :messages="$errors->get('password_confirmation')" class="mt-2" />
            </div>

            @include('partials.signup-age-fields')

            @include('partials.signup-legal-notice')

            <div class="flex items-center justify-end mt-4">
                <a class="nw-link underline text-sm rounded-md" href="{{ route('login') }}" wire:navigate>
                    {{ __('Already registered?') }}
                </a>

                <x-primary-button class="ms-4">
                    {{ __('Register') }}
                </x-primary-button>
            </div>
        </form>
    @else
        {{-- Invite-only beta: no request-access form or CTA here on purpose
             — invites are handed out privately by the admin, not requested
             through this page. --}}
        <div class="text-center">
            <p style="color: var(--ink)">{{ __('tcg-vault is by invitation only right now.') }}</p>
            <p class="mt-2 text-sm" style="color: var(--muted)">{{ __("If you already have an invite link, use it directly — this page isn't the way in.") }}</p>

            <a class="nw-link underline text-sm mt-6 inline-block" href="{{ route('login') }}" wire:navigate>
                {{ __('Already have an account?') }}
            </a>
        </div>
    @endif
</div>
