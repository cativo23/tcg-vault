<?php

declare(strict_types=1);

use App\Livewire\Admin\SetGrid;
use App\Models\User;
use App\Modules\Catalog\Models\Card;
use App\Modules\Catalog\Models\CardPriceSnapshot;
use App\Modules\Catalog\Models\Set;
use App\Modules\Collection\Models\Collection;
use App\Modules\Collection\Models\CollectionItem;
use Database\Seeders\PermissionSeeder;
use Livewire\Livewire;

/** A signed-in collector who may use /admin. */
function setGridUser(): User
{
    test()->seed(PermissionSeeder::class);
    $user = User::factory()->create();
    test()->actingAs($user);

    return $user;
}

/**
 * Pitch Black with three cards numbered 001, 002 and 010. The first prints
 * normal + reverse, the second only holo, the third lists no prints at all.
 *
 * @return array{set: Set, first: Card, second: Card, third: Card}
 */
function setGridFixture(): array
{
    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black', 'series' => 'Mega Evolution', 'card_count' => 3]);

    return [
        'set' => $set,
        'first' => Card::create(['tcgdex_id' => 'me05-001', 'set_id' => $set->id, 'local_id' => '001', 'name' => 'Fomantis', 'rarity' => 'Common', 'variants' => ['normal' => true, 'reverse' => true, 'holo' => false]]),
        'second' => Card::create(['tcgdex_id' => 'me05-002', 'set_id' => $set->id, 'local_id' => '002', 'name' => 'Mega Darkrai ex', 'rarity' => 'Double Rare', 'variants' => ['normal' => false, 'reverse' => false, 'holo' => true]]),
        'third' => Card::create(['tcgdex_id' => 'me05-010', 'set_id' => $set->id, 'local_id' => '010', 'name' => 'Gastly', 'rarity' => null]),
    ];
}

/** @param array<string, mixed> $attributes */
function setGridOwn(User $user, Card $card, array $attributes = []): CollectionItem
{
    $collection = Collection::withoutGlobalScopes()->firstOrCreate(
        ['user_id' => $user->id, 'slug' => 'my-collection'],
        ['name' => 'My Collection', 'is_public' => false],
    );

    return CollectionItem::create([
        'collection_id' => $collection->id,
        'card_id' => $card->id,
        'card_tcgdex_id' => $card->tcgdex_id,
        'condition' => 'NM',
        'quantity' => 1,
        ...$attributes,
    ]);
}

test('guests are sent to log in', function () {
    setGridFixture();

    $this->get('/admin/sets/me05')->assertRedirect(route('login'));
});

test('a set that is not in the catalog is a 404', function () {
    setGridUser();

    $this->get('/admin/sets/nope')->assertNotFound();
});

test('the page renders for a signed-in collector', function () {
    setGridUser();
    setGridFixture();

    $this->get('/admin/sets/me05')->assertOk()->assertSee('Pitch Black');
});

test('every card of the set is listed by default, owned or not', function () {
    $user = setGridUser();
    ['third' => $third] = setGridFixture();
    setGridOwn($user, $third);

    Livewire::test(SetGrid::class, ['setTcgdexId' => 'me05'])
        ->assertSee('Fomantis')
        ->assertSee('Mega Darkrai ex')
        ->assertSee('Gastly');
});

test('cards the owner does not have render as ghosts', function () {
    $user = setGridUser();
    ['third' => $third] = setGridFixture();
    setGridOwn($user, $third);

    $html = Livewire::test(SetGrid::class, ['setTcgdexId' => 'me05'])->html();

    expect(substr_count($html, 'nw-tile ghost'))->toBe(2)
        ->and($html)->toContain('Not owned');
});

test('hiding missing cards leaves only the owned ones, and toggling brings them back', function () {
    $user = setGridUser();
    ['first' => $first] = setGridFixture();
    setGridOwn($user, $first);

    Livewire::test(SetGrid::class, ['setTcgdexId' => 'me05'])
        ->call('toggleMissing')
        ->assertSet('hideMissing', true)
        ->assertSee('Fomantis')
        ->assertDontSee('Gastly')
        ->call('toggleMissing')
        ->assertSee('Gastly');
});

test('search narrows the grid by card name', function () {
    setGridUser();
    setGridFixture();

    Livewire::test(SetGrid::class, ['setTcgdexId' => 'me05'])
        ->set('search', 'dark')
        ->assertSee('Mega Darkrai ex')
        ->assertDontSee('Fomantis');
});

