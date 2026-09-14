<?php

use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome');

Route::view('dashboard', 'dashboard')
    ->middleware(['auth', 'verified'])
    ->name('dashboard');

Route::view('profile', 'profile')
    ->middleware(['auth'])
    ->name('profile');

Route::get('/admin/add', \App\Livewire\Admin\AddCollectionItem::class)
    ->middleware(['auth'])
    ->name('admin.collection.add');

// Temporary stub: route()/redirect()->route() resolve names eagerly, so
// 'admin.collection.index' must exist even though save() never gets followed
// in tests. Task 6 replaces this with the real collection index screen.
Route::get('/admin', \App\Livewire\Admin\AddCollectionItem::class)
    ->middleware(['auth'])
    ->name('admin.collection.index');

require __DIR__.'/auth.php';
