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
        <div class="min-h-screen" style="background: var(--bone)">
            <livewire:layout.navigation />

            <!-- Page Heading -->
            @if (isset($header))
                <header style="background: var(--paper); box-shadow: 0 1px 2px var(--hair)">
                    <div class="max-w-7xl mx-auto py-6 px-4 sm:px-6 lg:px-8">
                        {{ $header }}
                    </div>
                </header>
            @endif

            <!-- Page Content -->
            <main>
                {{ $slot }}
            </main>
        </div>
    </body>
</html>
