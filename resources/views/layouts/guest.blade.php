<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        {{-- Sets data-theme from localStorage before anything else renders,
             so there's no flash of the wrong theme. Absent (never toggled)
             means the prefers-color-scheme block in app.css decides. --}}
        <script>
            (function () {
                var t = localStorage.getItem('tcg-vault-theme');
                if (t) document.documentElement.setAttribute('data-theme', t);
            })();
        </script>

        <title>{{ config('app.name', 'tcg-vault') }}</title>

        {{-- Brand mark as favicon: the Vault Line — a rising sparkline running off
             the ink square's edge into a signal-green node, echoing the app's own
             daily value-tracking chart. --}}
        <link rel="icon" href="data:image/svg+xml,{{ rawurlencode('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 32 32"><rect x="1" y="1" width="30" height="30" rx="7" fill="#141412"/><path d="M2 22 L14 24 L26 10" fill="none" stroke="#f2efe6" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/><circle cx="26" cy="10" r="3" fill="#37d17f"/></svg>') }}">

        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=Archivo:wdth,wght@62..125,400..900&family=Martian+Mono:wdth,wght@75..112.5,300..800&display=swap" rel="stylesheet">

        <!-- Scripts -->
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="font-sans antialiased">
        <div class="min-h-screen flex flex-col sm:justify-center items-center pt-6 sm:pt-0 px-4" style="background: var(--bone)">
            <div class="w-full sm:max-w-md">
                {{--
                    Shared here, not per-page — login used to carry its own
                    ad hoc header (the old flat-dot mark, never updated to
                    the Vault Line redesign) while forgot/reset-password had
                    none at all. One wordmark for every guest page keeps
                    them from drifting apart again.

                    .nw-brand/.nw-mark are styled for the always-dark
                    .nw-topbar chrome (frozen, never themed) — this page
                    sits on the themed var(--bone), so color is overridden
                    to var(--ink) and the mark's stroke set to currentColor
                    instead of the navbar's hardcoded light tone, or the
                    mark disappears against a light-mode background.
                --}}
                <div class="flex justify-center mb-6">
                    <a href="{{ route('home') }}" class="nw-brand" style="color: var(--ink)" wire:navigate.hover>
                        <svg class="nw-mark" viewBox="0 0 64 32" aria-hidden="true">
                            <polyline points="4,22 16,27 28,15 40,19 52,7" fill="none" stroke="currentColor" stroke-width="4.5" stroke-linecap="round" stroke-linejoin="round"/>
                            <circle cx="52" cy="7" r="4.5" fill="#37d17f"/>
                        </svg>
                        tcg-vault
                    </a>
                </div>

                {{ $slot }}
            </div>
        </div>
    </body>
</html>
