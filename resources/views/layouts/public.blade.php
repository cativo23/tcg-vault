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
             mockup (vault-final.html) — Colección (the set you're
             currently viewing), Sets (the list), Movimientos (Phase 4,
             not built yet — shown but inert). --}}
        <header class="nw-topbar sticky top-0 z-50 flex items-center justify-between gap-4 px-4 sm:px-6" style="height: 52px">
            <span class="flex items-center gap-2 font-semibold uppercase text-sm tracking-wide">
                <span class="nw-dot" aria-hidden="true"></span>
                tcg-vault
            </span>
            <nav class="flex gap-5 text-xs font-bold uppercase tracking-wider">
                @if (request()->routeIs('gallery.show'))
                    {{-- "Colección" is whichever set you're currently viewing —
                         it has no meaning on its own outside a set's context,
                         so it only becomes a real (self-)link there. --}}
                    <a href="{{ url()->current() }}" class="nw-link is-active">Colección</a>
                @else
                    <span class="nw-link" style="opacity: .35; cursor: default">Colección</span>
                @endif
                <a href="{{ request()->route('username') ? route('gallery.index', ['username' => request()->route('username')]) : '#' }}"
                   class="nw-link {{ request()->routeIs('gallery.index') ? 'is-active' : '' }}">Sets</a>
                <span class="nw-link" style="opacity: .35; cursor: default" title="{{ __('Coming soon') }}">Movimientos</span>
            </nav>
        </header>

        {{ $slot }}
    </body>
</html>
