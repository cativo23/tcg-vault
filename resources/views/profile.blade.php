{{-- No <x-slot name="header"> — that renders an extra Breeze-stock
     header bar (layouts/app.blade.php's `@if (isset($header))` block)
     that no other page in the app uses anymore. Every other admin
     screen (Collection, Platform Settings) puts its own .nw-h1 heading
     directly in the body instead; this page was the one Breeze
     leftover still doing it the old way. --}}
<x-app-layout>
    <div class="nw-wrap py-10 space-y-6">
        <h1 class="nw-display nw-h1 nw-h1--sm mb-4">{{ __('Profile') }}</h1>

        <div class="p-4 sm:p-8 nw-card">
            <div class="max-w-xl">
                <livewire:profile.update-profile-information-form />
            </div>
        </div>

        <div class="p-4 sm:p-8 nw-card">
            <div class="max-w-xl">
                <livewire:profile.update-password-form />
            </div>
        </div>

        <div class="p-4 sm:p-8 nw-card">
            <div class="max-w-xl">
                <livewire:profile.delete-user-form />
            </div>
        </div>
    </div>
</x-app-layout>
