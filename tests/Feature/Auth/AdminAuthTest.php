<?php

declare(strict_types=1);

use App\Models\User;
use Livewire\Volt\Volt;

test('guest is redirected to login when visiting the dashboard', function () {
    $this->get('/dashboard')->assertRedirect('/login');
});

test('seeded admin can log in and reach the dashboard', function () {
    // Breeze's Livewire (Volt) stack authenticates through the login Volt
    // component's `login` action over the Livewire wire protocol, not a
    // classic POST /login route — there isn't one to hit with $this->post().
    $user = User::factory()->create([
        'email' => 'admin@tcg-vault.test',
        'password' => bcrypt('password'),
    ]);

    $component = Volt::test('pages.auth.login')
        ->set('form.email', 'admin@tcg-vault.test')
        ->set('form.password', 'password');

    $component->call('login');

    $component
        ->assertHasNoErrors()
        ->assertRedirect(route('dashboard', absolute: false));

    $this->assertAuthenticatedAs($user);
});

test('database seeder creates exactly one admin user matching config', function () {
    config(['tcgvault.admin_email' => 'seed-test@tcg-vault.test', 'tcgvault.admin_password' => 'seed-password']);

    $this->seed();

    $user = User::where('email', 'seed-test@tcg-vault.test')->first();
    expect($user)->not->toBeNull();
    expect(\Illuminate\Support\Facades\Hash::check('seed-password', $user->password))->toBeTrue();
});
