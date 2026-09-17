<div class="nw-wrap py-10">
    <h1 class="nw-display nw-h1 nw-h1--sm mb-4">Platform settings</h1>

    {{-- One section per topic (Registration today; Email/Storage/etc. later)
         so a new setting is another <x-settings-section> below this one,
         never a redesign of the page. --}}
    <div class="max-w-2xl space-y-4">
        <form wire:submit="save">
            <x-settings-section
                title="Registration"
                description="Off keeps the beta invite-only — /register shows a notice instead of the sign-up form."
            >
                <label class="flex items-center gap-2">
                    {{-- @tailwindcss/forms renders the checked state via
                         `background-color: currentColor` PLUS a hardcoded
                         white checkmark glyph drawn on top — `color` has to
                         stay dark in both themes or the checkmark vanishes
                         into a light fill, so this uses the frozen
                         --chrome-bg, not the theme-flipping --ink. --}}
                    <input type="checkbox" wire:model="registrationOpen" class="rounded shadow-sm" style="color: var(--chrome-bg); border-color: var(--hair)">
                    <span style="color: var(--ink)">{{ __('Open public registration') }}</span>
                </label>

                <div class="mt-4 flex items-center gap-3">
                    <x-primary-button>{{ __('Save') }}</x-primary-button>
                    <x-action-message on="settings-saved">{{ __('Saved.') }}</x-action-message>
                </div>
            </x-settings-section>
        </form>
    </div>
</div>
