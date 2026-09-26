@props([
    'code',
    'title',
    'message',
    // Defaults to "Back to login" for every error page except 404, which
    // passes its own — a stale or mistyped gallery link is a normal,
    // expected 404 on a public site, and the visitor hitting it may not
    // have an account to log in to at all.
    'linkRoute' => 'login',
    'linkLabel' => null,
])

@php
    $linkLabel ??= __('Back to login');
@endphp

{{--
    The branded shell for every HTTP error page (403/404/419/500/…).
    Laravel's exception handler renders these views directly — no
    controller, no Livewire — so this mirrors layouts/guest.blade.php by
    hand instead of using Livewire's #[Layout] attribute.
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <script>
            (function () {
                var t = localStorage.getItem('tcg-vault-theme');
                if (t) document.documentElement.setAttribute('data-theme', t);
            })();
        </script>

        <title>{{ $code }} — {{ config('app.name', 'tcg-vault') }}</title>

        <link rel="icon" href="data:image/svg+xml,{{ rawurlencode('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 32 32"><rect x="1" y="1" width="30" height="30" rx="7" fill="#141412"/><path d="M2 22 L14 24 L26 10" fill="none" stroke="#f2efe6" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/><circle cx="26" cy="10" r="3" fill="#37d17f"/></svg>') }}">

        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=Archivo:wdth,wght@62..125,400..900&family=Martian+Mono:wdth,wght@75..112.5,300..800&display=swap" rel="stylesheet">

        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="font-sans antialiased">
        <div class="min-h-screen flex flex-col justify-center items-center px-4 text-center" style="background: var(--bone)">
            <div class="nw-eyebrow" style="color: var(--danger)">{{ $code }}</div>
            <h1 class="nw-h1 nw-display" style="margin-top: 0.5rem;">{{ $title }}</h1>
            <p style="margin-top: 1rem; max-width: 32rem; color: var(--muted)">{{ $message }}</p>
            <a href="{{ route($linkRoute) }}" class="nw-pill-select" style="margin-top: 2rem; text-decoration: none; display: inline-block;">
                {{ $linkLabel }}
            </a>
        </div>
    </body>
</html>
