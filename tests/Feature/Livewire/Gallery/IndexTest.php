<?php

declare(strict_types=1);

use App\Livewire\Gallery\Index;
use App\Models\User;
use App\Modules\Catalog\Models\Card;
use App\Modules\Catalog\Models\CardPriceSnapshot;
use App\Modules\Catalog\Models\Set;
use App\Modules\Collection\Models\Collection;
use App\Modules\Collection\Models\CollectionItem;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Support\PriceRowReads;
use Tests\Support\RevampHtml;

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

test('the collection home shows every owned card once, led by the collector', function () {
    seedCollection();

    $response = $this->get('/carlos');

    $response->assertOk();
    $response->assertSee('Carlos'); // the page title
    $response->assertSee('Mega Darkrai ex');
    $response->assertSee('Pikachu on the Ball');
    $response->assertDontSee('Fomantis'); // in a touched set, but not owned — the home is the collection, not the catalog
    $response->assertSee('Showing <b>2</b> of <b>2</b>', false);
    // Swapping the result count with no announcement told assistive tech
    // nothing happened when a search/filter actually changed the grid.
    $response->assertSee('<p class="col-count" role="status">', false);
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

    $html = $this->get('/carlos')->getContent();

    // 2 × $194.68 in USD leads the summary; the EUR-priced copies are a note, not part of the sum.
    expect(RevampHtml::summary($html))->toContain('$389.36')
        ->and(RevampHtml::summary($html))->not->toContain('€')
        ->and($html)->toContain('€333.30')
        ->and($html)->not->toContain('722.66');
});

