<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Sentry\Laravel\Integration;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Traefik is the sole entry point in every environment this app
        // runs in (production: polaris2's Traefik; local: never sits
        // behind a proxy at all, so this is a no-op there) — trusting
        // '*' is standard for a single, always-present reverse-proxy
        // hop. Without this, url()/asset() render http:// behind
        // Traefik, and any signed URL (Breeze's password-reset link)
        // fails its signature check.
        $middleware->trustProxies(at: '*');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        Integration::handles($exceptions);
    })->create();
