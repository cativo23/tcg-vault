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
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Renderless;
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

test('a tile offers one chip per variant the card prints, summing every condition and grade', function () {
    $user = setGridUser();
    ['first' => $first] = setGridFixture();
    setGridOwn($user, $first, ['variant' => 'normal', 'condition' => 'NM', 'quantity' => 2]);
    setGridOwn($user, $first, ['variant' => 'normal', 'condition' => 'LP', 'quantity' => 1]);
    setGridOwn($user, $first, ['variant' => 'normal', 'condition' => 'NM', 'grade_company' => 'PSA', 'grade_value' => '9', 'quantity' => 4]);

    $component = Livewire::test(SetGrid::class, ['setTcgdexId' => 'me05']);
    $entry = $component->viewData('entries')->first(fn (array $e) => $e['card']->id === $first->id);

    expect($entry['chips'])->toBe([
        ['variant' => 'normal', 'label' => 'Normal', 'count' => 7],
        ['variant' => 'reverse-holofoil', 'label' => 'Reverse Holofoil', 'count' => 0],
    ]);
    $component
        ->assertSeeHtml('data-chip="'.$first->id.'|normal"')
        ->assertSeeHtml('data-chip="'.$first->id.'|reverse-holofoil"')
        ->assertSee('×7', false);
});

test('an owned variant is a filled chip and a variant with no copies is a dashed one', function () {
    $user = setGridUser();
    ['first' => $first] = setGridFixture();
    setGridOwn($user, $first, ['variant' => 'normal', 'quantity' => 2]);

    $html = Livewire::test(SetGrid::class, ['setTcgdexId' => 'me05'])->html();

    expect($html)->toContain('nw-chip-add owned')
        ->and($html)->toContain('data-chip="'.$first->id.'|normal"')
        ->and($html)->toContain('+ Reverse Holofoil');
});

test('a card with no known variants gets a single plain +1 chip that counts unspecified copies', function () {
    $user = setGridUser();
    ['third' => $third] = setGridFixture();
    setGridOwn($user, $third, ['variant' => null, 'quantity' => 2]);

    $component = Livewire::test(SetGrid::class, ['setTcgdexId' => 'me05']);
    $entry = $component->viewData('entries')->first(fn (array $e) => $e['card']->id === $third->id);

    expect($entry['chips'])->toBe([['variant' => null, 'label' => '', 'count' => 2]]);
    $component->assertSeeHtml('data-chip="'.$third->id.'|"');
});

test('another users copies do not count toward a chip', function () {
    setGridUser();
    ['first' => $first] = setGridFixture();
    $other = User::factory()->create();
    setGridOwn($other, $first, ['variant' => 'normal', 'quantity' => 5]);

    $component = Livewire::test(SetGrid::class, ['setTcgdexId' => 'me05']);
    $entry = $component->viewData('entries')->first(fn (array $e) => $e['card']->id === $first->id);

    expect($entry['chips'][0]['count'])->toBe(0);
});

/** @return array<string, mixed> */
function setGridEntry(object $component, Card $card): array
{
    return $component->viewData('entries')->first(fn (array $e) => $e['card']->id === $card->id);
}

test('an owned custom print the card does not list still gets its own filled chip', function () {
    $user = setGridUser();
    ['first' => $first] = setGridFixture();
    setGridOwn($user, $first, ['variant' => 'holofoil:cosmos+pre-release', 'quantity' => 2]);

    $component = Livewire::test(SetGrid::class, ['setTcgdexId' => 'me05']);

    expect(setGridEntry($component, $first)['chips'])->toBe([
        ['variant' => 'normal', 'label' => 'Normal', 'count' => 0],
        ['variant' => 'reverse-holofoil', 'label' => 'Reverse Holofoil', 'count' => 0],
        ['variant' => 'holofoil:cosmos+pre-release', 'label' => 'Holofoil · Cosmos · Pre Release', 'count' => 2],
    ]);
    $component->assertSeeHtml('data-chip="'.$first->id.'|holofoil:cosmos+pre-release"');
});

