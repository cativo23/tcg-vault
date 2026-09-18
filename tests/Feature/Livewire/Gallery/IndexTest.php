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

    $response = $this->get('/carlos');

    $response->assertOk();
    $response->assertSee('Carlos');
    $response->assertSee('Mega Darkrai ex');
    $response->assertSee('Pikachu on the Ball');
    $response->assertDontSee('Fomantis'); // in a touched set, but not owned — the home is the collection, not the catalog
    $response->assertSeeInOrder(['Showing', '2', 'of', '2']);
});

test('the search box shows a loading indicator while a debounced search is in flight', function () {
    seedCollection();

    $response = $this->get('/carlos');

    $response->assertOk();
    // The debounce window (~300ms) plus a real request round-trip is a
    // visible pause with nothing else on screen to say a search is
    // happening — assert the wire:loading trigger is wired to the same
    // property the search input debounces on.
    $response->assertSee('wire:loading', escape: false);
    $response->assertSee('wire:target="search"', escape: false);
});

test('the collection value never adds two currencies together', function () {
    seedCollection();

    $response = $this->get('/carlos');

    // 2 × $194.68 in USD leads; the EUR-priced copies are a footnote, not part of the sum.
    $response->assertSee('$389.36');
    $response->assertSee('€333.30');
    $response->assertDontSee('722.66');
});

test('multiple copies of one card collapse to one tile with a quantity', function () {
    seedCollection();

    $response = $this->get('/carlos');

    $response->assertSee('×2');
    $response->assertSee('4 copies');
});

test('every tile links to the card detail page', function () {
    seedCollection();

    $response = $this->get('/carlos');

    $response->assertSee('/carlos/me05/116', false);
    $response->assertSee('/carlos/fut2020/1', false);
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

test('tcgdex\'s literal "None" rarity never becomes a selectable filter option', function () {
    // Regression: tcgdex emits the literal string "None" (not null) for
    // promos/unrated prints. A plain ->filter() (no callback) only
    // strips null/'', so "None" survived into the rarity dropdown as a
    // real-looking-but-blank-labeled option (Rarity::label('None') is
    // already '', which is what made the option render with no visible
    // text) — and selecting it matched only cards literally rated
    // "None", hiding every properly-rated card from the grid.
    $user = User::factory()->create(['username' => 'carlos', 'name' => 'Carlos']);
    $collection = Collection::factory()->for($user)->create(['is_public' => true, 'slug' => 'main']);
    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);
    $darkrai = Card::create(['tcgdex_id' => 'me05-116', 'set_id' => $set->id, 'local_id' => '116', 'name' => 'Mega Darkrai ex', 'rarity' => 'Special illustration rare']);
    $fomantis = Card::create(['tcgdex_id' => 'me05-003', 'set_id' => $set->id, 'local_id' => '003', 'name' => 'Fomantis', 'rarity' => 'Common']);
    $pikachu = Card::create(['tcgdex_id' => 'me05-200', 'set_id' => $set->id, 'local_id' => '200', 'name' => 'Pikachu on the Ball', 'rarity' => 'None']);
    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $darkrai->id, 'card_tcgdex_id' => 'me05-116', 'condition' => 'NM', 'quantity' => 1]);
    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $fomantis->id, 'card_tcgdex_id' => 'me05-003', 'condition' => 'NM', 'quantity' => 1]);
    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $pikachu->id, 'card_tcgdex_id' => 'me05-200', 'condition' => 'NM', 'quantity' => 1]);

    $response = $this->get('/carlos');

    $response->assertOk();
    // All 3 owned cards show by default — nothing pre-filtered.
    $response->assertSeeInOrder(['Showing', '3', 'of', '3']);
    // The two real rarities are still real, selectable options...
    $response->assertSee('value="Special illustration rare"', false);
    $response->assertSee('value="Common"', false);
    // ...but "None" is never an option, in any casing.
    $response->assertDontSee('value="None"', false);
    $response->assertDontSee('value="none"', false);
});