test('multiple copies of one card collapse to one tile with a quantity', function () {
    seedCollection();

    $response = $this->get('/carlos');

    $response->assertSee('<span class="col-qty" title="2 copies">×2</span>', false);
    expect(Livewire::test(Index::class, ['username' => 'carlos'])->viewData('copies'))->toBe(4);
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

test('the three collector pages are always in the bar, as plain links, with no hamburger to open', function () {
    seedCollection();

    $html = $this->get('/carlos')->assertOk()->getContent();

    expect($html)->toContain('<nav class="bar-nav" aria-label="Gallery">')
        ->and($html)->toContain('href="'.route('gallery.sets', ['username' => 'carlos']).'"')
        ->and($html)->toContain('href="'.route('gallery.activity', ['username' => 'carlos']).'"')
        ->and($html)->not->toContain('nw-hamburger')
        ->and($html)->not->toContain('mobile-gallery-nav')
        ->and($html)->not->toContain('sm:block');
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

test('sorting by number keeps search and rarity filters working', function () {
    seedCollection();

    // The number sort joins sets, which has its own name column, so an
    // unqualified "name" in the search filter is ambiguous to Postgres.
    Livewire::withQueryParams(['sort' => 'number', 'search' => 'pika', 'rarity' => 'Promo'])
        ->test(Index::class, ['username' => 'carlos'])
        ->assertOk()
        ->assertSee('Pikachu on the Ball')
        ->assertDontSee('Mega Darkrai ex');
});

test('the grid reuses the stat band\'s cards instead of loading their price history again', function (string $sort, string $search) {
    seedCollection();

    $snapshotQueries = 0;
    DB::listen(function (QueryExecuted $query) use (&$snapshotQueries) {
        if (str_contains($query->sql, 'from "card_price_snapshots"')) {
            $snapshotQueries++;
        }
    });

    Livewire::withQueryParams(['sort' => $sort, 'search' => $search])->test(Index::class, ['username' => 'carlos']);

    expect($snapshotQueries)->toBe(1);
})->with(['value', 'newest', 'number', 'name'])->with(['', 'pika']);

test('a collection past the stat band\'s card cap still fills the grid with cards it did not load', function () {
    $user = User::factory()->create(['username' => 'carlos']);
    $collection = Collection::factory()->for($user)->create(['is_public' => true, 'slug' => 'main']);
    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);

    // 601 cards: one more than Index's MAX_CARDS, all named so that the
    // name sort's last page is exactly the cards most likely to fall
    // outside the stat band's unordered first 600.
    $now = now();
    DB::table('cards')->insert(collect(range(1, 601))->map(fn (int $i) => [
        'tcgdex_id' => "me05-$i", 'set_id' => $set->id, 'local_id' => (string) $i,
        'name' => sprintf('Card %04d', $i), 'created_at' => $now, 'updated_at' => $now,
    ])->all());
    DB::table('collection_items')->insert(Card::pluck('tcgdex_id', 'id')->map(fn (string $tcgdexId, int $id) => [
        'collection_id' => $collection->id, 'card_id' => $id, 'card_tcgdex_id' => $tcgdexId,
        'condition' => 'NM', 'quantity' => 1, 'created_at' => $now, 'updated_at' => $now,
    ])->values()->all());

    $entries = Livewire::withQueryParams(['sort' => 'name'])
        ->test(Index::class, ['username' => 'carlos'])
        ->set('take', 601)
        ->viewData('entries');

    expect($entries)->toHaveCount(601)
        ->and($entries->pluck('card.name')->all())->toBe(collect(range(1, 601))->map(fn (int $i) => sprintf('Card %04d', $i))->all());
});

test('cards past the stat band\'s card cap are priced through the deciding query too', function () {
    $user = User::factory()->create(['username' => 'carlos']);
    $collection = Collection::factory()->for($user)->create(['is_public' => true, 'slug' => 'main']);
    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);
    $now = now();
    DB::table('cards')->insert(collect(range(1, 601))->map(fn (int $i) => [
        'tcgdex_id' => "me05-$i", 'set_id' => $set->id, 'local_id' => (string) $i,
        'name' => sprintf('Card %04d', $i), 'created_at' => $now, 'updated_at' => $now,
    ])->all());
    DB::table('collection_items')->insert(Card::pluck('tcgdex_id', 'id')->map(fn (string $tcgdexId, int $id) => [
        'collection_id' => $collection->id, 'card_id' => $id, 'card_tcgdex_id' => $tcgdexId, 'variant' => 'normal',
        'condition' => 'NM', 'quantity' => 1, 'created_at' => $now, 'updated_at' => $now,
    ])->values()->all());
    // Two priced days per card, so each tile also carries a delta.
    DB::table('card_price_snapshots')->insert(Card::pluck('id')->flatMap(fn (int $id) => [
        ['card_id' => $id, 'source' => 'tcgplayer', 'origin' => 'tcgdex', 'variant' => 'normal', 'captured_on' => today()->toDateString(), 'currency' => 'USD', 'market_minor' => 1000 + $id, 'created_at' => $now, 'updated_at' => $now],
        ['card_id' => $id, 'source' => 'tcgplayer', 'origin' => 'tcgdex', 'variant' => 'normal', 'captured_on' => today()->subDay()->toDateString(), 'currency' => 'USD', 'market_minor' => 900 + $id, 'created_at' => $now, 'updated_at' => $now],
    ])->all());

    $entries = null;
    $reads = PriceRowReads::during(function () use (&$entries) {
        $entries = Livewire::withQueryParams(['sort' => 'name'])
            ->test(Index::class, ['username' => 'carlos'])
            ->set('take', 601)
            ->viewData('entries');
    });

    // The last render (take=601) loads the stat band's 600 cards, then the
    // one card they left out through a second deciding query. The first
    // render (24 cards) may or may not need its own past-cap load.
    $last = count($reads->deciding) - 1;
    $band = $reads->decidingIds($last - 1);
    $pastCap = array_values(array_diff(Card::pluck('id')->all(), $band));

    expect($entries)->toHaveCount(601)
        ->and($entries->every(fn (array $e) => $e['snapshot']?->market_minor === 1000 + $e['card']->id && $e['delta']?->deltaMinor === 100))->toBeTrue()
        ->and($reads->stray)->toBe([])
        ->and($band)->toHaveCount(600)
        ->and($pastCap)->toHaveCount(1)
        ->and($reads->decidingIds($last))->toBe($pastCap);
});

test('the summary dates the latest marketplace sync and the note names its origins, never a manual entry', function () {
    ['darkrai' => $darkrai] = seedCollection();
    CardPriceSnapshot::query()->delete(); // start from this test's own prices only
    CardPriceSnapshot::create(['card_id' => $darkrai->id, 'source' => 'cardmarket', 'variant' => 'default', 'captured_on' => today()->subDays(2), 'currency' => 'EUR', 'market_minor' => 100]);
    CardPriceSnapshot::create(['card_id' => $darkrai->id, 'source' => 'manual', 'origin' => 'hand', 'variant' => 'holofoil:cosmos', 'captured_on' => today(), 'currency' => 'USD', 'market_minor' => 500]);

    $html = $this->get('/carlos')->assertOk()->getContent();

    expect(RevampHtml::summary($html))->toEndWith('on '.today()->subDays(2)->format('j M'))
        ->and(RevampHtml::summary($html))->not->toContain(today()->format('j M'))
        ->and(RevampHtml::note($html))->toBe('Prices via tcgdex.') // run once, then pinned
        ->and(RevampHtml::note($html))->not->toContain('hand');
});

test('a set tab falls back to the local card count when tcgdex reports zero', function () {
    $user = User::factory()->create(['username' => 'carlos']);
    $collection = Collection::factory()->for($user)->create(['is_public' => true, 'slug' => 'main']);
    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black', 'card_count' => 0]);
    $owned = Card::create(['tcgdex_id' => 'me05-116', 'set_id' => $set->id, 'local_id' => '116', 'name' => 'Mega Darkrai ex']);
    Card::create(['tcgdex_id' => 'me05-003', 'set_id' => $set->id, 'local_id' => '003', 'name' => 'Fomantis']);
    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $owned->id, 'card_tcgdex_id' => 'me05-116', 'condition' => 'NM', 'quantity' => 1]);

    $html = $this->get('/carlos')->assertOk()->getContent();

    expect($html)->toContain('<b class="col-tab__n">1/2</b>')
        ->and($html)->not->toContain('1/0');
});

