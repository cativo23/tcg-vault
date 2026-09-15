<?php

use App\Livewire\Admin\AddCollectionItem;
use App\Livewire\Admin\CollectionItems;
use App\Livewire\Gallery\Activity;
use App\Livewire\Gallery\CardShow;
use App\Livewire\Gallery\Index;
use App\Livewire\Gallery\Sets;
use App\Livewire\Gallery\Show;
use App\Models\User;
use Illuminate\Support\Facades\Route;

// The front door is the collector's public gallery. A single-owner vault
// has exactly one gallery worth landing on: the configured admin username
// when set, otherwise the first account that has a username at all.
// Nothing to show yet (fresh install) → the login screen.
Route::get('/', function () {
    $owner = User::query()
        ->when(config('tcgvault.admin_username'), fn ($q, $username) => $q->where('username', $username))
        ->whereNotNull('username')
        ->orderBy('id')
        ->first();

    return $owner
        ? redirect()->route('gallery.index', ['username' => $owner->username])
        : redirect()->route('login');
})->name('home');

// Breeze's installer wired 'dashboard' as the post-login landing route
// name (see resources/views/livewire/pages/auth/login.blade.php's
// redirectIntended default). Rather than hunting down and changing that
// constant, the route keeps its name but now forwards to the real
// landing page below — one obvious place to change if the target moves.
Route::redirect('/dashboard', '/admin')
    ->middleware(['auth'])
    ->name('dashboard');

Route::view('profile', 'profile')
    ->middleware(['auth'])
    ->name('profile');

Route::get('/admin/add', AddCollectionItem::class)
    ->middleware(['auth'])
    ->name('admin.collection.add');

Route::get('/admin', CollectionItems::class)
    ->middleware(['auth'])
    ->name('admin.collection.index');

// Every auth.php route (login, register, forgot-password, the
// reset-password/{token} and verify-email/{id}/{hash} routes) MUST be
// registered before any /{username}/... route below. Since the gallery
// URL structure dropped its literal "gallery" middle segment,
// /{username}/{setTcgdexId} is now a bare two-segment dynamic/dynamic
// pattern with nothing anchoring it — it would otherwise match a URL
// like /reset-password/{token} just as well as auth.php's own route
// does, and whichever was registered first would win.
require __DIR__.'/auth.php';

// Public gallery. The literal segments (sets, activity, movimientos) MUST
// be registered before the `{setTcgdexId}` wildcard or the wildcard eats
// them. tcgdex set ids are short alphanumerics ("me05", "swsh12pt5") so
// none of these words can collide with a real set.
Route::get('/{username}/sets', Sets::class)
    ->name('gallery.sets');

Route::get('/{username}/activity', Activity::class)
    ->name('gallery.activity');

// The screen shipped under its Spanish working title; the URL follows the
// English UI now, and the old path keeps working for anyone who linked it.
Route::get('/{username}/movimientos', fn (string $username) => redirect()->route('gallery.activity', ['username' => $username], 301))
    ->name('gallery.movimientos');

// Old URL, before the gallery moved from /{username}/gallery to
// /{username} — keeps working for anyone who linked it. Must also be
// registered here, before the {setTcgdexId} wildcard below, or the
// wildcard would eat "gallery" as a fake set id instead of ever
// reaching this redirect.
Route::get('/{username}/gallery', fn (string $username) => redirect()->route('gallery.index', ['username' => $username], 301));

Route::get('/{username}/{setTcgdexId}', Show::class)
    ->name('gallery.show');

Route::get('/{username}/{setTcgdexId}/{localId}', CardShow::class)
    ->name('gallery.card');

// The collector's whole collection, at the shortest possible URL — the
// one meant to actually get shared. Kept as the LAST route in the file,
// after every literal top-level path above (admin, profile, dashboard,
// and everything auth.php just registered), for the same single-segment
// collision reason.
Route::get('/{username}', Index::class)
    ->name('gallery.index');