test('copies with no variant on a card that has variants show as an unspecified chip', function () {
    $user = setGridUser();
    ['first' => $first] = setGridFixture();
    setGridOwn($user, $first, ['variant' => null, 'quantity' => 3]);

    $component = Livewire::test(SetGrid::class, ['setTcgdexId' => 'me05']);

    expect(setGridEntry($component, $first)['chips'])->toBe([
        ['variant' => 'normal', 'label' => 'Normal', 'count' => 0],
        ['variant' => 'reverse-holofoil', 'label' => 'Reverse Holofoil', 'count' => 0],
        ['variant' => null, 'label' => 'Unspecified', 'count' => 3],
    ]);
    $component->assertSeeHtml('data-chip="'.$first->id.'|"');
});

test('a card with no known variants keeps the plain chip for unspecified copies and adds one per stored variant', function () {
    $user = setGridUser();
    ['third' => $third] = setGridFixture();
    setGridOwn($user, $third, ['variant' => 'normal', 'quantity' => 2]);

    $component = Livewire::test(SetGrid::class, ['setTcgdexId' => 'me05']);

    expect(setGridEntry($component, $third)['chips'])->toBe([
        ['variant' => null, 'label' => '', 'count' => 0],
        ['variant' => 'normal', 'label' => 'Normal', 'count' => 2],
    ]);
});

test('the chip counts always add up to the tile quantity', function () {
    $user = setGridUser();
    ['first' => $first, 'third' => $third] = setGridFixture();
    setGridOwn($user, $first, ['variant' => 'normal', 'quantity' => 1]);
    setGridOwn($user, $first, ['variant' => 'holofoil:cosmos+pre-release', 'quantity' => 2]);
    setGridOwn($user, $first, ['variant' => null, 'quantity' => 4]);
    setGridOwn($user, $third, ['variant' => 'holofoil', 'quantity' => 5]);
    setGridOwn($user, $third, ['variant' => null, 'condition' => 'LP', 'quantity' => 1]);

    $component = Livewire::test(SetGrid::class, ['setTcgdexId' => 'me05']);

    foreach ([$first, $third] as $card) {
        $entry = setGridEntry($component, $card);
        expect(array_sum(array_column($entry['chips'], 'count')))->toBe($entry['quantity']);
    }
    expect(setGridEntry($component, $first)['quantity'])->toBe(7);
});

test('the number sort is natural and case-insensitive across mixed ids', function () {
    setGridUser();
    $set = Set::create(['tcgdex_id' => 'swsh9', 'name' => 'Mixed', 'card_count' => 6]);
    foreach (['TG10', '010', 'TG2', '002', 'TG01', '001'] as $id) {
        Card::create(['tcgdex_id' => 'swsh9-'.$id, 'set_id' => $set->id, 'local_id' => $id, 'name' => 'Card '.$id]);
    }

    $ids = Livewire::test(SetGrid::class, ['setTcgdexId' => 'swsh9'])
        ->viewData('entries')->map(fn (array $e) => $e['card']->local_id)->values()->all();

    expect($ids)->toBe(['001', '002', '010', 'TG01', 'TG2', 'TG10']);
});

test('quickAdd creates a Near Mint copy of a card the owner is missing', function () {
    $user = setGridUser();
    ['first' => $first] = setGridFixture();

    $component = Livewire::test(SetGrid::class, ['setTcgdexId' => 'me05'])
        ->call('quickAdd', $first->id, 'normal');

    $item = CollectionItem::sole();
    expect($item->card_id)->toBe($first->id)
        ->and($item->variant)->toBe('normal')
        ->and($item->condition)->toBe('NM')
        ->and($item->quantity)->toBe(1)
        ->and($item->collection->user_id)->toBe($user->id);

    $component->assertReturned(['ok' => true, 'itemId' => $item->id, 'created' => true, 'variantCount' => 1, 'cardTotal' => 1]);
});

test('quickAdd merges into the matching copy instead of adding a second row', function () {
    $user = setGridUser();
    ['first' => $first] = setGridFixture();
    $existing = setGridOwn($user, $first, ['variant' => 'normal', 'quantity' => 2]);

    $component = Livewire::test(SetGrid::class, ['setTcgdexId' => 'me05'])
        ->call('quickAdd', $first->id, 'normal');

    expect(CollectionItem::count())->toBe(1)
        ->and($existing->fresh()->quantity)->toBe(3);
    $component->assertReturned(['ok' => true, 'itemId' => $existing->id, 'created' => false, 'variantCount' => 3, 'cardTotal' => 3]);
});