test('value sort orders by unit price, not total owned value, and uses the priciest owned variant', function () {
    // "value" sort must rank by unit price, not quantity-weighted total —
    // otherwise 3× a cheap normal copy could outrank 1× a pricier card.
    // It must also use the owned copy's actual variant price (here,
    // Inkay's high-value copy is a reverse-holofoil), not the card-level
    // priority chain, which would never even look at that variant.
    $user = User::factory()->create(['username' => 'carlos', 'name' => 'Carlos']);
    $collection = Collection::factory()->for($user)->create(['is_public' => true, 'slug' => 'main']);
    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);

    $inkay = Card::create(['tcgdex_id' => 'me05-051', 'set_id' => $set->id, 'local_id' => '051', 'name' => 'Inkay']);
    $misty = Card::create(['tcgdex_id' => 'me05-080', 'set_id' => $set->id, 'local_id' => '080', 'name' => "Misty's Vitality"]);

    CardPriceSnapshot::create(['card_id' => $inkay->id, 'source' => 'tcgplayer', 'variant' => 'normal', 'captured_on' => today(), 'currency' => 'USD', 'market_minor' => 11]);
    CardPriceSnapshot::create(['card_id' => $inkay->id, 'source' => 'tcgplayer', 'variant' => 'reverse-holofoil', 'captured_on' => today(), 'currency' => 'USD', 'market_minor' => 45]);
    CardPriceSnapshot::create(['card_id' => $misty->id, 'source' => 'tcgplayer', 'variant' => 'normal', 'captured_on' => today(), 'currency' => 'USD', 'market_minor' => 20]);

    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $inkay->id, 'card_tcgdex_id' => 'me05-051', 'variant' => 'normal', 'condition' => 'NM', 'quantity' => 3]);
    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $misty->id, 'card_tcgdex_id' => 'me05-080', 'variant' => 'normal', 'condition' => 'NM', 'quantity' => 1]);

    // Unit price: Inkay's normal is $0.11, Misty's Vitality is $0.20 —
    // Misty's Vitality must lead the default "value" sort even though
    // Inkay's 3 copies add up to more total money.
    Livewire::test(Index::class, ['username' => 'carlos'])
        ->assertSeeInOrder(["Misty's Vitality", 'Inkay']);
});

test('the grid loads 24 cards at a time and load-more reveals the rest', function () {
    // The gallery uses infinite-scroll (load-more), not page-number
    // pagination.
    $user = User::factory()->create(['username' => 'carlos', 'name' => 'Carlos']);
    $collection = Collection::factory()->for($user)->create(['is_public' => true, 'slug' => 'main']);
    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);

    foreach (range(1, 30) as $i) {
        $card = Card::create(['tcgdex_id' => "me05-{$i}", 'set_id' => $set->id, 'local_id' => (string) $i, 'name' => "Card {$i}"]);
        CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => "me05-{$i}", 'condition' => 'NM', 'quantity' => 1]);
    }

    Livewire::test(Index::class, ['username' => 'carlos'])
        ->assertViewHas('entries', fn ($entries) => $entries->count() === 24)
        ->assertViewHas('totalEntries', 30)
        ->assertViewHas('hasMore', true)
        ->call('loadMore')
        ->assertViewHas('entries', fn ($entries) => $entries->count() === 30)
        ->assertViewHas('hasMore', false);
});

test('changing search, a filter, or the sort resets how many cards are loaded', function () {
    $user = User::factory()->create(['username' => 'carlos', 'name' => 'Carlos']);
    $collection = Collection::factory()->for($user)->create(['is_public' => true, 'slug' => 'main']);
    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);

    foreach (range(1, 30) as $i) {
        $card = Card::create(['tcgdex_id' => "me05-{$i}", 'set_id' => $set->id, 'local_id' => (string) $i, 'name' => "Card {$i}"]);
        CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => "me05-{$i}", 'condition' => 'NM', 'quantity' => 1]);
    }

    Livewire::test(Index::class, ['username' => 'carlos'])
        ->call('loadMore')
        ->assertViewHas('entries', fn ($entries) => $entries->count() === 30)
        ->set('search', 'Card 1')
        ->assertViewHas('entries', fn ($entries) => $entries->count() <= 24);
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

    $response = $this->get('/carlos');

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

    $response = $this->get('/carlos');

    $response->assertOk();
    $response->assertDontSee('Mega Darkrai ex');
    $response->assertSee('Nothing on display yet');
});

