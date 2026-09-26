<?php

declare(strict_types=1);

use App\Livewire\Auth\InviteRegistration;
use App\Models\User;
use App\Modules\Invites\Models\Invite;
use App\Settings\RegistrationSettings;
use App\Support\AgeScreen;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Schema;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Livewire\Volt\Volt;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    Carbon::setTestNow('2026-09-25 12:00:00');
    Role::findOrCreate('user');
    app(RegistrationSettings::class)->open = true;
    app(RegistrationSettings::class)->save();
});

function openSignup(int $month, int $year): Testable
{
    return Volt::test('pages.auth.register')
        ->set('name', 'Test User')->set('username', 'testuser')->set('email', 'test@example.com')
        ->set('password', 'password')->set('password_confirmation', 'password')
        ->set('birth_month', $month)->set('birth_year', $year);
}

test('an adult signs up without a guardian box', function () {
    openSignup(1, 1990)->call('register')->assertHasNoErrors();

    expect(User::where('email', 'test@example.com')->exists())->toBeTrue();
});

test('birth month and year are required and must be real', function (mixed $month, mixed $year, string $field) {
    openSignup(1, 1990)->set('birth_month', $month)->set('birth_year', $year)
        ->call('register')->assertHasErrors($field);

    expect(User::count())->toBe(0);
})->with([
    'no month' => ['', 1990, 'birth_month'],
    'month 13' => [13, 1990, 'birth_month'],
    'no year' => [1, '', 'birth_year'],
    'future year' => [1, 2030, 'birth_year'],
    'implausible year' => [1, 1850, 'birth_year'],
]);

test('under 13 is refused neutrally and a cookie blocks retrying', function () {
    openSignup(9, 2013)->call('register')
        ->assertHasErrors('birth_year')
        ->assertSee('We can’t create an account for you.', false);

    expect(User::count())->toBe(0)
        ->and(Cookie::hasQueued(AgeScreen::BLOCK_COOKIE))->toBeTrue();
});

test('with the block cookie, a different birth date is still refused', function () {
    Livewire::withCookies([AgeScreen::BLOCK_COOKIE => '1']);

    openSignup(1, 1990)->call('register')->assertHasErrors('birth_year');

    expect(User::count())->toBe(0);
});

test('13 to 17 needs the guardian box ticked', function () {
    openSignup(8, 2012)->call('register')->assertHasErrors('guardian_consent');
    expect(User::count())->toBe(0);

    openSignup(8, 2012)->set('guardian_consent', true)->call('register')->assertHasNoErrors();
    expect(User::count())->toBe(1);
});

test('the birth date is never stored', function () {
    openSignup(8, 2012)->set('guardian_consent', true)->call('register');

    expect(Schema::hasColumn('users', 'birth_month'))->toBeFalse()
        ->and(Schema::hasColumn('users', 'birth_year'))->toBeFalse()
        ->and(User::first()->getAttributes())->not->toHaveKeys(['birth_month', 'birth_year', 'guardian_consent']);
});

test('the invite signup applies the same age screen', function () {
    $invite = Invite::factory()->create(['email' => 'kid@example.com']);
    $component = fn () => Livewire::test(InviteRegistration::class, ['invite' => $invite, 'hash' => sha1('kid@example.com')])
        ->set('name', 'Kid')->set('username', 'kid')
        ->set('password', 'a-real-password')->set('password_confirmation', 'a-real-password');

    $component()->set('birth_month', 9)->set('birth_year', 2013)->call('register')->assertHasErrors('birth_year');
    $component()->set('birth_month', 8)->set('birth_year', 2012)->call('register')->assertHasErrors('guardian_consent');
    expect(User::where('email', 'kid@example.com')->exists())->toBeFalse();

    $component()->set('birth_month', 8)->set('birth_year', 2012)->set('guardian_consent', true)
        ->call('register')->assertHasNoErrors();
    expect(User::where('email', 'kid@example.com')->exists())->toBeTrue();
});

test('the signup form never hints at the age cutoff', function () {
    $this->get(route('register'))
        ->assertOk()
        ->assertSee('Birth month')
        ->assertDontSee('at least 13')
        ->assertDontSee('under 13')
        ->assertDontSee('13 or older')
        ->assertDontSee('13+');
});
