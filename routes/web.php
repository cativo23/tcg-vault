<?php

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

Route::get('/admin/add', \App\Livewire\Admin\AddCollectionItem::class)
    ->middleware(['auth'])
    ->name('admin.collection.add');

Route::get('/admin', \App\Livewire\Admin\CollectionItems::class)
    ->middleware(['auth'])
    ->name('admin.collection.index');

// Public gallery. The literal segments (sets, activity, movimientos) MUST
// be registered before the `{setTcgdexId}` wildcard or the wildcard eats
// them. tcgdex set ids are short alphanumerics ("me05", "swsh12pt5") so
// none of these words can collide with a real set.
Route::get('/{username}/gallery', \App\Livewire\Gallery\Index::class)
    ->name('gallery.index');

Route::get('/{username}/gallery/sets', \App\Livewire\Gallery\Sets::class)
    ->name('gallery.sets');

Route::get('/{username}/gallery/activity', \App\Livewire\Gallery\Activity::class)
    ->name('gallery.activity');

// The screen shipped under its Spanish working title; the URL follows the
// English UI now, and the old path keeps working for anyone who linked it.
Route::get('/{username}/gallery/movimientos', fn (string $username) => redirect()->route('gallery.activity', ['username' => $username], 301))
    ->name('gallery.movimientos');

Route::get('/{username}/gallery/{setTcgdexId}', \App\Livewire\Gallery\Show::class)
    ->name('gallery.show');

Route::get('/{username}/gallery/{setTcgdexId}/{localId}', \App\Livewire\Gallery\CardShow::class)
    ->name('gallery.card');

require __DIR__.'/auth.php';