test('quickAdd keeps variants apart and reports the total across variants', function () {
    $user = setGridUser();
    ['first' => $first] = setGridFixture();
    setGridOwn($user, $first, ['variant' => 'normal', 'quantity' => 2]);

    $component = Livewire::test(SetGrid::class, ['setTcgdexId' => 'me05'])
        ->call('quickAdd', $first->id, 'reverse-holofoil');

    expect(CollectionItem::count())->toBe(2);
    $component->assertReturned(fn (array $result) => $result['ok'] === true && $result['variantCount'] === 1 && $result['cardTotal'] === 3);
});

test('quickAdd on a card with no known variants adds with an empty variant', function () {
    setGridUser();
    ['third' => $third] = setGridFixture();

    Livewire::test(SetGrid::class, ['setTcgdexId' => 'me05'])->call('quickAdd', $third->id, null);

    expect(CollectionItem::sole()->variant)->toBeNull();
});

test('quickAdd refuses a variant the card does not print', function () {
    setGridUser();
    ['first' => $first, 'third' => $third] = setGridFixture();

    Livewire::test(SetGrid::class, ['setTcgdexId' => 'me05'])
        ->call('quickAdd', $first->id, 'holofoil')
        ->assertReturned(fn (array $result) => $result['ok'] === false);

    Livewire::test(SetGrid::class, ['setTcgdexId' => 'me05'])
        ->call('quickAdd', $third->id, 'normal')
        ->assertReturned(fn (array $result) => $result['ok'] === false);

    expect(CollectionItem::count())->toBe(0);
});

test('quickAdd refuses a card from another set', function () {
    setGridUser();
    setGridFixture();
    $other = Set::create(['tcgdex_id' => 'sv08', 'name' => 'Surging Sparks', 'card_count' => 1]);
    $stranger = Card::create(['tcgdex_id' => 'sv08-001', 'set_id' => $other->id, 'local_id' => '001', 'name' => 'Exeggcute', 'variants' => ['normal' => true]]);

    Livewire::test(SetGrid::class, ['setTcgdexId' => 'me05'])
        ->call('quickAdd', $stranger->id, 'normal')
        ->assertReturned(fn (array $result) => $result['ok'] === false);

    expect(CollectionItem::count())->toBe(0);
});

test('quickAdd never touches another users collection', function () {
    $user = setGridUser();
    ['first' => $first] = setGridFixture();
    $other = User::factory()->create();
    $theirs = setGridOwn($other, $first, ['variant' => 'normal', 'quantity' => 5]);

    Livewire::test(SetGrid::class, ['setTcgdexId' => 'me05'])->call('quickAdd', $first->id, 'normal');

    expect($theirs->fresh()->quantity)->toBe(5)
        ->and(CollectionItem::count())->toBe(2)
        ->and(CollectionItem::where('id', '!=', $theirs->id)->sole()->collection->user_id)->toBe($user->id);
});

test('quickAdd saves nothing past the per-minute limit and says to slow down', function () {
    $user = setGridUser();
    ['first' => $first] = setGridFixture();
    for ($i = 0; $i < SetGrid::QUICK_ADD_LIMIT_PER_MINUTE; $i++) {
        RateLimiter::hit('quick-add:'.$user->id, 60);
    }

    Livewire::test(SetGrid::class, ['setTcgdexId' => 'me05'])
        ->call('quickAdd', $first->id, 'normal')
        ->assertReturned(fn (array $result) => $result['ok'] === false && str_contains($result['message'], 'Slow down'));

    expect(CollectionItem::count())->toBe(0);
});

test('quickAdd accepts a stored custom print the tile shows as a chip', function () {
    $user = setGridUser();
    ['first' => $first] = setGridFixture();
    setGridOwn($user, $first, ['variant' => 'holofoil:cosmos', 'quantity' => 1]);

    Livewire::test(SetGrid::class, ['setTcgdexId' => 'me05'])
        ->call('quickAdd', $first->id, 'holofoil:cosmos')
        ->assertReturned(fn (array $result) => $result['ok'] === true && $result['variantCount'] === 2 && $result['cardTotal'] === 2);

    expect(CollectionItem::count())->toBe(1);
});

test('quickAdd refuses a print the card does not offer and the owner does not hold', function () {
    setGridUser();
    ['first' => $first] = setGridFixture();

    Livewire::test(SetGrid::class, ['setTcgdexId' => 'me05'])
        ->call('quickAdd', $first->id, 'holofoil:cosmos')
        ->assertReturned(fn (array $result) => $result['ok'] === false);

    Livewire::test(SetGrid::class, ['setTcgdexId' => 'me05'])
        ->call('quickAdd', $first->id, 'not a variant!')
        ->assertReturned(fn (array $result) => $result['ok'] === false);

    expect(CollectionItem::count())->toBe(0);
});