test('the rarity filter narrows the grid to one rarity', function () {
    setGridUser();
    setGridFixture();

    Livewire::test(SetGrid::class, ['setTcgdexId' => 'me05'])
        ->set('rarityFilter', 'Common')
        ->assertSee('Fomantis')
        ->assertDontSee('Mega Darkrai ex');
});

test('another users copies never make a tile look owned', function () {
    setGridUser();
    ['first' => $first] = setGridFixture();
    $other = User::factory()->create();
    setGridOwn($other, $first);

    $html = Livewire::test(SetGrid::class, ['setTcgdexId' => 'me05'])->html();

    expect(substr_count($html, 'nw-tile ghost'))->toBe(3);
});

test('a private collection works', function () {
    $user = setGridUser();
    ['first' => $first] = setGridFixture();
    setGridOwn($user, $first, ['quantity' => 3]);

    expect(Collection::withoutGlobalScopes()->where('user_id', $user->id)->value('is_public'))->toBeFalse();

    $html = Livewire::test(SetGrid::class, ['setTcgdexId' => 'me05'])->html();

    expect(substr_count($html, 'nw-tile ghost'))->toBe(2)
        ->and($html)->toContain('×3');
});

test('the stat band counts distinct owned cards against the set size', function () {
    $user = setGridUser();
    ['first' => $first, 'second' => $second] = setGridFixture();
    setGridOwn($user, $first, ['condition' => 'NM']);
    setGridOwn($user, $first, ['condition' => 'LP']);
    setGridOwn($user, $second);

    $component = Livewire::test(SetGrid::class, ['setTcgdexId' => 'me05']);

    expect($component->viewData('ownedCount'))->toBe(2)
        ->and($component->viewData('totalCount'))->toBe(3);
});

/** @return list<string> card names in render order */
function setGridNames(object $component): array
{
    return $component->viewData('entries')->map(fn (array $entry) => $entry['card']->name)->values()->all();
}

test('the number sort lists the whole set in card order with owned and missing interleaved', function () {
    $user = setGridUser();
    ['third' => $third] = setGridFixture();
    setGridOwn($user, $third);

    $component = Livewire::test(SetGrid::class, ['setTcgdexId' => 'me05']);

    expect(setGridNames($component))->toBe(['Fomantis', 'Mega Darkrai ex', 'Gastly']);
});

test('the name sort interleaves owned and missing cards', function () {
    $user = setGridUser();
    ['third' => $third] = setGridFixture();
    setGridOwn($user, $third);

    $component = Livewire::test(SetGrid::class, ['setTcgdexId' => 'me05'])->call('sortBy', 'name');

    expect(setGridNames($component))->toBe(['Fomantis', 'Gastly', 'Mega Darkrai ex']);
});

test('the rarity sort interleaves owned and missing cards', function () {
    $user = setGridUser();
    ['second' => $second] = setGridFixture();
    setGridOwn($user, $second);

    $component = Livewire::test(SetGrid::class, ['setTcgdexId' => 'me05'])->call('sortBy', 'rarity');

    // Unrated sorts first, then Common, then Double Rare: the one owned card is last, not first.
    expect(setGridNames($component))->toBe(['Gastly', 'Fomantis', 'Mega Darkrai ex']);
});

test('the value sort interleaves owned and missing cards, priciest first', function () {
    $user = setGridUser();
    ['first' => $first, 'second' => $second] = setGridFixture();
    setGridOwn($user, $first);
    CardPriceSnapshot::create(['card_id' => $second->id, 'source' => 'tcgplayer', 'variant' => 'holofoil', 'captured_on' => today(), 'currency' => 'USD', 'market_minor' => 5000]);
    CardPriceSnapshot::create(['card_id' => $first->id, 'source' => 'tcgplayer', 'variant' => 'normal', 'captured_on' => today(), 'currency' => 'USD', 'market_minor' => 1000]);

    $component = Livewire::test(SetGrid::class, ['setTcgdexId' => 'me05'])->call('sortBy', 'value');

    // The priciest card is one the owner is missing; the unpriced card comes last.
    expect(setGridNames($component))->toBe(['Mega Darkrai ex', 'Fomantis', 'Gastly']);
});

test('an unknown sort is ignored', function () {
    setGridUser();
    setGridFixture();

    Livewire::test(SetGrid::class, ['setTcgdexId' => 'me05'])
        ->call('sortBy', 'bogus')
        ->assertSet('sort', 'number');
});