test('a search matching nothing shows "no cards match" with a way to clear it, not the whole-collection-empty state', function () {
    seedCollection();

    $response = $this->get('/carlos?search=pica');

    $response->assertOk();
    // Before the fix, a filtered zero-result count was indistinguishable
    // from the collection having no public cards at all — this wrongly
    // rendered "Nothing on display yet" and dropped the entire toolbar
    // (search input + Clear button) along with it, leaving no UI way to
    // remove the search term short of editing the URL by hand.
    $response->assertDontSee('Nothing on display yet');
    $response->assertDontSee('This collection has no public cards');
    $response->assertSee('No cards match');
    $response->assertSee('id="gallery-search"', false);
    $response->assertSee('clearFilters', false);
});

test('the owner viewing their own empty collection gets a way to add cards, not just "check back soon"', function () {
    // Real user complaint: a brand-new collector's own gallery page (the
    // one they land on / share) told THEM to "check back soon" as if
    // someone else would populate it, with no link anywhere to the admin
    // screen that actually lets them add a card.
    $user = User::factory()->create(['username' => 'carlos']);
    Collection::factory()->for($user)->create(['is_public' => true, 'slug' => 'main']);

    $response = $this->actingAs($user)->get('/carlos');

    $response->assertOk();
    $response->assertDontSee('Check back soon');
    $response->assertSee(route('admin.collection.add'), false);
});

test('a visitor viewing someone else\'s empty collection still sees the guest message, never an admin link', function () {
    $owner = User::factory()->create(['username' => 'carlos']);
    Collection::factory()->for($owner)->create(['is_public' => true, 'slug' => 'main']);
    $visitor = User::factory()->create(['username' => 'ash']);

    $response = $this->actingAs($visitor)->get('/carlos');

    $response->assertOk();
    $response->assertSee('Check back soon');
    $response->assertDontSee(route('admin.collection.add'), false);
});

test('a logged-in visitor never sees a manage-collection link into someone else\'s account', function () {
    // Bug: the topbar's admin link was gated on @auth alone (any
    // logged-in user) instead of checking it's actually THIS collector's
    // own page — a logged-in visitor browsing another collector's
    // gallery got a link claiming to manage a collection, which actually
    // opened THEIR OWN /admin, not the page they were looking at.
    $owner = User::factory()->create(['username' => 'carlos']);
    Collection::factory()->for($owner)->create(['is_public' => true, 'slug' => 'main']);
    $visitor = User::factory()->create(['username' => 'ash']);

    $response = $this->actingAs($visitor)->get('/carlos');

    $response->assertOk();
    $response->assertDontSee(route('admin.collection.index'), false);
});

test('the owner sees a clearly labeled way to manage their own collection', function () {
    seedCollection();

    $response = $this->actingAs(User::where('username', 'carlos')->first())->get('/carlos');

    $response->assertOk();
    $response->assertSee(route('admin.collection.index'), false);
    $response->assertDontSee('>Admin<', false); // renamed: "Admin" reads as a technical/backend term, not "manage my own cards"
});

test('the desktop nav lives behind sm:block and a hamburger panel exists for narrow viewports', function () {
    // Regression: .nw-nav shrinks under pressure (min-width: 0) but
    // .nw-brand and the button group beside it don't — a long right-side
    // label ("Manage collection") squeezed the 3 nav links to 0 width on
    // real phones instead of just scrolling, and there was no fallback.
    seedCollection();

    $response = $this->get('/carlos');

    $response->assertOk();
    $response->assertSee('class="hidden sm:block"', false);
    $response->assertSee('nw-hamburger', false);
    $response->assertSee('id="mobile-gallery-nav"', false);
    // The desktop nav's 3 links must still exist somewhere for the
    // mobile panel to duplicate — this isn't asserting the panel is
    // non-empty, just that both copies exist in the markup.
    $response->assertSee('aria-label="Gallery"', false);
});

test('a nonexistent username 404s', function () {
    $this->get('/nobody-here')->assertNotFound();
});

test('the collection home requires no authentication', function () {
    seedCollection();

    $this->get('/carlos')->assertOk();
});

test('the old /{username}/gallery URL redirects to the shorter /{username}', function () {
    seedCollection();

    $this->get('/carlos/gallery')->assertRedirect('/carlos');
});