test('quickAdd on the Unspecified chip merges into the unspecified copies', function () {
    $user = setGridUser();
    ['first' => $first] = setGridFixture();
    $existing = setGridOwn($user, $first, ['variant' => null, 'quantity' => 2]);

    Livewire::test(SetGrid::class, ['setTcgdexId' => 'me05'])
        ->call('quickAdd', $first->id, '')
        ->assertReturned(['ok' => true, 'itemId' => $existing->id, 'created' => false, 'variantCount' => 3, 'cardTotal' => 3]);

    expect(CollectionItem::count())->toBe(1);
});

test('quickAdd refuses no variant on a card with prints when the owner holds no unspecified copy', function () {
    setGridUser();
    ['first' => $first] = setGridFixture();

    Livewire::test(SetGrid::class, ['setTcgdexId' => 'me05'])
        ->call('quickAdd', $first->id, null)
        ->assertReturned(fn (array $result) => $result['ok'] === false);

    expect(CollectionItem::count())->toBe(0);
});

test('undo takes back the copy a tap added to a row that already had more', function () {
    $user = setGridUser();
    ['first' => $first] = setGridFixture();
    $item = setGridOwn($user, $first, ['variant' => 'normal', 'quantity' => 2]);

    Livewire::test(SetGrid::class, ['setTcgdexId' => 'me05'])
        ->call('quickAdd', $first->id, 'normal')
        ->call('undoQuickAdd', $item->id)
        ->assertReturned(['ok' => true, 'variantCount' => 2, 'cardTotal' => 2]);

    expect($item->fresh()->quantity)->toBe(2);
});

test('undo refuses a copy no tap added, so a forged call changes nothing', function () {
    $user = setGridUser();
    ['first' => $first] = setGridFixture();
    $item = setGridOwn($user, $first, ['variant' => 'normal', 'quantity' => 2]);

    Livewire::test(SetGrid::class, ['setTcgdexId' => 'me05'])
        ->call('undoQuickAdd', $item->id)
        ->assertReturned(fn (array $result) => $result['ok'] === false);

    expect($item->fresh()->quantity)->toBe(2);
});

test('a second undo of one tap is refused and keeps the copy that was already there', function () {
    $user = setGridUser();
    ['first' => $first] = setGridFixture();
    $item = setGridOwn($user, $first, ['variant' => 'normal', 'quantity' => 2]);

    Livewire::test(SetGrid::class, ['setTcgdexId' => 'me05'])
        ->call('quickAdd', $first->id, 'normal')
        ->call('undoQuickAdd', $item->id)
        ->call('undoQuickAdd', $item->id)
        ->assertReturned(fn (array $result) => $result['ok'] === false);

    expect($item->fresh()->quantity)->toBe(2);
});

test('undo deletes a row the quick add created once it reaches zero', function () {
    setGridUser();
    ['first' => $first] = setGridFixture();

    $component = Livewire::test(SetGrid::class, ['setTcgdexId' => 'me05'])
        ->call('quickAdd', $first->id, 'normal');
    $itemId = CollectionItem::sole()->id;

    $component
        ->call('undoQuickAdd', $itemId)
        ->assertReturned(['ok' => true, 'variantCount' => 0, 'cardTotal' => 0]);

    expect(CollectionItem::count())->toBe(0);
});

test('undoing a create and a merge in tap order leaves nothing behind', function () {
    setGridUser();
    ['first' => $first] = setGridFixture();

    $component = Livewire::test(SetGrid::class, ['setTcgdexId' => 'me05'])
        ->call('quickAdd', $first->id, 'normal')
        ->call('quickAdd', $first->id, 'normal');
    $itemId = CollectionItem::sole()->id;

    $component->call('undoQuickAdd', $itemId)->assertReturned(['ok' => true, 'variantCount' => 1, 'cardTotal' => 1]);
    $component->call('undoQuickAdd', $itemId)->assertReturned(['ok' => true, 'variantCount' => 0, 'cardTotal' => 0]);

    expect(CollectionItem::count())->toBe(0);
});

