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

        <title>{{ config('app.name', 'Laravel') }}</title>

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
