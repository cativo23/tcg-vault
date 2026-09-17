<div>
    {{--
        The invite is the only front door into a private, single-admin
        vault — an invitee has no other page to have seen first, so the
        brand mark + a one-line "why you're here" has to live right on
        this form instead of assuming a landing page already set context.
    --}}
    <div class="flex flex-col items-center mb-8 text-center">
        {{--
            .nw-brand/.nw-mark are styled for the always-dark .nw-topbar
            chrome (frozen, never themed) — this page sits on the
            themed var(--bone), so color is overridden to var(--ink) and
            the mark's stroke set to currentColor instead of the
            navbar's hardcoded light tone, or the mark disappears
            against a light-mode background.
        --}}
        <a href="{{ route('home') }}" class="nw-brand" style="color: var(--ink)" wire:navigate.hover>
            <svg class="nw-mark" viewBox="0 0 64 32" aria-hidden="true">
                <polyline points="4,22 16,27 28,15 40,19 52,7" fill="none" stroke="currentColor" stroke-width="4.5" stroke-linecap="round" stroke-linejoin="round"/>
                <circle cx="52" cy="7" r="4.5" fill="#37d17f"/>
            </svg>
            tcg-vault
        </a>
        <p class="nw-eyebrow" style="margin-top: 1.5rem;">{{ __("You're invited") }}</p>
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

        <div class="flex items-center justify-end mt-4">
            <x-primary-button>{{ __('Create account') }}</x-primary-button>
        </div>
    </form>
</div>
