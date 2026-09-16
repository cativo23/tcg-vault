<div class="max-w-2xl">
    <form wire:submit="save">
        <label class="flex items-center gap-2">
            <input type="checkbox" wire:model="registrationOpen">
            <span style="color: var(--ink)">{{ __('Open public registration') }}</span>
        </label>
        <p class="mt-1 text-sm" style="color: var(--muted)">
            {{ __('Off keeps the beta invite-only — /register shows a notice instead of the sign-up form.') }}
        </p>

        <x-primary-button class="mt-4">{{ __('Save') }}</x-primary-button>
    </form>
</div>
