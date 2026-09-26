<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Collection\Models\Collection;
use Livewire\Volt\Volt;

test('a suspended member cannot log in, even with the right password', function () {
    $user = User::factory()->create(['suspended_at' => now()]);

    Volt::test('pages.auth.login')
        ->set('form.email', $user->email)
        ->set('form.password', 'password')
        ->call('login')
        ->assertHasErrors(['form.email']);

    $this->assertGuest();
});

test('a suspended member is told why they cannot log in', function () {
    $user = User::factory()->create(['suspended_at' => now()]);

    Volt::test('pages.auth.login')
        ->set('form.email', $user->email)
        ->set('form.password', 'password')
        ->call('login')
        ->assertSee('This account is suspended.');
});

test('a wrong password on a suspended account does not reveal the suspension', function () {
    $user = User::factory()->create(['suspended_at' => now()]);

    Volt::test('pages.auth.login')
        ->set('form.email', $user->email)
        ->set('form.password', 'wrong-password')
        ->call('login')
        ->assertDontSee('This account is suspended.');
});

test('a member suspended while signed in is logged out on their next request', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    $user->forceFill(['suspended_at' => now()])->save();

    $this->get('/profile')
        ->assertRedirect(route('login'))
        ->assertSessionHas('status', 'This account is suspended.');

    $this->assertGuest();
    $this->get(route('login'))->assertSee('This account is suspended.');
});

test('a suspended member’s public page is not shown', function () {
    $user = User::factory()->create(['username' => 'suspended-sam', 'suspended_at' => now()]);
    Collection::factory()->for($user)->create(['is_public' => true]);

    $this->get('/suspended-sam')->assertNotFound();
});

test('an active member is unaffected', function () {
    $user = User::factory()->create(['username' => 'active-ash']);
    Collection::factory()->for($user)->create(['is_public' => true]);

    $this->get('/active-ash')->assertOk();
    $this->actingAs($user)->get('/profile')->assertOk();
});
