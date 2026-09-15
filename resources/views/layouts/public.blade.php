@php
    $username = request()->route('username');
    $siteName = 'tcg-vault'; // the brand, not APP_NAME — the spec names the product
    $pageTitle = isset($title) && $title !== '' ? "{$title} · {$siteName}" : $siteName;
    $pageDescription = $description ?? 'A Pokémon TCG collection, catalogued card by card with live market value.';
    $isActive = fn (string ...$routes) => request()->routeIs(...$routes) ? 'page' : null;
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

        {{-- Brand mark as favicon: the ink square and the one signal dot. --}}
        <link rel="icon" href="data:image/svg+xml,{{ rawurlencode('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 32 32"><rect width="32" height="32" rx="7" fill="#141412"/><circle cx="16" cy="16" r="6" fill="#37d17f"/></svg>') }}">

        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=Archivo:wdth,wght@62..125,400..900&family=Martian+Mono:wdth,wght@75..112.5,300..800&display=swap" rel="stylesheet">
        <link rel="preconnect" href="https://assets.tcgdex.net">

        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="antialiased">
        <a href="#main" class="sr-only focus:not-sr-only focus:absolute focus:z-[70] focus:m-2 focus:px-3 focus:py-2" style="background: var(--signal); color: var(--ink)">Skip to content</a>

        <header class="nw-topbar sticky top-0 z-50">
            <div class="nw-wrap flex items-center justify-between gap-4" style="height: 52px">
                <a href="{{ $username ? route('gallery.index', ['username' => $username]) : url('/') }}" class="nw-brand" wire:navigate>
                    <span class="nw-dot" aria-hidden="true"></span>
                    tcg-vault
                </a>

                @if ($username)
                    <nav class="nw-nav" aria-label="Gallery">
                        <a href="{{ route('gallery.index', ['username' => $username]) }}" @if ($isActive('gallery.index', 'gallery.card')) aria-current="page" @endif wire:navigate>Collection</a>
                        <a href="{{ route('gallery.sets', ['username' => $username]) }}" @if ($isActive('gallery.sets', 'gallery.show')) aria-current="page" @endif wire:navigate>Sets</a>
                        <a href="{{ route('gallery.activity', ['username' => $username]) }}" @if ($isActive('gallery.activity')) aria-current="page" @endif wire:navigate>Activity</a>
                    </nav>
                @endif

                @auth
                    {{-- The owner, viewing their own public gallery while logged
                         in, needs a way back to /admin — every other visitor here
                         is a guest by definition. --}}
                    <a href="{{ route('admin.collection.index') }}" class="nw-nav-ghost">{{ __('Admin') }}</a>
                @else
                    <span class="w-[52px] sm:w-[62px]" aria-hidden="true"></span>
                @endauth
            </div>
        </header>

        <div class="nw-main">
            <main id="main" class="nw-grow">
                {{ $slot }}
            </main>

            <footer class="nw-footer">
                <div class="nw-wrap flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
                    <div>
                        <div class="fbrand">tcg-vault</div>
                        Card data, artwork and prices via <a href="https://tcgdex.dev" rel="noopener">tcgdex.dev</a>, refreshed daily.
                        Prices are market references, not appraisals.
                    </div>
                    <div class="sm:text-right sm:max-w-xs">
                        A personal collection archive. Not affiliated with Nintendo, Creatures, GAME FREAK or The Pokémon Company.
                    </div>
                </div>
            </footer>
        </div>
    </body>
</html>