test('undo refuses a copy that has a photo and says to use the panel', function () {
    setGridUser();
    ['first' => $first] = setGridFixture();

    $component = Livewire::test(SetGrid::class, ['setTcgdexId' => 'me05'])
        ->call('quickAdd', $first->id, 'normal');
    $item = CollectionItem::sole();
    $item->update(['photo_path' => 'mine.jpg']);

    $component
        ->call('undoQuickAdd', $item->id)
        ->assertReturned(fn (array $result) => $result['ok'] === false && str_contains($result['message'], 'panel'));

    expect($item->fresh()->quantity)->toBe(1);
});

test('undo refuses a copy that has notes', function () {
    setGridUser();
    ['first' => $first] = setGridFixture();

    $component = Livewire::test(SetGrid::class, ['setTcgdexId' => 'me05'])
        ->call('quickAdd', $first->id, 'normal');
    $item = CollectionItem::sole();
    $item->update(['notes' => 'Bought at a con']);

    $component
        ->call('undoQuickAdd', $item->id)
        ->assertReturned(fn (array $result) => $result['ok'] === false);

    expect(CollectionItem::count())->toBe(1);
});

test('undo refuses a copy whose condition was changed in the panel after the tap', function () {
    setGridUser();
    ['first' => $first] = setGridFixture();

    $component = Livewire::test(SetGrid::class, ['setTcgdexId' => 'me05'])
        ->call('quickAdd', $first->id, 'normal');
    $item = CollectionItem::sole();
    $item->update(['condition' => 'LP']);

    $component
        ->call('undoQuickAdd', $item->id)
        ->assertReturned(fn (array $result) => $result['ok'] === false);

    expect(CollectionItem::count())->toBe(1);
});

test('undo refuses once the toast window has passed', function () {
    setGridUser();
    ['first' => $first] = setGridFixture();

    $component = Livewire::test(SetGrid::class, ['setTcgdexId' => 'me05'])
        ->call('quickAdd', $first->id, 'normal');
    $itemId = CollectionItem::sole()->id;

    $this->travel(SetGrid::UNDO_WINDOW_SECONDS + 1)->seconds();

    $component
        ->call('undoQuickAdd', $itemId)
        ->assertReturned(fn (array $result) => $result['ok'] === false);

    expect(CollectionItem::count())->toBe(1);
});

test('undo cannot touch another users copy', function () {
    setGridUser();
    ['first' => $first] = setGridFixture();
    $other = User::factory()->create();
    $theirs = setGridOwn($other, $first, ['variant' => 'normal', 'quantity' => 2]);

    Livewire::test(SetGrid::class, ['setTcgdexId' => 'me05'])
        ->call('undoQuickAdd', $theirs->id)
        ->assertReturned(fn (array $result) => $result['ok'] === false);

    expect($theirs->fresh()->quantity)->toBe(2);
});

test('undo cannot touch a copy of a card from another set', function () {
    $user = setGridUser();
    setGridFixture();
    $other = Set::create(['tcgdex_id' => 'sv08', 'name' => 'Surging Sparks', 'card_count' => 1]);
    $stranger = Card::create(['tcgdex_id' => 'sv08-001', 'set_id' => $other->id, 'local_id' => '001', 'name' => 'Exeggcute']);
    $item = setGridOwn($user, $stranger, ['quantity' => 2]);

    Livewire::test(SetGrid::class, ['setTcgdexId' => 'me05'])
        ->call('undoQuickAdd', $item->id)
        ->assertReturned(fn (array $result) => $result['ok'] === false);

    expect($item->fresh()->quantity)->toBe(2);
});

test('undo saves nothing past its own per-minute limit', function () {
    $user = setGridUser();
    ['first' => $first] = setGridFixture();
    $component = Livewire::test(SetGrid::class, ['setTcgdexId' => 'me05'])
        ->call('quickAdd', $first->id, 'normal');
    $itemId = CollectionItem::sole()->id;

    for ($i = 0; $i < SetGrid::QUICK_ADD_LIMIT_PER_MINUTE; $i++) {
        RateLimiter::hit('quick-undo:'.$user->id, 60);
    }

    $component
        ->call('undoQuickAdd', $itemId)
        ->assertReturned(fn (array $result) => $result['ok'] === false && str_contains($result['message'], 'Slow down'));

    expect(CollectionItem::sole()->quantity)->toBe(1);
});

