<?php

use App\Livewire\Actions\Logout;
use Livewire\Volt\Component;

new class extends Component
{
    /**
     * Log the current user out of the application.
     */
    public function logout(Logout $logout): void
    {
        $logout();

        $this->redirect('/', navigate: true);
    }
}; ?>

<nav x-data="{ open: false }" class="nw-topbar">
    <!-- Primary Navigation Menu -->
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="flex justify-between h-16">
            <div class="flex">
                <!-- Logo -->
                <div class="shrink-0 flex items-center">
                    <a href="{{ route('dashboard') }}" class="nw-brand" wire:navigate>
                        <svg class="nw-mark" viewBox="0 0 64 32" aria-hidden="true">
                            <polyline points="4,22 16,27 28,15 40,19 52,7" fill="none" stroke="#f2efe6" stroke-width="4.5" stroke-linecap="round" stroke-linejoin="round"/>
                            <circle cx="52" cy="7" r="4.5" fill="#37d17f"/>
                        </svg>
                        tcg-vault
                    </a>
                </div>

                <!-- Navigation Links -->
                <div class="hidden space-x-8 sm:-my-px sm:ms-10 sm:flex">
                    <x-nav-link :href="route('admin.collection.index')" :active="request()->routeIs('admin.collection.*')" wire:navigate>
                        {{ __('Collection') }}
                    </x-nav-link>
                    @if (auth()->user()->username)
                        <x-nav-link :href="route('gallery.index', ['username' => auth()->user()->username])">
                            {{ __('View gallery') }}
                        </x-nav-link>
                    @endif
                    @can('manage-invites')
                        <x-nav-link :href="route('staff.invites')" :active="request()->routeIs('staff.invites')" wire:navigate>
                            {{ __('Invites') }}
                        </x-nav-link>
                    @endcan
                    @can('manage-platform-settings')
                        <x-nav-link :href="route('staff.settings')" :active="request()->routeIs('staff.settings')" wire:navigate>
                            {{ __('Settings') }}
                        </x-nav-link>
                    @endcan
                </div>
            </div>

            <div class="flex items-center gap-2 sm:gap-3">
                {{-- Always visible at every width, same reasoning as the
                     public layout's header: a display preference someone
                     toggles reactively shouldn't need a menu opened first,
                     unlike Profile/Log Out below which ARE destinations. --}}
                <x-theme-toggle />

                <!-- Settings Dropdown -->
                <div class="hidden sm:flex sm:items-center">
                    <x-dropdown align="right" width="48">
                        <x-slot name="trigger">
                            <button class="inline-flex items-center px-3 py-2 border border-transparent text-sm leading-4 font-medium rounded-md focus:outline-none transition ease-in-out duration-150" style="color: var(--chrome-fg)">
                                <div x-data="{{ json_encode(['name' => auth()->user()->name]) }}" x-text="name" x-on:profile-updated.window="name = $event.detail.name"></div>

                                <div class="ms-1">
                                    <svg class="fill-current h-4 w-4" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20">
                                        <path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd" />
                                    </svg>
                                </div>
                            </button>
                        </x-slot>

                        <x-slot name="content">
                            <x-dropdown-link :href="route('profile')" wire:navigate>
                                {{ __('Profile') }}
                            </x-dropdown-link>

                            <!-- Authentication -->
                            <button wire:click="logout" class="w-full text-start">
                                <x-dropdown-link>
                                    {{ __('Log Out') }}
                                </x-dropdown-link>
                            </button>
                        </x-slot>
                    </x-dropdown>
                </div>

                <!-- Hamburger -->
                <div class="flex items-center sm:hidden">
                    <button @click="open = ! open" class="nw-hover-tint inline-flex items-center justify-center p-2 rounded-md focus:outline-none transition duration-150 ease-in-out" style="color: var(--chrome-fg)">
                        <svg class="h-6 w-6" stroke="currentColor" fill="none" viewBox="0 0 24 24">
                            <path :class="{'hidden': open, 'inline-flex': ! open }" class="inline-flex" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
                            <path :class="{'hidden': ! open, 'inline-flex': open }" class="hidden" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Responsive Navigation Menu -->
    <div :class="{'block': open, 'hidden': ! open}" class="hidden sm:hidden">
        <div class="pt-2 pb-3 space-y-1">
            <x-responsive-nav-link :href="route('admin.collection.index')" :active="request()->routeIs('admin.collection.*')" wire:navigate>
                {{ __('Collection') }}
            </x-responsive-nav-link>
            @if (auth()->user()->username)
                <x-responsive-nav-link :href="route('gallery.index', ['username' => auth()->user()->username])">
                    {{ __('View gallery') }}
                </x-responsive-nav-link>
            @endif
            @can('manage-invites')
                <x-responsive-nav-link :href="route('staff.invites')" :active="request()->routeIs('staff.invites')" wire:navigate>
                    {{ __('Invites') }}
                </x-responsive-nav-link>
            @endcan
            @can('manage-platform-settings')
                <x-responsive-nav-link :href="route('staff.settings')" :active="request()->routeIs('staff.settings')" wire:navigate>
                    {{ __('Settings') }}
                </x-responsive-nav-link>
            @endcan
        </div>

        <!-- Responsive Settings Options -->
        <div class="pt-4 pb-1 border-t" style="border-color: rgba(242,239,230,.14)">
            <div class="px-4">
                {{-- Theme toggle already lives in the always-visible topbar
                     row above — no need for a second copy in here. --}}
                <div class="font-medium text-base" style="color: var(--chrome-fg)" x-data="{{ json_encode(['name' => auth()->user()->name]) }}" x-text="name" x-on:profile-updated.window="name = $event.detail.name"></div>
                <div class="font-medium text-sm" style="color: var(--flat)">{{ auth()->user()->email }}</div>
            </div>

            <div class="mt-3 space-y-1">
                <x-responsive-nav-link :href="route('profile')" wire:navigate>
                    {{ __('Profile') }}
                </x-responsive-nav-link>

                <!-- Authentication -->
                <button wire:click="logout" class="w-full text-start">
                    <x-responsive-nav-link>
                        {{ __('Log Out') }}
                    </x-responsive-nav-link>
                </button>
            </div>
        </div>
    </div>
</nav>
