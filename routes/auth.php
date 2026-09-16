<?php

use App\Http\Controllers\Auth\VerifyEmailController;
use App\Livewire\Auth\InviteRegistration;
use Illuminate\Support\Facades\Route;
use Livewire\Volt\Volt;

Route::middleware('guest')->group(function () {
    // Always registered — whether it shows the real form or an
    // invite-only notice is a runtime decision inside the component
    // itself (Setting::get('registration.open'), falling back to
    // config('tcgvault.allow_registration')), not whether this route
    // exists. Toggling registration must never make the route
    // disappear out from under someone mid-flow, and needs no deploy.
    Volt::route('register', 'pages.auth.register')
        ->name('register');

    // Deliberately ALWAYS registered, independent of allow_registration —
    // the invite flow is the beta's only way in, and toggling public
    // registration must never accidentally lock out someone who already
    // holds a valid invite link. Each invite's own isUsable() state
    // (used_at/revoked_at/expires_at), re-checked on every request, is
    // what actually gates it — never the existence of this route.
    Route::get('register/invite/{invite}/{hash}', InviteRegistration::class)
        ->middleware(['signed', 'throttle:6,1'])
        ->name('invite.accept');

    Volt::route('login', 'pages.auth.login')
        ->name('login');

    Volt::route('forgot-password', 'pages.auth.forgot-password')
        ->name('password.request');

    Volt::route('reset-password/{token}', 'pages.auth.reset-password')
        ->name('password.reset');
});

Route::middleware('auth')->group(function () {
    Volt::route('verify-email', 'pages.auth.verify-email')
        ->name('verification.notice');

    Route::get('verify-email/{id}/{hash}', VerifyEmailController::class)
        ->middleware(['signed', 'throttle:6,1'])
        ->name('verification.verify');

    Volt::route('confirm-password', 'pages.auth.confirm-password')
        ->name('password.confirm');
});
