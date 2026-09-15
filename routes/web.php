<?php

use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome');

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

Route::get('/{username}/gallery', \App\Livewire\Gallery\Index::class)
    ->name('gallery.index');

Route::get('/{username}/gallery/movimientos', \App\Livewire\Gallery\Movimientos::class)
    ->name('gallery.movimientos');

Route::get('/{username}/gallery/{setTcgdexId}', \App\Livewire\Gallery\Show::class)
    ->name('gallery.show');

require __DIR__.'/auth.php';