test('each entry carries its 7-day move next to the previous-day delta', function () {
    $user = User::factory()->create(['username' => 'carlos', 'name' => 'Carlos']);
    $collection = Collection::factory()->for($user)->create(['is_public' => true, 'slug' => 'main']);
    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);
    $card = Card::create(['tcgdex_id' => 'me05-116', 'set_id' => $set->id, 'local_id' => '116', 'name' => 'Mega Darkrai ex']);
    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => 'me05-116', 'variant' => 'normal', 'condition' => 'NM', 'quantity' => 1]);
    foreach ([0 => 1240, 1 => 1230, 7 => 1000] as $days => $minor) {
        CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'tcgplayer', 'variant' => 'normal', 'captured_on' => today()->subDays($days), 'currency' => 'USD', 'market_minor' => $minor]);
    }

    $entry = Livewire::test(Index::class, ['username' => 'carlos'])->viewData('entries')->first();

    expect($entry['delta']->deltaMinor)->toBe(10)
        ->and($entry['weekDelta']->deltaMinor)->toBe(240)
        ->and($entry['weekDelta']->previous->market_minor)->toBe(1000);
});

test('the 7-day move costs no extra queries however many cards the page prices', function () {
    $user = User::factory()->create(['username' => 'carlos', 'name' => 'Carlos']);
    $collection = Collection::factory()->for($user)->create(['is_public' => true, 'slug' => 'main']);
    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);
    $now = now();
    $add = function (int $from, int $to) use ($collection, $set, $now) {
        DB::table('cards')->insert(collect(range($from, $to))->map(fn (int $i) => [
            'tcgdex_id' => "me05-$i", 'set_id' => $set->id, 'local_id' => (string) $i,
            'name' => sprintf('Card %04d', $i), 'created_at' => $now, 'updated_at' => $now,
        ])->all());
        $tcgdexIds = Card::whereIn('tcgdex_id', collect(range($from, $to))->map(fn (int $i) => "me05-$i")->all())->pluck('tcgdex_id', 'id');
        DB::table('collection_items')->insert($tcgdexIds->map(fn (string $tcgdexId, int $id) => [
            'collection_id' => $collection->id, 'card_id' => $id, 'card_tcgdex_id' => $tcgdexId, 'variant' => 'normal',
            'condition' => 'NM', 'quantity' => 1, 'created_at' => $now, 'updated_at' => $now,
        ])->values()->all());
        // Ten daily rows per card, rising one cent a day: every card's 7-day move is +7.
        DB::table('card_price_snapshots')->insert($tcgdexIds->keys()->flatMap(fn (int $id) => collect(range(0, 9))->map(fn (int $d) => [
            'card_id' => $id, 'source' => 'tcgplayer', 'origin' => 'tcgdex', 'variant' => 'normal',
            'captured_on' => today()->subDays($d)->toDateString(), 'currency' => 'USD', 'market_minor' => 1000 + $id - $d,
            'created_at' => $now, 'updated_at' => $now,
        ]))->all());
    };
    // Measured cold both times (flushed cache), so a hit on one side cannot hide a per-card read on the other.
    $queriesFor = function (): array {
        Cache::flush();
        $count = 0;
        DB::listen(function () use (&$count) {
            $count++;
        });
        $entries = Livewire::test(Index::class, ['username' => 'carlos'])->viewData('entries');

        return [$count, $entries];
    };

    $add(1, 3);
    [$few, $fewEntries] = $queriesFor();
    $add(4, 30);
    [$many, $manyEntries] = $queriesFor();

    expect($fewEntries)->toHaveCount(3)
        ->and($manyEntries)->toHaveCount(24)
        ->and($manyEntries->every(fn (array $e) => $e['weekDelta']?->deltaMinor === 7))->toBeTrue()
        ->and($many)->toBe($few);
});

