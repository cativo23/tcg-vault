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

        {{-- Brand mark as favicon: the ink square and the one signal dot. --}}
        <link rel="icon" href="data:image/svg+xml,{{ rawurlencode('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 32 32"><rect width="32" height="32" rx="7" fill="#141412"/><circle cx="16" cy="16" r="6" fill="#37d17f"/></svg>') }}">

        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=Archivo:wdth,wght@62..125,400..900&family=Martian+Mono:wdth,wght@75..112.5,300..800&display=swap" rel="stylesheet">

        <!-- Scripts -->
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="font-sans antialiased">
        <div class="min-h-screen flex flex-col sm:justify-center items-center pt-6 sm:pt-0 px-4" style="background: var(--bone)">
            <div class="w-full sm:max-w-md">
                {{ $slot }}
            </div>
        </div>
    </body>
</html>
