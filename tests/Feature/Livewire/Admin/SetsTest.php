<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Catalog\Models\Card;
use App\Modules\Catalog\Models\Set;
use App\Modules\Collection\Models\Collection;
use App\Modules\Collection\Models\CollectionItem;
use Database\Seeders\PermissionSeeder;

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
});

function mySetsOwn(User $user, Set $set, string $localId, string $name, string $slug = 'my-collection'): Card
{
    $card = Card::create([
        'tcgdex_id' => $set->tcgdex_id.'-'.$localId, 'set_id' => $set->id,
        'local_id' => $localId, 'name' => $name,
    ]);
    $collection = Collection::withoutGlobalScopes()->firstOrCreate(
        ['user_id' => $user->id, 'slug' => $slug],
        ['name' => 'My Collection', 'is_public' => false],
    );
    CollectionItem::create([
        'collection_id' => $collection->id, 'card_id' => $card->id,
        'card_tcgdex_id' => $card->tcgdex_id, 'condition' => 'NM', 'quantity' => 1,
    ]);

    return $card;
}

test('guests are sent to log in', function () {
    $this->get('/admin/sets')->assertRedirect(route('login'));
});

test('lists the sets the owner holds a card from with owned and total counts', function () {
    $user = User::factory()->create();
    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black', 'series' => 'Mega Evolution', 'card_count' => 120]);
    mySetsOwn($user, $set, '116', 'Mega Darkrai ex');
    mySetsOwn($user, $set, '003', 'Fomantis');
    // A second copy of an owned card still counts the card once.
    CollectionItem::create([
        'collection_id' => Collection::withoutGlobalScopes()->where('user_id', $user->id)->value('id'),
        'card_id' => Card::where('tcgdex_id', 'me05-116')->value('id'),
        'card_tcgdex_id' => 'me05-116', 'condition' => 'LP', 'quantity' => 1,
    ]);

    $this->actingAs($user)->get('/admin/sets')
        ->assertOk()
        ->assertSee('Pitch Black')
        ->assertSee('2 / 120')
        ->assertSee('/admin/sets/me05', false);
});

test('works for a private collection', function () {
    $user = User::factory()->create();
    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black', 'card_count' => 120]);
    mySetsOwn($user, $set, '116', 'Mega Darkrai ex');
    expect(Collection::withoutGlobalScopes()->where('user_id', $user->id)->value('is_public'))->toBeFalse();

    $this->actingAs($user)->get('/admin/sets')->assertOk()->assertSee('Pitch Black');
});

test('lists only the signed-in owners sets', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $mine = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black', 'card_count' => 120]);
    $theirs = Set::create(['tcgdex_id' => 'sv08', 'name' => 'Surging Sparks', 'card_count' => 191]);
    Set::create(['tcgdex_id' => 'untouched', 'name' => 'Never Added', 'card_count' => 10]);
    mySetsOwn($user, $mine, '116', 'Mega Darkrai ex');
    mySetsOwn($other, $theirs, '001', 'Exeggcute');

    $this->actingAs($user)->get('/admin/sets')
        ->assertSee('Pitch Black')
        ->assertDontSee('Surging Sparks')
        ->assertDontSee('Never Added');
});

test('falls back to the real card count when tcgdex reported none', function () {
    $user = User::factory()->create();
    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);
    mySetsOwn($user, $set, '116', 'Mega Darkrai ex');
    Card::create(['tcgdex_id' => 'me05-003', 'set_id' => $set->id, 'local_id' => '003', 'name' => 'Fomantis']);

    $this->actingAs($user)->get('/admin/sets')->assertSee('1 / 2');
});

test('an owner with no cards sees an empty state that links to adding one', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/admin/sets')
        ->assertOk()
        ->assertSee('No sets yet')
        ->assertSee(route('admin.collection.add'), false);
});

test('shows the newest set first and puts sets with no release date last', function () {
    $user = User::factory()->create();
    $old = Set::create(['tcgdex_id' => 'base1', 'name' => 'Base Set', 'card_count' => 102, 'released_on' => '1999-01-09']);
    $new = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black', 'card_count' => 120, 'released_on' => '2026-09-01']);
    $undated = Set::create(['tcgdex_id' => 'miscp', 'name' => 'Miscellaneous Promos', 'card_count' => 1]);
    mySetsOwn($user, $old, '001', 'Alakazam');
    mySetsOwn($user, $new, '116', 'Mega Darkrai ex');
    mySetsOwn($user, $undated, '001', 'Pikachu');

    $this->actingAs($user)->get('/admin/sets')
        ->assertSeeInOrder(['Pitch Black', 'Base Set', 'Miscellaneous Promos']);
});

test('falls back to the local card count when tcgdex reports zero', function () {
    $user = User::factory()->create();
    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black', 'card_count' => 0]);
    mySetsOwn($user, $set, '116', 'Mega Darkrai ex');
    Card::create(['tcgdex_id' => 'me05-003', 'set_id' => $set->id, 'local_id' => '003', 'name' => 'Fomantis']);

    $this->actingAs($user)->get('/admin/sets')
        ->assertOk()
        ->assertSee('1 / 2')
        ->assertDontSee('1 / 0');
});

test('a signed-in user without use-collection gets a 403 on the set pages', function () {
    $this->seed(PermissionSeeder::class);
    $user = User::factory()->create();
    $user->syncRoles([]);
    $this->actingAs($user);

    $this->get('/admin/sets')->assertForbidden();
    $this->get('/admin/sets/me05')->assertForbidden();
});