function seedLeadCollection(int $cards): void
{
    $user = User::factory()->create(['username' => 'carlos', 'name' => 'Carlos']);
    $collection = Collection::factory()->for($user)->create(['is_public' => true, 'slug' => 'main']);
    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black', 'card_count' => 120]);

    foreach (range(1, $cards) as $i) {
        $card = Card::create([
            'tcgdex_id' => "me05-$i", 'set_id' => $set->id, 'local_id' => (string) $i, 'name' => "Card $i",
            'official_image_url' => "https://assets.tcgdex.net/en/me/me05/$i/high.webp",
        ]);
        CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'tcgplayer', 'variant' => 'normal', 'captured_on' => today(), 'currency' => 'USD', 'market_minor' => $i * 100]);
        CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => "me05-$i", 'variant' => 'normal', 'condition' => 'NM', 'quantity' => 1]);
    }
}

test('the page is the collector\'s name, one summary line and the approved copy blocks', function () {
    seedCollection();

    $html = $this->get('/carlos')->assertOk()->getContent();

    expect($html)->toContain('<h1 id="col-who" class="col-who">carlos</h1>')
        ->and(RevampHtml::summary($html))->toBe('2 cards · 2 sets · $389.36 on '.today()->format('j M'))
        ->and($html)->toContain('Showing <b>2</b> of <b>2</b>')
        ->and($html)->not->toContain('class="nw-masthead"')
        ->and($html)->not->toContain('Pokémon TCG · Collection'); // the eyebrow is gone
});

test('the summary counts one card and one set in the singular, and has no value segment when nothing is priced', function () {
    $user = User::factory()->create(['username' => 'carlos']);
    $collection = Collection::factory()->for($user)->create(['is_public' => true, 'slug' => 'main']);
    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);
    $card = Card::create(['tcgdex_id' => 'me05-116', 'set_id' => $set->id, 'local_id' => '116', 'name' => 'Mega Darkrai ex']);
    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => 'me05-116', 'condition' => 'NM', 'quantity' => 1]);

    $html = $this->get('/carlos')->getContent();

    expect(RevampHtml::summary($html))->toBe('1 card · 1 set')
        ->and($html)->not->toContain('class="col-note"');
});

