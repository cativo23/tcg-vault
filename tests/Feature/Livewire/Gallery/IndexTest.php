<?php

declare(strict_types=1);

use App\Livewire\Gallery\Index;
use App\Models\User;
use App\Modules\Catalog\Models\Card;
use App\Modules\Catalog\Models\CardPriceSnapshot;
use App\Modules\Catalog\Models\Set;
use App\Modules\Collection\Models\Collection;
use App\Modules\Collection\Models\CollectionItem;
use Livewire\Livewire;

function seedCollection(): array
{
    $user = User::factory()->create(['username' => 'carlos', 'name' => 'Carlos']);
    $collection = Collection::factory()->for($user)->create(['is_public' => true, 'slug' => 'main']);
    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black', 'card_count' => 120]);
    $other = Set::create(['tcgdex_id' => 'fut2020', 'name' => 'Pokémon Futsal 2020', 'card_count' => 5]);

    $darkrai = Card::create(['tcgdex_id' => 'me05-116', 'set_id' => $set->id, 'local_id' => '116', 'name' => 'Mega Darkrai ex', 'rarity' => 'Special illustration rare']);
    $fomantis = Card::create(['tcgdex_id' => 'me05-003', 'set_id' => $set->id, 'local_id' => '003', 'name' => 'Fomantis', 'rarity' => 'Common']);
    $pikachu = Card::create(['tcgdex_id' => 'fut2020-1', 'set_id' => $other->id, 'local_id' => '1', 'name' => 'Pikachu on the Ball', 'rarity' => 'Promo']);

    CardPriceSnapshot::create(['card_id' => $darkrai->id, 'source' => 'tcgplayer', 'variant' => 'holofoil', 'captured_on' => today(), 'currency' => 'USD', 'market_minor' => 19468]);
    CardPriceSnapshot::create(['card_id' => $pikachu->id, 'source' => 'cardmarket', 'variant' => 'default', 'captured_on' => today(), 'currency' => 'EUR', 'market_minor' => 16665]);

    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $darkrai->id, 'card_tcgdex_id' => 'me05-116', 'condition' => 'NM', 'quantity' => 2]);
    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $pikachu->id, 'card_tcgdex_id' => 'fut2020-1', 'condition' => 'NM', 'quantity' => 1]);
    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $pikachu->id, 'card_tcgdex_id' => 'fut2020-1', 'condition' => 'LP', 'quantity' => 1]);

    return compact('user', 'collection', 'set', 'darkrai', 'fomantis', 'pikachu');
}

test('the collection home shows every owned card once, with the collector as the masthead', function () {
    seedCollection();

    $response = $this->get('/carlos/gallery');

    $response->assertOk();
    $response->assertSee('Carlos');
    $response->assertSee('Mega Darkrai ex');
    $response->assertSee('Pikachu on the Ball');
    $response->assertDontSee('Fomantis'); // in a touched set, but not owned — the home is the collection, not the catalog
    $response->assertSeeInOrder(['Showing', '2', 'of', '2']);
});

test('the collection value never adds two currencies together', function () {
    seedCollection();

    $response = $this->get('/carlos/gallery');

    // 2 × $194.68 in USD leads; the EUR-priced copies are a footnote, not part of the sum.
    $response->assertSee('$389.36');
    $response->assertSee('€333.30');
    $response->assertDontSee('722.66');
});

test('multiple copies of one card collapse to one tile with a quantity', function () {
    seedCollection();

    $response = $this->get('/carlos/gallery');

    $response->assertSee('×2');
    $response->assertSee('4 copies');
});

test('every tile links to the card detail page', function () {
    seedCollection();

    $response = $this->get('/carlos/gallery');

    $response->assertSee('/carlos/gallery/me05/116', false);
    $response->assertSee('/carlos/gallery/fut2020/1', false);
});

test('search, set and rarity filters narrow the grid', function () {
    seedCollection();

    Livewire::test(Index::class, ['username' => 'carlos'])
        ->set('search', 'darkrai')
        ->assertSee('Mega Darkrai ex')
        ->assertDontSee('Pikachu on the Ball')
        ->set('search', '')
        ->set('setFilter', 'fut2020')
        ->assertSee('Pikachu on the Ball')
        ->assertDontSee('Mega Darkrai ex')
        ->set('setFilter', '')
        ->set('rarityFilter', 'Promo')
        ->assertSee('Pikachu on the Ball')
        ->assertDontSee('Mega Darkrai ex')
        ->call('clearFilters')
        ->assertSee('Mega Darkrai ex');
});

test('an unknown sort is ignored rather than trusted', function () {
    seedCollection();

    Livewire::test(Index::class, ['username' => 'carlos'])
        ->call('sortBy', 'drop table')
        ->assertSet('sort', 'value')
        ->call('sortBy', 'name')
        ->assertSet('sort', 'name');
});

test('the page carries its own title and Open Graph image', function () {
    seedCollection();

    $response = $this->get('/carlos/gallery');

    $response->assertSee('<title>', false);
    $response->assertSee("Carlos's collection · tcg-vault"); // escaped like the view does
    $response->assertSee('property="og:title"', false);
});

test('a private collection shows the empty state, never its cards', function () {
    $user = User::factory()->create(['username' => 'carlos']);
    $collection = Collection::factory()->for($user)->create(['is_public' => false, 'slug' => 'private']);
    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);
    $card = Card::create(['tcgdex_id' => 'me05-116', 'set_id' => $set->id, 'local_id' => '116', 'name' => 'Mega Darkrai ex']);
    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => 'me05-116', 'condition' => 'NM', 'quantity' => 1]);

    $response = $this->get('/carlos/gallery');

    $response->assertOk();
    $response->assertDontSee('Mega Darkrai ex');
    $response->assertSee('Nothing on display yet');
});

test('a nonexistent username 404s', function () {
    $this->get('/nobody-here/gallery')->assertNotFound();
});

test('the collection home requires no authentication', function () {
    seedCollection();

    $this->get('/carlos/gallery')->assertOk();
});
