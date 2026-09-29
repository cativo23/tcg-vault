<?php

declare(strict_types=1);

use App\Livewire\Auth\InviteRegistration;
use App\Models\User;
use App\Modules\Invites\Models\Invite;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Livewire\Volt\Volt;
use Spatie\Permission\Models\Role;

const USER_EMAIL_MIGRATION = 'migrations/2026_09_29_200000_make_user_email_unique_case_insensitive.php';

test('the database refuses a second account whose email differs only in case', function () {
    User::factory()->create(['email' => 'ash@example.com']);

    expect(fn () => User::factory()->create(['email' => 'Ash@Example.com']))
        ->toThrow(QueryException::class);
});

test('accepting an invite stored with capitals creates the account in lowercase', function () {
    Role::findOrCreate('user');
    $invite = Invite::factory()->create(['email' => 'Legacy@Example.com']);

    Livewire::test(InviteRegistration::class, ['invite' => $invite, 'hash' => sha1($invite->email)])
        ->set('name', 'Legacy')
        ->set('username', 'legacy')
        ->set('password', 'a-real-password')
        ->set('password_confirmation', 'a-real-password')
        ->set('birth_month', 1)
        ->set('birth_year', 1990)
        ->call('register')
        ->assertRedirect();

    expect(User::where('username', 'legacy')->value('email'))->toBe('legacy@example.com');
});

test('a password reset link can be requested with the email in any case', function () {
    Notification::fake();
    $user = User::factory()->create(['email' => 'ash@example.com']);

    Volt::test('pages.auth.forgot-password')
        ->set('email', 'ASH@Example.com')
        ->call('sendPasswordResetLink')
        ->assertHasNoErrors();

    Notification::assertSentTo($user, ResetPassword::class);
});

test('the password can be reset with the email typed in any case', function () {
    Notification::fake();
    $user = User::factory()->create(['email' => 'ash@example.com']);

    Volt::test('pages.auth.forgot-password')->set('email', 'ash@example.com')->call('sendPasswordResetLink');

    Notification::assertSentTo($user, ResetPassword::class, function ($notification) use ($user) {
        Volt::test('pages.auth.reset-password', ['token' => $notification->token])
            ->set('email', 'Ash@EXAMPLE.com')
            ->set('password', 'new-password-123')
            ->set('password_confirmation', 'new-password-123')
            ->call('resetPassword')
            ->assertHasNoErrors();

        return Hash::check('new-password-123', $user->refresh()->password);
    });
});

test('the migration lowercases existing emails and builds the case-insensitive index', function () {
    DB::statement('DROP INDEX IF EXISTS users_email_lower_unique');
    $user = User::factory()->create(['email' => 'Mixed@Example.com']);

    (require database_path(USER_EMAIL_MIGRATION))->up();

    expect($user->refresh()->email)->toBe('mixed@example.com')
        ->and(DB::selectOne("select indexdef from pg_indexes where indexname = 'users_email_lower_unique'")->indexdef)
        ->toContain('lower');
});

test('the migration stops instead of choosing between two accounts for one inbox', function () {
    DB::statement('DROP INDEX IF EXISTS users_email_lower_unique');
    $first = User::factory()->create(['email' => 'dup@example.com']);
    $second = User::factory()->create(['email' => 'Dup@example.com']);

    expect(fn () => (require database_path(USER_EMAIL_MIGRATION))->up())
        ->toThrow(RuntimeException::class);

    expect($first->refresh()->email)->toBe('dup@example.com')
        ->and($second->refresh()->email)->toBe('Dup@example.com');
});

test('signing in works with the email typed in any case', function () {
    $user = User::factory()->create(['email' => 'ash@example.com']);

    Volt::test('pages.auth.login')
        ->set('form.email', 'Ash@EXAMPLE.com')
        ->set('form.password', 'password')
        ->call('login')
        ->assertHasNoErrors();

    $this->assertAuthenticatedAs($user);
});

test('the migration drops reset links stored under a capitalised address instead of failing', function () {
    DB::statement('DROP INDEX IF EXISTS users_email_lower_unique');
    User::factory()->create(['email' => 'Mixed@Example.com']);
    // A capitalised token next to its lowercase twin would collide on the
    // table's primary key if it were moved instead.
    DB::table('password_reset_tokens')->insert([
        ['email' => 'Mixed@Example.com', 'token' => 'old', 'created_at' => now()],
        ['email' => 'mixed@example.com', 'token' => 'new', 'created_at' => now()],
    ]);

    (require database_path(USER_EMAIL_MIGRATION))->up();

    expect(DB::table('password_reset_tokens')->pluck('email')->all())->toBe(['mixed@example.com']);
});

test('the admin seeder stores the admin email in lowercase and finds it again on a re-seed', function () {
    Role::findOrCreate('super-admin');
    config([
        'tcgvault.admin_email' => 'Carlos@Example.com',
        'tcgvault.admin_password' => 'a-real-password',
        'tcgvault.admin_username' => 'carlos',
    ]);

    $this->seed();
    $this->seed();

    expect(User::where('username', 'carlos')->value('email'))->toBe('carlos@example.com')
        ->and(User::whereRaw('LOWER(email) = ?', ['carlos@example.com'])->count())->toBe(1);
});

test('the migration names accounts by id, not address, when it stops', function () {
    DB::statement('DROP INDEX IF EXISTS users_email_lower_unique');
    $first = User::factory()->create(['email' => 'secret@example.com']);
    $second = User::factory()->create(['email' => 'Secret@example.com']);

    try {
        (require database_path(USER_EMAIL_MIGRATION))->up();
        $this->fail('The migration should have stopped.');
    } catch (RuntimeException $exception) {
        expect($exception->getMessage())
            ->toContain((string) $first->id)
            ->toContain((string) $second->id)
            ->not->toContain('secret@example.com');
    }
});
