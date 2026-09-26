<?php

declare(strict_types=1);

use App\Models\User;
use Livewire\Volt\Volt;

test('the privacy policy is public', function () {
    $this->get('/privacy')
        ->assertOk()
        ->assertSee('Privacy policy')
        ->assertSee('Hetzner')
        ->assertSee('Your rights');
});

test('the terms are public', function () {
    $this->get('/terms')
        ->assertOk()
        ->assertSee('Terms of use')
        ->assertSee('El Salvador');
});

test('the public footer links to both pages', function () {
    $this->get('/')
        ->assertOk()
        ->assertSee(route('privacy'))
        ->assertSee(route('terms'));
});

test('the login page links to both pages', function () {
    $this->get(route('login'))
        ->assertOk()
        ->assertSee(route('privacy'))
        ->assertSee(route('terms'));
});

test('nobody can claim privacy or terms as a username', function (string $username) {
    $this->actingAs(User::factory()->create(['username' => 'oldname']));

    Volt::test('profile.update-profile-information-form')
        ->set('username', $username)
        ->call('updateProfileInformation')
        ->assertHasErrors('username');
})->with(['privacy', 'terms']);