test('one successful quickAdd counts once against the limiter', function () {
    $user = setGridUser();
    ['first' => $first] = setGridFixture();

    Livewire::test(SetGrid::class, ['setTcgdexId' => 'me05'])->call('quickAdd', $first->id, 'normal');

    expect(RateLimiter::attempts('quick-add:'.$user->id))->toBe(1);
});

test('quickAdd accepts a held key even when it is not a well-formed variant', function () {
    $user = setGridUser();
    ['first' => $first] = setGridFixture();
    setGridOwn($user, $first, ['variant' => 'legacy print', 'quantity' => 1]);

    Livewire::test(SetGrid::class, ['setTcgdexId' => 'me05'])
        ->call('quickAdd', $first->id, 'legacy print')
        ->assertReturned(fn (array $result) => $result['ok'] === true && $result['variantCount'] === 2);
});

test('undo refuses a copy that was graded in the panel after the tap', function () {
    setGridUser();
    ['first' => $first] = setGridFixture();

    $component = Livewire::test(SetGrid::class, ['setTcgdexId' => 'me05'])
        ->call('quickAdd', $first->id, 'normal');
    $item = CollectionItem::sole();
    $item->update(['grade_company' => 'PSA', 'grade_value' => '9']);

    $component
        ->call('undoQuickAdd', $item->id)
        ->assertReturned(fn (array $result) => $result['ok'] === false);

    expect(CollectionItem::count())->toBe(1);
});

test('a created row stays undoable for as long as its latest tap, however old the first was', function () {
    setGridUser();
    ['first' => $first] = setGridFixture();

    $component = Livewire::test(SetGrid::class, ['setTcgdexId' => 'me05'])
        ->call('quickAdd', $first->id, 'normal');
    $this->travel(SetGrid::UNDO_WINDOW_SECONDS - 5)->seconds();
    $component->call('quickAdd', $first->id, 'normal');
    $itemId = CollectionItem::sole()->id;
    $this->travel(6)->seconds();

    $component->call('undoQuickAdd', $itemId)->assertReturned(['ok' => true, 'variantCount' => 1, 'cardTotal' => 1]);
    $component->call('undoQuickAdd', $itemId)->assertReturned(['ok' => true, 'variantCount' => 0, 'cardTotal' => 0]);

    expect(CollectionItem::count())->toBe(0);
});

test('quickAdd saves nothing and says to retry while the card is locked by another tap', function () {
    $user = setGridUser();
    ['first' => $first] = setGridFixture();
    $lock = Cache::lock('quick-add:lock:'.$user->id.':'.$first->id, 10);
    expect($lock->get())->toBeTrue();
    SetGrid::$lockWaitSeconds = 0;

    Livewire::test(SetGrid::class, ['setTcgdexId' => 'me05'])
        ->call('quickAdd', $first->id, 'normal')
        ->assertReturned(fn (array $result) => $result['ok'] === false && str_contains($result['message'], 'try again'));

    $lock->release();
    SetGrid::$lockWaitSeconds = 2;
    expect(CollectionItem::count())->toBe(0)
        ->and(RateLimiter::attempts('quick-add:'.$user->id))->toBe(0)
        ->and(Collection::withoutGlobalScopes()->where('user_id', $user->id)->count())->toBe(0);
});

test('undo changes nothing and says to retry while the card is locked by another tap', function () {
    $user = setGridUser();
    ['first' => $first] = setGridFixture();
    $component = Livewire::test(SetGrid::class, ['setTcgdexId' => 'me05'])
        ->call('quickAdd', $first->id, 'normal');
    $itemId = CollectionItem::sole()->id;
    $lock = Cache::lock('quick-add:lock:'.$user->id.':'.$first->id, 10);
    expect($lock->get())->toBeTrue();
    SetGrid::$lockWaitSeconds = 0;

    $component
        ->call('undoQuickAdd', $itemId)
        ->assertReturned(fn (array $result) => $result['ok'] === false && str_contains($result['message'], 'try again'));

    $lock->release();
    SetGrid::$lockWaitSeconds = 2;
    expect(CollectionItem::count())->toBe(1)
        ->and(RateLimiter::attempts('quick-undo:'.$user->id))->toBe(0);
});

test('quickAdd and undoQuickAdd are renderless, so a tap does not re-render the grid', function () {
    foreach (['quickAdd', 'undoQuickAdd'] as $method) {
        expect((new ReflectionMethod(SetGrid::class, $method))->getAttributes(Renderless::class))->toHaveCount(1);
    }
});
