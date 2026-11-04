@php
    $siteName = 'tcg-vault'; // the brand, not APP_NAME — the spec names the product
    $pageTitle = isset($title) && $title !== '' ? "{$title} · {$siteName}" : $siteName;
    $pageDescription = $description ?? 'Every card you own, on one page.';
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ $pageTitle }}</title>
        <meta name="description" content="{{ $pageDescription }}">
        <link rel="canonical" href="{{ $canonical ?? url()->current() }}">

        <meta property="og:type" content="website">
        <meta property="og:site_name" content="{{ $siteName }}">
        <meta property="og:title" content="{{ $pageTitle }}">
        <meta property="og:description" content="{{ $pageDescription }}">
        <meta property="og:url" content="{{ $canonical ?? url()->current() }}">
        @isset($ogImage)
            <meta property="og:image" content="{{ $ogImage }}">
            <meta name="twitter:card" content="summary_large_image">
        @else
            <meta name="twitter:card" content="summary">
        @endisset

        {{-- Runs before first paint: motion mode (js + moving/still), the
             ?progress= capture switch, and the stored theme (tcg-vault-theme).
             ?theme= overrides the stored choice for captures. --}}
        <script>
            (function () {
                var q = new URLSearchParams(location.search), h = document.documentElement;
                h.classList.add('js');
                var reduce = q.get('motion') === 'off' || (window.matchMedia && matchMedia('(prefers-reduced-motion: reduce)').matches);
                h.classList.add(reduce ? 'still' : 'moving');
                if (!reduce && q.get('progress') != null) h.classList.add('dbg');
                var t = q.get('theme');
                if (!t) { try { t = localStorage.getItem('tcg-vault-theme'); } catch (e) {} }
                if (t === 'dark' || t === 'light') h.setAttribute('data-theme', t);
            })();
        </script>

        <link rel="icon" href="data:image/svg+xml,{{ rawurlencode('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 32 32"><rect x="1" y="1" width="30" height="30" rx="7" fill="#141412"/><path d="M2 22 L14 24 L26 10" fill="none" stroke="#f2efe6" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/><circle cx="26" cy="10" r="3" fill="#37d17f"/></svg>') }}">

        <link rel="preconnect" href="https://assets.tcgdex.net">

        @vite(['resources/css/revamp.css', 'resources/js/app.js'])
    </head>
    <body>
        <a class="skip" href="#main">Skip to content</a>

        <header class="bar">
            <a class="mark" href="{{ url('/') }}">tcg-vault</a>
            <div class="bar-end">
                <button type="button" class="theme-toggle" data-theme-toggle aria-label="Toggle dark mode">
                    <svg class="i-sun" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="4"></circle><path d="M12 2v2M12 20v2M4.93 4.93l1.41 1.41M17.66 17.66l1.41 1.41M2 12h2M20 12h2M6.34 17.66l-1.41 1.41M19.07 4.93l-1.41 1.41"></path></svg>
                    <svg class="i-moon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"></path></svg>
                </button>
                <a class="bar-link" href="{{ route('login') }}">Log in</a>
            </div>
        </header>

        <main id="main">{{ $slot }}</main>

        <footer class="foot">
            <div>
                <span class="fbrand">tcg-vault</span>
                Card data, artwork and prices via <a href="https://tcgdex.dev" rel="noopener">tcgdex.dev</a>, refreshed daily.
                Prices are market references, not appraisals.
            </div>
            <div class="foot-r">
                A personal collection archive. Not affiliated with Nintendo, Creatures, GAME FREAK or The Pokémon Company.
                <div class="legal"><a href="{{ route('privacy') }}">Privacy</a> · <a href="{{ route('terms') }}">Terms</a></div>
            </div>
        </footer>

        @stack('scripts')
    </body>
</html>