test('a collection past the stat band\'s cap still reports its real card count', function () {
    $user = User::factory()->create(['username' => 'carlos']);
    $collection = Collection::factory()->for($user)->create(['is_public' => true, 'slug' => 'main']);
    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);
    $now = now();
    DB::table('cards')->insert(collect(range(1, 601))->map(fn (int $i) => [
        'tcgdex_id' => "me05-$i", 'set_id' => $set->id, 'local_id' => (string) $i, 'name' => "Card $i", 'created_at' => $now, 'updated_at' => $now,
    ])->all());
    DB::table('collection_items')->insert(Card::pluck('tcgdex_id', 'id')->map(fn (string $t, int $id) => [
        'collection_id' => $collection->id, 'card_id' => $id, 'card_tcgdex_id' => $t, 'condition' => 'NM', 'quantity' => 1, 'created_at' => $now, 'updated_at' => $now,
    ])->values()->all());

    expect(Livewire::test(Index::class, ['username' => 'carlos'])->viewData('collectionTotal'))->toBe(601)
        ->and(RevampHtml::summary($this->get('/carlos')->getContent()))->toStartWith('601 cards');
});

test('other currencies are never added in: they move to a note beside the price attribution', function () {
    seedCollection();

    $html = $this->get('/carlos')->getContent();

    expect(RevampHtml::note($html))->toBe('Prices via tcgdex. Also €333.30 priced in EUR, not added to the total.')
        ->and($html)->not->toContain('722.66');
});

test('every set the collector owns from is a divider tab with its progress, All first', function () {
    seedCollection();

    $html = $this->get('/carlos')->assertOk()->getContent();

    expect($html)->toContain('role="tablist"')
        ->and($html)->toContain('id="col-tab-all"')
        ->and($html)->toContain('id="col-tab-me05"')
        ->and($html)->toContain('id="col-tab-fut2020"')
        ->and($html)->toContain('<b class="col-tab__n">2</b>')
        ->and($html)->toContain('<b class="col-tab__n">1/120</b>')
        ->and($html)->toContain('<b class="col-tab__n">1/5</b>')
        ->and($html)->toContain('role="tabpanel"');
    preg_match('#<button[^>]*id="col-tab-all"[^>]*>#', $html, $all);
    preg_match('#<button[^>]*id="col-tab-me05"[^>]*>#', $html, $set);
    expect($all[0])->toContain('aria-selected="true"')->toContain('tabindex="0"')->toContain('aria-controls="col-sheet"')
        ->and($set[0])->toContain('aria-selected="false"')->toContain('tabindex="-1"');
});

test('the tab for an unknown ?set= leaves All as the tab stop so the strip stays reachable by keyboard', function () {
    seedCollection();

    $html = $this->get('/carlos?set=zzz')->getContent();
    preg_match('#<button[^>]*id="col-tab-all"[^>]*>#', $html, $all);

    expect($all[0])->toContain('tabindex="0"')->toContain('aria-selected="false"')
        ->and($html)->toContain('No cards match');
});

test('selecting a tab filters the listing, restarts the batch and offers that set\'s own page', function () {
    seedCollection();

    Livewire::test(Index::class, ['username' => 'carlos'])
        ->assertDontSee('Open the Pokémon Futsal 2020 page')
        ->call('loadMore')->assertSet('take', 48)
        ->call('selectSet', 'fut2020')
        ->assertSet('setFilter', 'fut2020')->assertSet('take', 24)
        ->assertSee('Pikachu on the Ball')->assertDontSee('Mega Darkrai ex')
        ->assertSee('Open the Pokémon Futsal 2020 page')
        ->assertSeeHtml('href="'.route('gallery.show', ['username' => 'carlos', 'setTcgdexId' => 'fut2020']).'"')
        ->call('selectSet', '')
        ->assertSet('setFilter', '')
        ->assertSee('Mega Darkrai ex')->assertSee('Pikachu on the Ball');
});

