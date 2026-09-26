<div>
    {{--
        The invite is the only front door into a private, single-admin
        vault — an invitee has no other page to have seen first, so a
        one-line "why you're here" has to live right on this form instead
        of assuming a landing page already set context. The wordmark
        itself comes from layouts/guest.blade.php, shared by every guest
        auth page.
    --}}
    <div class="flex flex-col items-center mb-8 text-center">
        <p class="nw-eyebrow">{{ __("You're invited") }}</p>
        <p style="margin-top: 0.5rem; color: var(--muted)">{{ __('Create your account to start tracking your collection.') }}</p>
    </div>

    <form wire:submit="register">
        <div>
            <x-input-label for="email" :value="__('Email')" />
            <x-text-input id="email" class="block mt-1 w-full" type="email" value="{{ $invite->email }}" disabled />
        </div>

        <div class="mt-4">
            <x-input-label for="name" :value="__('Name')" />
            <x-text-input wire:model="name" id="name" class="block mt-1 w-full" type="text" name="name" required autofocus autocomplete="name" />
            <x-input-error :messages="$errors->get('name')" class="mt-2" />
        </div>

        <div class="mt-4">
            <x-input-label for="username" :value="__('Username')" />
            <x-text-input wire:model="username" id="username" class="block mt-1 w-full" type="text" name="username" required autocomplete="username" />
            <x-input-error :messages="$errors->get('username')" class="mt-2" />
        </div>

        <div class="mt-4">
            <x-input-label for="password" :value="__('Password')" />
            <x-text-input wire:model="password" id="password" class="block mt-1 w-full" type="password" name="password" required autocomplete="new-password" />
            <x-input-error :messages="$errors->get('password')" class="mt-2" />
        </div>

        <div class="mt-4">
            <x-input-label for="password_confirmation" :value="__('Confirm Password')" />
            <x-text-input wire:model="password_confirmation" id="password_confirmation" class="block mt-1 w-full" type="password" name="password_confirmation" required autocomplete="new-password" />
            <x-input-error :messages="$errors->get('password_confirmation')" class="mt-2" />
        </div>

        @include('partials.signup-legal-notice')

        <div class="flex items-center justify-end mt-4">
            <x-primary-button>{{ __('Create account') }}</x-primary-button>
        </div>
    </form>
</div>
