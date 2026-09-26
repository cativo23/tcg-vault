<?php

use App\Models\User;
use App\Modules\Catalog\Models\Card;
use App\Modules\Catalog\Models\Set;
use App\Modules\Collection\Models\Collection;
use App\Modules\Collection\Models\CollectionItem;
use App\Modules\Invites\Models\Invite;
use Illuminate\Support\Facades\Storage;
use Livewire\Volt\Volt;

test('profile page is displayed', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    $response = $this->get('/profile');

    $response
        ->assertOk()
        ->assertSeeVolt('profile.update-profile-information-form')
        ->assertSeeVolt('profile.update-password-form')
        ->assertSeeVolt('profile.delete-user-form');
});

test('the profile page uses the apps own display heading, not a generic Breeze one', function () {
    // Was the one page left rendering <x-slot name="header"> with plain
    // Tailwind text-xl — every other admin screen (Collection, Platform
    // Settings) puts a .nw-display .nw-h1 heading directly in the body.
    $user = User::factory()->create();
    $this->actingAs($user);

    $response = $this->get('/profile');

    $response->assertOk();
    $response->assertSee('class="nw-display nw-h1 nw-h1--sm mb-4"', false);
    $response->assertDontSee('text-xl font-semibold', false);
});

test('profile information can be updated', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    $component = Volt::test('profile.update-profile-information-form')
        ->set('name', 'Test User')
        ->set('email', 'test@example.com')
        ->call('updateProfileInformation');

    $component
        ->assertHasNoErrors()
        ->assertNoRedirect();

    $user->refresh();

    $this->assertSame('Test User', $user->name);
    $this->assertSame('test@example.com', $user->email);
    $this->assertNull($user->email_verified_at);
});

test('email verification status is unchanged when the email address is unchanged', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    $component = Volt::test('profile.update-profile-information-form')
        ->set('name', 'Test User')
        ->set('email', $user->email)
        ->call('updateProfileInformation');

    $component
        ->assertHasNoErrors()
        ->assertNoRedirect();

    $this->assertNotNull($user->refresh()->email_verified_at);
});

test('username can be updated within length and character limits', function () {
    $user = User::factory()->create(['username' => 'oldname']);
    $this->actingAs($user);

    Volt::test('profile.update-profile-information-form')
        ->set('username', 'new-handle')
        ->call('updateProfileInformation');

    expect($user->refresh()->username)->toBe('new-handle');
});

test('a username shorter than 3 characters is rejected', function () {
    $user = User::factory()->create(['username' => 'oldname']);
    $this->actingAs($user);

    Volt::test('profile.update-profile-information-form')
        ->set('username', 'ab')
        ->call('updateProfileInformation')
        ->assertHasErrors('username');

    expect($user->refresh()->username)->toBe('oldname');
});

test('a username with an illegal character is rejected', function () {
    $user = User::factory()->create(['username' => 'oldname']);
    $this->actingAs($user);

    Volt::test('profile.update-profile-information-form')
        ->set('username', 'not_valid!')
        ->call('updateProfileInformation')
        ->assertHasErrors('username');

    expect($user->refresh()->username)->toBe('oldname');
});

test('a username matching one of the apps own route segments is rejected', function () {
    $user = User::factory()->create(['username' => 'oldname']);
    $this->actingAs($user);

    Volt::test('profile.update-profile-information-form')
        ->set('username', 'admin')
        ->call('updateProfileInformation')
        ->assertHasErrors('username');

    expect($user->refresh()->username)->toBe('oldname');
});

test('a username already taken by another user is rejected', function () {
    User::factory()->create(['username' => 'taken']);
    $user = User::factory()->create(['username' => 'oldname']);
    $this->actingAs($user);

    Volt::test('profile.update-profile-information-form')
        ->set('username', 'taken')
        ->call('updateProfileInformation')
        ->assertHasErrors('username');

    expect($user->refresh()->username)->toBe('oldname');
});

test('user can delete their account', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    $component = Volt::test('profile.delete-user-form')
        ->set('password', 'password')
        ->call('deleteUser');

    $component
        ->assertHasNoErrors()
        ->assertRedirect('/');

    $this->assertGuest();
    $this->assertNull($user->fresh());
});

test('deleting an account that registered through an invite also deletes that invite', function () {
    $user = User::factory()->create();
    $invite = Invite::factory()->create([
        'email' => $user->email,
        'used_at' => now(),
        'accepted_by' => $user->id,
    ]);

    $this->actingAs($user);

    Volt::test('profile.delete-user-form')
        ->set('password', 'password')
        ->call('deleteUser')
        ->assertHasNoErrors()
        ->assertRedirect('/');

    expect($user->fresh())->toBeNull()
        ->and($invite->fresh())->toBeNull();
});

test('deleting an account that created or revoked invites keeps those invites', function () {
    $admin = User::factory()->create();
    $created = Invite::factory()->create(['created_by' => $admin->id]);
    $revoked = Invite::factory()->create(['revoked_at' => now(), 'revoked_by' => $admin->id]);

    $this->actingAs($admin);

    Volt::test('profile.delete-user-form')
        ->set('password', 'password')
        ->call('deleteUser')
        ->assertHasNoErrors()
        ->assertRedirect('/');

    expect($admin->fresh())->toBeNull()
        ->and($created->fresh()->created_by)->toBeNull()
        ->and($revoked->fresh()->revoked_by)->toBeNull();
});

test('deleting an account removes its uploaded card photos but no one elses', function () {
    Storage::fake('collection-photos');
    Storage::disk('collection-photos')->put('mine.jpg', 'x');
    Storage::disk('collection-photos')->put('theirs.jpg', 'x');

    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);
    $card = Card::create(['tcgdex_id' => 'me05-116', 'set_id' => $set->id, 'local_id' => '116', 'name' => 'Mega Darkrai ex']);

    $user = User::factory()->create();
    $other = User::factory()->create();
    foreach ([[$user, 'mine.jpg'], [$other, 'theirs.jpg']] as [$owner, $photo]) {
        CollectionItem::create([
            'collection_id' => Collection::factory()->for($owner)->create()->id,
            'card_id' => $card->id, 'card_tcgdex_id' => 'me05-116',
            'condition' => 'NM', 'quantity' => 1, 'photo_path' => $photo,
        ]);
    }

    $this->actingAs($user);

    Volt::test('profile.delete-user-form')
        ->set('password', 'password')
        ->call('deleteUser')
        ->assertHasNoErrors();

    Storage::disk('collection-photos')->assertMissing('mine.jpg');
    Storage::disk('collection-photos')->assertExists('theirs.jpg');
});

test('the delete account form says exactly what deletion removes', function () {
    $this->actingAs(User::factory()->create(['username' => 'ash']));

    Volt::test('profile.delete-user-form')
        ->assertSee('your whole collection')
        ->assertSee('notes and photos')
        ->assertSee('/ash')
        ->assertSee('username becomes available')
        ->assertSee('can’t be undone')
        // No export exists yet to back up a "download your data" prompt.
        ->assertDontSee('download');
});

test('the delete account form skips the gallery line for a user with no username', function () {
    $this->actingAs(User::factory()->create(['username' => null]));

    Volt::test('profile.delete-user-form')
        ->assertSee('your whole collection')
        ->assertDontSee('goes offline');
});

test('correct password must be provided to delete account', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    $component = Volt::test('profile.delete-user-form')
        ->set('password', 'wrong-password')
        ->call('deleteUser');

    $component
        ->assertHasErrors('password')
        ->assertNoRedirect();

    $this->assertNotNull($user->fresh());
});
