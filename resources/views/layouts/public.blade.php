@php
    $username = request()->route('username');
    $siteName = 'tcg-vault'; // the brand, not APP_NAME — the spec names the product
    $pageTitle = isset($title) && $title !== '' ? "{$title} · {$siteName}" : $siteName;
    $pageDescription = $description ?? 'A Pokémon TCG collection, catalogued card by card with live market value.';
    $isActive = fn (string ...$routes) => request()->routeIs(...$routes) ? 'page' : null;
    $registrationOpen = app(\App\Settings\RegistrationSettings::class)->open;
    // Every screen that resolves a $targetUser already computes this via
    // ResolvesPublicCollection::isOwnerViewing() and passes it through
    // layoutData — that ID-based check is the single source of truth for
    // "is this the collector's own page", so the layout must never
    // re-derive its own (username-string) version of the same rule.
    // Screens with no target user (the '/' home page) never pass it, so
    // it defaults closed.
    $isOwner = $isOwner ?? false;
@endphp
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

        {{-- Brand mark as favicon: the Vault Line — a rising sparkline running off
             the ink square's edge into a signal-green node, echoing the app's own
             daily value-tracking chart. --}}
        <link rel="icon" href="data:image/svg+xml,{{ rawurlencode('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 32 32"><rect x="1" y="1" width="30" height="30" rx="7" fill="#141412"/><path d="M2 22 L14 24 L26 10" fill="none" stroke="#f2efe6" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/><circle cx="26" cy="10" r="3" fill="#37d17f"/></svg>') }}">

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
                    <svg class="nw-mark" viewBox="0 0 64 32" aria-hidden="true">
                        <polyline points="4,22 16,27 28,15 40,19 52,7" fill="none" stroke="#f2efe6" stroke-width="4.5" stroke-linecap="round" stroke-linejoin="round"/>
                        <circle cx="52" cy="7" r="4.5" fill="#37d17f"/>
                    </svg>
                    tcg-vault
                </a>

                @if ($username)
                    <nav class="nw-nav" aria-label="Gallery">
                        <a href="{{ route('gallery.index', ['username' => $username]) }}" @if ($isActive('gallery.index', 'gallery.card')) aria-current="page" @endif wire:navigate>Collection</a>
                        <a href="{{ route('gallery.sets', ['username' => $username]) }}" @if ($isActive('gallery.sets', 'gallery.show')) aria-current="page" @endif wire:navigate>Sets</a>
                        <a href="{{ route('gallery.activity', ['username' => $username]) }}" @if ($isActive('gallery.activity')) aria-current="page" @endif wire:navigate>Activity</a>
                    </nav>
                @endif

                <div class="flex items-center gap-2">
                    <x-theme-toggle />

                    @if ($isOwner)
                        {{-- Only the collector looking at their OWN page gets this —
                             @auth alone used to show it to any logged-in visitor,
                             linking to THEIR admin area while browsing someone
                             else's collection. "Manage collection" instead of the
                             old "Admin" label: this is "manage my own cards," not
                             a backend/technical destination. $isOwner comes from
                             the component's layoutData, not a re-derived check
                             here — see the @php block above. --}}
                        <a href="{{ route('admin.collection.index') }}" class="nw-nav-ghost">{{ __('Manage collection') }}</a>
                    @endif

                    @guest
                        <a href="{{ route('login') }}" class="nw-nav-ghost" wire:navigate>{{ __('Log in') }}</a>
                        @if ($registrationOpen)
                            <a href="{{ route('register') }}" class="nw-nav-ghost" wire:navigate>{{ __('Sign up') }}</a>
                        @endif
                    @endguest
                </div>
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