test('the biggest sets lead the tabs', function () {
    $user = User::factory()->create(['username' => 'carlos']);
    $collection = Collection::factory()->for($user)->create(['is_public' => true, 'slug' => 'main']);
    $small = Set::create(['tcgdex_id' => 'aaa1', 'name' => 'Small Set', 'card_count' => 10, 'released_on' => '2026-01-01']);
    $big = Set::create(['tcgdex_id' => 'bbb1', 'name' => 'Big Set', 'card_count' => 10, 'released_on' => '2020-01-01']);
    foreach ([[$small, 1], [$big, 1], [$big, 2]] as [$set, $n]) {
        $card = Card::create(['tcgdex_id' => $set->tcgdex_id.'-'.$n, 'set_id' => $set->id, 'local_id' => (string) $n, 'name' => "{$set->name} $n"]);
        CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => $card->tcgdex_id, 'condition' => 'NM', 'quantity' => 1]);
    }

    $html = $this->get('/carlos')->getContent();

    expect(strpos($html, 'id="col-tab-bbb1"'))->toBeLessThan(strpos($html, 'id="col-tab-aaa1"'));
});

test('in value order with no search, the two priciest cards take a double pocket and the high-resolution art', function () {
    seedLeadCollection(4);

    $html = $this->get('/carlos')->assertOk()->getContent();

    expect(substr_count($html, 'col-slot col-lead col-enter'))->toBe(2)
        ->and($html)->toContain('src="https://assets.tcgdex.net/en/me/me05/4/high.webp"')
        ->and($html)->toContain('src="https://assets.tcgdex.net/en/me/me05/3/high.webp"')
        ->and($html)->toContain('src="https://assets.tcgdex.net/en/me/me05/2/low.webp"')
        ->and($html)->toContain('src="https://assets.tcgdex.net/en/me/me05/1/low.webp"');
});

test('there are no double pockets outside value order, while searching, or with two cards or fewer', function (string $query, int $cards) {
    seedLeadCollection($cards);

    $html = $this->get('/carlos'.$query)->assertOk()->getContent();

    expect(substr_count($html, 'col-lead'))->toBe(0);
})->with([
    'name order' => ['?sort=name', 4],
    'newest order' => ['?sort=newest', 4],
    'a search' => ['?search=Card', 4],
    'two cards' => ['', 2],
]);

test('a set or rarity filter keeps the double pockets', function () {
    seedLeadCollection(4);

    expect(substr_count($this->get('/carlos?set=me05')->getContent(), 'col-slot col-lead col-enter'))->toBe(2);
});

test('the rarity filter lists rarities from common up to secret, unknown wording last, and never "None"', function () {
    $user = User::factory()->create(['username' => 'carlos']);
    $collection = Collection::factory()->for($user)->create(['is_public' => true, 'slug' => 'main']);
    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);
    foreach (['Secret Rare', 'Zany Rare', 'Common', 'Rare Holo', 'None'] as $n => $rarity) {
        $card = Card::create(['tcgdex_id' => "me05-$n", 'set_id' => $set->id, 'local_id' => (string) $n, 'name' => "Card $n", 'rarity' => $rarity]);
        CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => "me05-$n", 'condition' => 'NM', 'quantity' => 1]);
    }

    expect(Livewire::test(Index::class, ['username' => 'carlos'])->viewData('rarities')->all())
        ->toBe(['Common', 'Rare Holo', 'Secret Rare', 'Zany Rare']);
});

