<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ $title ?? config('app.name', 'tcg-vault') }}</title>

        <!-- Scripts -->
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="font-sans antialiased" style="background: var(--bone); color: var(--ink)">
        {{-- Section-switcher header, ported from the original approved
             mockup (vault-final.html) — Collection (the set you're
             currently viewing), Sets (the list), Activity (Phase 4:
             price deltas + activity feed). Nav labels are English per
             Carlos's explicit call — the mockup's original Spanish
             labels (Colección/Movimientos) were carried over verbatim
             at first but that wasn't the intent. --}}
        <header class="nw-topbar sticky top-0 z-50 flex items-center justify-between gap-4 px-4 sm:px-6" style="height: 52px">
            <span class="flex items-center gap-2 font-semibold uppercase text-sm tracking-wide">
                <span class="nw-dot" aria-hidden="true"></span>
                tcg-vault
            </span>
            <nav class="flex gap-5 text-xs font-bold uppercase tracking-wider">
                @if (request()->routeIs('gallery.show'))
                    {{-- "Collection" is whichever set you're currently viewing —
                         it has no meaning on its own outside a set's context,
                         so it only becomes a real (self-)link there. --}}
                    <a href="{{ url()->current() }}" class="nw-link is-active">Collection</a>
                @else
                    <span class="nw-link" style="opacity: .35; cursor: default">Collection</span>
                @endif
                <a href="{{ request()->route('username') ? route('gallery.index', ['username' => request()->route('username')]) : '#' }}"
                   class="nw-link {{ request()->routeIs('gallery.index') ? 'is-active' : '' }}">Sets</a>
                <a href="{{ request()->route('username') ? route('gallery.movimientos', ['username' => request()->route('username')]) : '#' }}"
                   class="nw-link {{ request()->routeIs('gallery.movimientos') ? 'is-active' : '' }}">Activity</a>
            </nav>
            @auth
                {{-- Carlos, viewing his own public gallery while logged
                     in, needs a quick way back to /admin — nothing in
                     this layout otherwise links there, since every other
                     public-gallery visitor is a guest by definition. --}}
                <a href="{{ route('admin.collection.index') }}" class="nw-btn-secondary text-xs px-3 py-1.5 whitespace-nowrap">
                    {{ __('Admin') }}
                </a>
            @endauth
        </header>

        {{ $slot }}
    </body>
</html>