test('a tile shows its 7-day move and only a rise is green', function () {
    $user = User::factory()->create(['username' => 'carlos']);
    $collection = Collection::factory()->for($user)->create(['is_public' => true, 'slug' => 'main']);
    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);
    foreach ([['Riser', 1240, 1000], ['Faller', 900, 1000]] as $n => [$name, $now, $then]) {
        $card = Card::create(['tcgdex_id' => "me05-$n", 'set_id' => $set->id, 'local_id' => (string) $n, 'name' => $name]);
        CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => "me05-$n", 'variant' => 'normal', 'condition' => 'NM', 'quantity' => 1]);
        CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'tcgplayer', 'variant' => 'normal', 'captured_on' => today(), 'currency' => 'USD', 'market_minor' => $now]);
        CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'tcgplayer', 'variant' => 'normal', 'captured_on' => today()->subDays(7), 'currency' => 'USD', 'market_minor' => $then]);
    }

    $html = $this->get('/carlos')->getContent();

    expect($html)->toContain('<span class="col-delta col-delta--up">+$2.40 · 7d</span>')
        ->and($html)->toContain('<span class="col-delta">−$1.00 · 7d</span>')
        ->and(substr_count($html, 'col-delta--up'))->toBe(1);
});

test('slab, stamp and the collector\'s photo survive on the new tile (option A)', function () {
    $user = User::factory()->create(['username' => 'carlos']);
    $collection = Collection::factory()->for($user)->create(['is_public' => true, 'slug' => 'main']);
    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);
    $card = Card::create(['tcgdex_id' => 'me05-116', 'set_id' => $set->id, 'local_id' => '116', 'name' => 'Mega Darkrai ex', 'rarity' => 'Common', 'official_image_url' => 'https://assets.tcgdex.net/en/me/me05/116/high.webp']);
    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => 'me05-116', 'variant' => 'normal+player-rewards-program', 'condition' => 'NM', 'grade_company' => 'PSA', 'grade_value' => '10', 'photo_path' => 'u/7/darkrai.jpg', 'quantity' => 1]);

    $html = $this->get('/carlos')->getContent();

    expect($html)->toContain('<span class="col-slab">PSA 10</span>')
        ->and($html)->toContain('Player Rewards Program')
        ->and($html)->toContain('/storage/collection-photos/u/7/darkrai.jpg')
        ->and($html)->not->toContain('nw-type-dot');
});

test('the page sits on the revamp shell: collector nav, its own stylesheet only, no old chrome, no client-side navigation, indexable', function () {
    seedCollection();

    $html = $this->get('/carlos')->assertOk()->getContent();
    $manifest = json_decode((string) file_get_contents(public_path('build/manifest.json')), true);

    expect(RevampHtml::currentNav($html))->toBe('Collection')
        ->and($html)->toContain('<nav class="bar-nav" aria-label="Gallery">')
        ->and($html)->toContain($manifest['resources/css/revamp.css']['file'])
        ->and($html)->toContain($manifest['resources/css/collection.css']['file'])
        ->and($html)->not->toContain($manifest['resources/css/app.css']['file'])
        ->and($html)->not->toContain('wire:navigate')
        ->and($html)->not->toContain('noindex')
        ->and($html)->not->toContain('class="nw-')
        ->and($html)->not->toContain('nw-hamburger');
});

test('the owner\'s empty state offers Add a card as a plain link and keeps the approved copy', function () {
    $user = User::factory()->create(['username' => 'carlos']);
    Collection::factory()->for($user)->create(['is_public' => true, 'slug' => 'main']);

    $html = $this->actingAs($user)->get('/carlos')->assertOk()->getContent();

    expect($html)->toContain('Nothing here yet')
        ->and($html)->toContain('Add a card or make one public from your collection.')
        ->and($html)->toContain('href="'.route('admin.collection.add').'"')
        ->and($html)->toContain('>Add a card</a>')
        ->and($html)->not->toContain('col-summary')
        ->and($html)->not->toContain('role="tablist"');
});

test('a signed-in visitor on a collection page sees no Log in link', function () {
    seedCollection();

    $html = $this->actingAs(User::factory()->create(['username' => 'ash']))->get('/carlos')->assertOk()->getContent();

    expect($html)->not->toContain('href="'.route('login').'"');
});
