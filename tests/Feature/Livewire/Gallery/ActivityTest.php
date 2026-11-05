<?php

declare(strict_types=1);

use App\Livewire\Gallery\Activity;
use App\Livewire\Gallery\Index;
use App\Models\User;
use App\Modules\Catalog\Models\Card;
use App\Modules\Catalog\Models\CardPriceSnapshot;
use App\Modules\Catalog\Models\Set;
use App\Modules\Catalog\Support\ChartRange;
use App\Modules\Collection\Models\Collection;
use App\Modules\Collection\Models\CollectionItem;
use App\Modules\Collection\Services\CollectionValueSeries;
use App\Modules\Collection\Services\PublicCollection;
use App\Support\DayLabel;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Support\PriceRowReads;
use Tests\Support\RevampHtml;

test('shows a price delta for a card with two snapshot days', function () {
    $user = User::factory()->create(['username' => 'carlos']);
    $collection = Collection::factory()->for($user)->create(['is_public' => true, 'slug' => 'main']);
    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);
    $card = Card::create(['tcgdex_id' => 'me05-116', 'set_id' => $set->id, 'local_id' => '116', 'name' => 'Mega Darkrai ex']);
    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => 'me05-116', 'condition' => 'NM', 'quantity' => 1]);

    CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'tcgplayer', 'variant' => 'normal', 'captured_on' => today()->subDay(), 'currency' => 'USD', 'market_minor' => 1000]);
    CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'tcgplayer', 'variant' => 'normal', 'captured_on' => today(), 'currency' => 'USD', 'market_minor' => 1200]);

    $response = $this->get('/carlos/activity');

    $response->assertOk();
    // The card also appears in the "recently added" feed regardless of
    // pricing, so asserting on its name alone would pass even if the
    // delta computation were completely broken — assert the actual
    // computed delta text ($12.00 - $10.00 = +2.00 USD) instead.
    $response->assertSee('+$2.00');
});

test('a delta spanning a source/variant/currency change is not shown — comparing them would be meaningless', function () {
    $user = User::factory()->create(['username' => 'carlos']);
    $collection = Collection::factory()->for($user)->create(['is_public' => true, 'slug' => 'main']);
    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);
    $card = Card::create(['tcgdex_id' => 'me05-116', 'set_id' => $set->id, 'local_id' => '116', 'name' => 'Mega Darkrai ex']);
    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => 'me05-116', 'condition' => 'NM', 'quantity' => 1]);

    // "Previous" day only has a cardmarket/EUR snapshot; "latest" day
    // only has a tcgplayer/USD one. resolveAsOf()'s priority chain
    // resolves each independently, so without a same-source/variant/
    // currency guard this would compute a nonsense "delta" of
    // 1200 - 900 = +3.00 across two different currencies.
    CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'cardmarket', 'variant' => 'default', 'captured_on' => today()->subDay(), 'currency' => 'EUR', 'market_minor' => 900]);
    CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'tcgplayer', 'variant' => 'normal', 'captured_on' => today(), 'currency' => 'USD', 'market_minor' => 1200]);

    $response = $this->get('/carlos/activity');

    $response->assertOk();
    // "1 update" is the item's own addition to the feed, not a move —
    // the header counts everything actually listed below it, so it
    // must never read a move-only number while the feed shows an
    // unrelated "Added <card>" entry.
    $response->assertSee('1 update');
});

// With the $1 floor the page hides a fabricated zero move whatever the resolver does; the resolver's own guard keeps its coverage in CardPriceResolverDeltaTest.
test('a card whose newest day only has a non-priority source does not render a fabricated zero delta', function () {
    // Regression for the final whole-branch review's Critical finding:
    // resolve() always prefers tcgplayer when ANY exists, so when today
    // only has cardmarket, it falls back to yesterday's tcgplayer row —
    // the SAME row a naive "compare today vs yesterday" would also land
    // on for "yesterday", producing a fake 0.00 delta that hides the
    // real move and would have slipped past the source/variant/currency
    // guard (a row trivially matches itself).
    $user = User::factory()->create(['username' => 'carlos']);
    $collection = Collection::factory()->for($user)->create(['is_public' => true, 'slug' => 'main']);
    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);
    $card = Card::create(['tcgdex_id' => 'me05-116', 'set_id' => $set->id, 'local_id' => '116', 'name' => 'Mega Darkrai ex']);
    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => 'me05-116', 'condition' => 'NM', 'quantity' => 1]);

    CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'tcgplayer', 'variant' => 'normal', 'captured_on' => today()->subDay(), 'currency' => 'USD', 'market_minor' => 1000]);
    CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'cardmarket', 'variant' => 'default', 'captured_on' => today(), 'currency' => 'EUR', 'market_minor' => 900]);

    $response = $this->get('/carlos/activity');

    $response->assertOk();
    $response->assertDontSee('+$0.00');
    $response->assertDontSee('-$0.00');
    $response->assertSee('1 update');
});

test('a snapshot with a null market_minor is never used as the previous comparison point', function () {
    $user = User::factory()->create(['username' => 'carlos']);
    $collection = Collection::factory()->for($user)->create(['is_public' => true, 'slug' => 'main']);
    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);
    $card = Card::create(['tcgdex_id' => 'me05-116', 'set_id' => $set->id, 'local_id' => '116', 'name' => 'Mega Darkrai ex']);
    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => 'me05-116', 'condition' => 'NM', 'quantity' => 1]);

    CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'tcgplayer', 'variant' => 'normal', 'captured_on' => today()->subDay(), 'currency' => 'USD', 'market_minor' => null]);
    CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'tcgplayer', 'variant' => 'normal', 'captured_on' => today(), 'currency' => 'USD', 'market_minor' => 1200]);

    $response = $this->get('/carlos/activity');

    $response->assertOk();
    $response->assertSee('1 update');
});

test('a card with only one snapshot day shows no delta, not a fake one', function () {
    $user = User::factory()->create(['username' => 'carlos']);
    $collection = Collection::factory()->for($user)->create(['is_public' => true, 'slug' => 'main']);
    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);
    $card = Card::create(['tcgdex_id' => 'me05-116', 'set_id' => $set->id, 'local_id' => '116', 'name' => 'Mega Darkrai ex']);
    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => 'me05-116', 'condition' => 'NM', 'quantity' => 1]);
    CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'tcgplayer', 'variant' => 'normal', 'captured_on' => today(), 'currency' => 'USD', 'market_minor' => 1000]);

    $response = $this->get('/carlos/activity');

    $response->assertOk();
    // The card shouldn't be listed among the deltas at all (no comparison
    // possible with only one snapshot day) — the price-deltas section
    // must fall back to its empty state, not render a delta for it.
    // (The previous assertion here checked for a literal "+$"/"-$"
    // substring that the view never actually renders — a currency-code
    // suffix like "2.50 USD" contains no "$" — so it passed regardless
    // of whether this behavior actually worked.)
    $response->assertSee('1 update');
});

test('shows recently added items in the activity feed', function () {
    $user = User::factory()->create(['username' => 'carlos']);
    $collection = Collection::factory()->for($user)->create(['is_public' => true, 'slug' => 'main']);
    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);
    $card = Card::create(['tcgdex_id' => 'me05-116', 'set_id' => $set->id, 'local_id' => '116', 'name' => 'Mega Darkrai ex']);
    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => 'me05-116', 'condition' => 'NM', 'quantity' => 1]);

    $response = $this->get('/carlos/activity');

    $response->assertOk();
    $response->assertSee('Mega Darkrai ex');
});

test('a private (non-public) collection contributes nothing', function () {
    $user = User::factory()->create(['username' => 'carlos']);
    $collection = Collection::factory()->for($user)->create(['is_public' => false, 'slug' => 'private']);
    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);
    $card = Card::create(['tcgdex_id' => 'me05-116', 'set_id' => $set->id, 'local_id' => '116', 'name' => 'Mega Darkrai ex']);
    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => 'me05-116', 'condition' => 'NM', 'quantity' => 1]);

    $response = $this->get('/carlos/activity');

    $response->assertOk();
    $response->assertDontSee('Mega Darkrai ex');
});

test('the activity route requires no authentication', function () {
    $user = User::factory()->create(['username' => 'carlos']);
    Collection::factory()->for($user)->create(['is_public' => true, 'slug' => 'main']);

    $response = $this->get('/carlos/activity');

    $response->assertOk();
});

test('a nonexistent username 404s', function () {
    $response = $this->get('/nobody-here/activity');

    $response->assertNotFound();
});

test('the old Spanish path redirects permanently to the English one', function () {
    User::factory()->create(['username' => 'carlos']);

    $response = $this->get('/carlos/movimientos');

    $response->assertRedirect('/carlos/activity');
    expect($response->status())->toBe(301);
});

test('an added item is priced at its own variant, not the card-level chain', function () {
    // The real me05-001 Tropius: tcgplayer prices the normal print at
    // $0.05 and the reverse-holofoil at $0.22. resolve()'s card-level
    // chain only ever considers tcgplayer normal/holofoil, so a feed
    // entry labelled "Reverse Holofoil" would carry the normal print's
    // $0.05 — a price for a card the collector does not own.
    $user = User::factory()->create(['username' => 'carlos']);
    $collection = Collection::factory()->for($user)->create(['is_public' => true, 'slug' => 'main']);
    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);
    $card = Card::create(['tcgdex_id' => 'me05-001', 'set_id' => $set->id, 'local_id' => '001', 'name' => 'Tropius', 'variants' => ['normal' => true, 'reverse' => true]]);
    // Quantity 2 deliberately: the masthead total has ALWAYS resolved
    // per-variant (Valuation::itemTotals), so with quantity 1 it would
    // read "$0.22" too and assertSee could not tell a correct feed row
    // apart from a missing one. At quantity 2 the total is $0.44 and
    // $0.22 can only be the feed entry's own per-unit price.
    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => 'me05-001', 'condition' => 'NM', 'quantity' => 2, 'variant' => 'reverse-holofoil']);

    CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'tcgplayer', 'variant' => 'normal', 'captured_on' => today(), 'currency' => 'USD', 'market_minor' => 5]);
    CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'tcgplayer', 'variant' => 'reverse-holofoil', 'captured_on' => today(), 'currency' => 'USD', 'market_minor' => 22]);

    $response = $this->get('/carlos/activity');

    $response->assertOk();
    $response->assertSee('$0.44'); // the collection total
    $response->assertSee('$0.22'); // the feed row, per unit
    $response->assertDontSee('$0.05');
});

test('an added item whose exact variant has no snapshot still shows a price', function () {
    // resolveForVariant() matches the variant EXACTLY and returns null
    // otherwise, so it cannot stand alone here. A cardmarket-only card
    // with no usable `variants` flags stores its price under 'default'
    // (TcgdexCardCatalogProvider::extractPrices), while the add form
    // falls back to offering all three variants (AddCollectionItem's
    // KNOWN_VARIANTS) — so the copy can legitimately be saved as
    // 'normal' with no 'normal' row to match. Without the same
    // card-level fallback Valuation::headlineSnapshot() already applies,
    // the gallery tile would show a price while this feed row went blank.
    $user = User::factory()->create(['username' => 'carlos']);
    $collection = Collection::factory()->for($user)->create(['is_public' => true, 'slug' => 'main']);
    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);
    $card = Card::create(['tcgdex_id' => 'me05-037', 'set_id' => $set->id, 'local_id' => '037', 'name' => 'Lampent']);
    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => 'me05-037', 'condition' => 'NM', 'quantity' => 2, 'variant' => 'normal']);

    CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'cardmarket', 'variant' => 'default', 'captured_on' => today(), 'currency' => 'EUR', 'market_minor' => 20]);

    $response = $this->get('/carlos/activity');

    $response->assertOk();
    $response->assertSee('€0.20');
});

test('the feed never prices one variant with a different print\'s snapshot', function () {
    // The fallback for "no row matches this variant" must not reach a
    // snapshot that names a DIFFERENT print. Here the only price is
    // tcgplayer's normal print while the copy is reverse-holofoil:
    // showing $0.05 under a "Reverse Holofoil" label is the exact
    // mislabelling this component was fixed for. No honest price exists,
    // so the row shows none.
    $user = User::factory()->create(['username' => 'carlos']);
    $collection = Collection::factory()->for($user)->create(['is_public' => true, 'slug' => 'main']);
    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);
    $card = Card::create(['tcgdex_id' => 'me05-001', 'set_id' => $set->id, 'local_id' => '001', 'name' => 'Tropius', 'variants' => ['normal' => true, 'reverse' => true]]);
    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => 'me05-001', 'condition' => 'NM', 'quantity' => 1, 'variant' => 'reverse-holofoil']);

    CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'tcgplayer', 'variant' => 'normal', 'captured_on' => today(), 'currency' => 'USD', 'market_minor' => 5]);

    $response = $this->get('/carlos/activity');

    $response->assertOk();
    $response->assertSee('Tropius');
    $response->assertDontSee('$0.05');
});

test('the value chart prices each copy by its own variant, not one price per card', function () {
    // One normal + one reverse-holofoil copy, whose prints move
    // independently. Pricing the card once and multiplying by the total
    // quantity charts a collection the user does not own: 2x the normal
    // print ($2.00 -> $4.00) instead of one of each ($1.10 -> $2.20).
    $user = User::factory()->create(['username' => 'carlos']);
    $collection = Collection::factory()->for($user)->create(['is_public' => true, 'slug' => 'main']);
    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);
    $card = Card::create(['tcgdex_id' => 'me05-037', 'set_id' => $set->id, 'local_id' => '037', 'name' => 'Lampent', 'variants' => ['normal' => true, 'reverse' => true]]);
    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => 'me05-037', 'condition' => 'NM', 'quantity' => 1, 'variant' => 'normal']);
    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => 'me05-037', 'condition' => 'NM', 'quantity' => 1, 'variant' => 'reverse-holofoil']);

    CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'tcgplayer', 'variant' => 'normal', 'captured_on' => today()->subDay(), 'currency' => 'USD', 'market_minor' => 100]);
    CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'tcgplayer', 'variant' => 'reverse-holofoil', 'captured_on' => today()->subDay(), 'currency' => 'USD', 'market_minor' => 10]);
    CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'tcgplayer', 'variant' => 'normal', 'captured_on' => today(), 'currency' => 'USD', 'market_minor' => 200]);
    CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'tcgplayer', 'variant' => 'reverse-holofoil', 'captured_on' => today(), 'currency' => 'USD', 'market_minor' => 20]);

    $response = $this->get('/carlos/activity');

    $response->assertOk();
    // The sparkline's accessible description carries both endpoints.
    $response->assertSee('from $1.10 to $2.20');
    // The chart's last point must agree with the headline total, which
    // has always summed per variant — they price the same collection.
    $response->assertSee('$2.20');
});

test('a price move is reported for the variant the collector actually owns', function () {
    // The collector owns only the reverse-holofoil print, which moved
    // +$2.00. The normal print moved +$5.00 over the same two days.
    // Running the card-level chain reports the normal print's move for a
    // card no normal copy of which is owned.
    $user = User::factory()->create(['username' => 'carlos']);
    $collection = Collection::factory()->for($user)->create(['is_public' => true, 'slug' => 'main']);
    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);
    $card = Card::create(['tcgdex_id' => 'me05-001', 'set_id' => $set->id, 'local_id' => '001', 'name' => 'Tropius', 'variants' => ['normal' => true, 'reverse' => true]]);
    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => 'me05-001', 'condition' => 'NM', 'quantity' => 1, 'variant' => 'reverse-holofoil']);

    CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'tcgplayer', 'variant' => 'normal', 'captured_on' => today()->subDay(), 'currency' => 'USD', 'market_minor' => 100]);
    CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'tcgplayer', 'variant' => 'reverse-holofoil', 'captured_on' => today()->subDay(), 'currency' => 'USD', 'market_minor' => 1000]);
    CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'tcgplayer', 'variant' => 'normal', 'captured_on' => today(), 'currency' => 'USD', 'market_minor' => 600]);
    CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'tcgplayer', 'variant' => 'reverse-holofoil', 'captured_on' => today(), 'currency' => 'USD', 'market_minor' => 1200]);

    $moves = Livewire::test(Activity::class, ['username' => 'carlos'])->viewData('feed')->where('kind', 'move');

    expect($moves->map(fn (array $e) => $e['delta']->deltaMinor)->values()->all())->toBe([200]);
    $this->get('/carlos/activity')->assertOk()->assertSee('+$2.00')->assertDontSee('+$5.00');
});

/**
 * One public collection owning one normal copy of one card, with a
 * tcgplayer price row for each [days ago => minor units] pair.
 *
 * @param  array<int, int>  $rows
 */
function ownedCardPricedDaysAgo(array $rows, string $tcgdexId = 'me05-116', string $variant = 'normal'): void
{
    $user = User::query()->where('username', 'carlos')->first() ?? User::factory()->create(['username' => 'carlos']);
    $collection = Collection::withoutGlobalScopes()->firstOrCreate(['user_id' => $user->id, 'slug' => 'main'], ['name' => 'My Collection', 'is_public' => true]);
    $set = Set::query()->firstOrCreate(['tcgdex_id' => 'me05'], ['name' => 'Pitch Black']);
    $card = Card::create(['tcgdex_id' => $tcgdexId, 'set_id' => $set->id, 'local_id' => substr($tcgdexId, -3), 'name' => 'Card '.$tcgdexId]);
    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => $tcgdexId, 'condition' => 'NM', 'quantity' => 1, 'variant' => $variant]);

    foreach ($rows as $daysAgo => $minor) {
        CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'tcgplayer', 'variant' => $variant, 'captured_on' => today()->subDays($daysAgo), 'currency' => 'USD', 'market_minor' => $minor]);
    }
}

test('the value chart opens on one month and switches range', function () {
    ownedCardPricedDaysAgo([0 => 300, 40 => 200, 100 => 100]);

    $page = Livewire::test(Activity::class, ['username' => 'carlos']);
    expect($page->viewData('chartRange'))->toBe(ChartRange::OneMonth)
        ->and($page->viewData('series')->count())->toBe(31);

    $page->call('selectRange', '3m');
    expect($page->viewData('chartRange'))->toBe(ChartRange::ThreeMonths)
        ->and($page->get('range'))->toBe('3m');
});

test('selectRange ignores anything that is not a string', function () {
    ownedCardPricedDaysAgo([0 => 300, 40 => 200]);

    $page = Livewire::test(Activity::class, ['username' => 'carlos'])->call('selectRange', '6m')->call('selectRange', ['1y']);

    expect($page->viewData('chartRange'))->toBe(ChartRange::SixMonths);
});

test('the value chart\'s last point equals the headline total on a long range', function () {
    ownedCardPricedDaysAgo([0 => 300, 60 => 250, 120 => 100], 'me05-116', 'normal');
    ownedCardPricedDaysAgo([0 => 40, 90 => 30, 119 => 10], 'me05-117', 'reverse-holofoil');

    $page = Livewire::withQueryParams(['range' => '6m'])->test(Activity::class, ['username' => 'carlos']);

    expect($page->viewData('series')->last()['minor'])->toBe($page->viewData('totals')['USD']);
});

test('an unknown or array range opens one month with a 200', function () {
    ownedCardPricedDaysAgo([0 => 300, 40 => 200]);

    $this->get('/carlos/activity?range=7d')->assertOk()->assertSeeHtml('wire:click="selectRange(\'1m\')"');
    expect(Livewire::withQueryParams(['range' => '7d'])->test(Activity::class, ['username' => 'carlos'])->viewData('chartRange'))->toBe(ChartRange::OneMonth);
    $this->get('/carlos/activity?range[]=1y')->assertOk();
    expect(Livewire::withQueryParams(['range' => ['1y']])->test(Activity::class, ['username' => 'carlos'])->viewData('chartRange'))->toBe(ChartRange::OneMonth);
});

test('the value panel names the range instead of a day count', function () {
    // Six days of history: a 3-month chart samples every third day, so it holds three
    // points (0, 3 and 6 days ago) and the old head would have read "· 3 days".
    ownedCardPricedDaysAgo([0 => 300, 6 => 200]);

    $this->get('/carlos/activity?range=3m')->assertSee('· 3 months')->assertDontSee('· 3 days');
});

test('history since shows when the collection\'s prices start after the range start', function () {
    // 46 days back is off the 3-month chart's every-third-day grid, so only the true earliest day can be named.
    ownedCardPricedDaysAgo([0 => 300, 46 => 200]);

    $this->get('/carlos/activity')->assertDontSee('History since');
    $this->get('/carlos/activity?range=3m')->assertSeeHtml('History since <span class="act-mono">'.DayLabel::of(today()->subDays(46)).'</span>');
    $this->get('/carlos/activity?range=1y')->assertSeeHtml('History since <span class="act-mono">'.DayLabel::of(today()->subDays(46)).'</span>');
});

test('history older than the range does not claim a start', function () {
    ownedCardPricedDaysAgo([0 => 300, 400 => 200, 100 => 250]);

    $this->get('/carlos/activity?range=1y')->assertDontSee('History since');
    $this->get('/carlos/activity?range=6m')->assertDontSee('History since');
});

test('two days of history chart on every range', function (string $range) {
    ownedCardPricedDaysAgo([0 => 300, 2 => 200]);

    $page = Livewire::withQueryParams(['range' => $range])->test(Activity::class, ['username' => 'carlos']);

    expect($page->viewData('series')->count())->toBeGreaterThanOrEqual(2);
})->with(['1m', '3m', '6m', '1y']);

test('the series is not built when there is no priced currency', function () {
    ownedCardPricedDaysAgo([]);

    $queries = [];
    DB::listen(function ($query) use (&$queries) {
        $queries[] = $query->sql;
    });
    Livewire::test(Activity::class, ['username' => 'carlos']);

    expect(collect($queries)->filter(fn ($sql) => str_contains($sql, 'min("captured_on")')))->toBeEmpty();
});

test('the range buttons stay when there is not enough history to chart', function () {
    ownedCardPricedDaysAgo([0 => 300]);

    $this->get('/carlos/activity')
        ->assertSee('A value chart appears once two daily price snapshots exist.')
        ->assertSee("selectRange('3m')", false);
});

test('the feed reuses the cards the headline loaded, so their price rows load once', function () {
    ownedCardPricedDaysAgo([0 => 3000, 1 => 2900, 5 => 2500]);
    ownedCardPricedDaysAgo([0 => 9000, 2 => 8800], 'me05-117', 'reverse-holofoil');

    $priceRowLoads = 0;
    DB::listen(function ($query) use (&$priceRowLoads) {
        // Any query that loads price rows themselves; the series' count and
        // min() aggregates and its per-day lookups are not row loads.
        if (preg_match('/^select \* from "card_price_snapshots"|row_number\(\) over/i', $query->sql)) {
            $priceRowLoads++;
        }
    });

    $feed = Livewire::test(Activity::class, ['username' => 'carlos'])->viewData('feed');

    expect($priceRowLoads)->toBe(1)
        ->and($feed->where('kind', 'added')->map(fn (array $e) => $e['snapshot']?->market_minor)->sort()->values()->all())->toBe([3000, 9000])
        ->and($feed->where('kind', 'move')->map(fn (array $e) => $e['delta']->deltaMinor)->sort()->values()->all())->toBe([100, 200]);
});

test('a feed copy whose card is past the headline\'s card cap is still priced', function () {
    $user = User::factory()->create(['username' => 'carlos']);
    $collection = Collection::factory()->for($user)->create(['is_public' => true, 'slug' => 'main']);
    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);
    $now = now();
    $count = CollectionValueSeries::MAX_CARDS + 1;

    DB::table('cards')->insert(collect(range(1, $count))->map(fn (int $i) => [
        'tcgdex_id' => "me05-{$i}", 'set_id' => $set->id, 'local_id' => (string) $i,
        'name' => "Card {$i}", 'created_at' => $now, 'updated_at' => $now,
    ])->all());
    DB::table('collection_items')->insert(Card::orderBy('id')->pluck('tcgdex_id', 'id')->map(fn (string $tcgdexId, int $id) => [
        'collection_id' => $collection->id, 'card_id' => $id, 'card_tcgdex_id' => $tcgdexId, 'variant' => 'normal',
        'condition' => 'NM', 'quantity' => 1, 'created_at' => $now->copy()->addSeconds($id), 'updated_at' => $now,
    ])->values()->all());
    DB::table('card_price_snapshots')->insert(Card::orderBy('id')->pluck('id')->map(fn (int $id) => [
        'card_id' => $id, 'source' => 'tcgplayer', 'origin' => 'tcgdex', 'variant' => 'normal',
        'captured_on' => today()->toDateString(), 'currency' => 'USD', 'market_minor' => 1000 + $id,
        'created_at' => $now, 'updated_at' => $now,
    ])->all());

    // The same query the page runs, so the same card is left out; its copy
    // becomes the newest, so the feed lists it.
    $headlineCards = PublicCollection::for($user)->cardsQuery()->take(CollectionValueSeries::MAX_CARDS)->get()->modelKeys();
    $leftOut = Card::whereKeyNot($headlineCards)->sole();
    CollectionItem::where('card_id', $leftOut->id)->update(['created_at' => $now->copy()->addDay()]);

    $added = null;
    $reads = PriceRowReads::during(function () use (&$added) {
        $added = Livewire::test(Activity::class, ['username' => 'carlos'])->viewData('feed')->where('kind', 'added');
    });

    // The past-cap path ran: the headline's load left the card out, and a
    // second deciding load fetched exactly it. If the page's unordered load
    // ever picks other cards than the query above, this fails rather than
    // passing without reaching the path.
    expect($reads->stray)->toBe([])
        ->and($reads->deciding)->toHaveCount(2)
        ->and($reads->decidingIds(0))->not->toContain($leftOut->id)
        ->and($reads->decidingIds(1))->toBe([$leftOut->id])
        ->and($added->first()['card']->id)->toBe($leftOut->id)
        ->and($added->every(fn (array $e) => $e['snapshot']?->market_minor === 1000 + $e['card']->id))->toBeTrue();
});

/**
 * One public copy of a card whose price moved from $from to $to (minor
 * units) between yesterday and today. A USD move is tcgplayer's normal
 * print; a EUR move is cardmarket's card-wide row, which a copy saved
 * without a variant falls to.
 */
function activityMover(string $tcgdexId, int $from, int $to, string $currency = 'USD', int $quantity = 1): void
{
    $user = User::query()->where('username', 'carlos')->first() ?? User::factory()->create(['username' => 'carlos']);
    $collection = Collection::withoutGlobalScopes()->firstOrCreate(['user_id' => $user->id, 'slug' => 'main'], ['name' => 'My Collection', 'is_public' => true]);
    $set = Set::query()->firstOrCreate(['tcgdex_id' => 'me05'], ['name' => 'Pitch Black']);
    $card = Card::create(['tcgdex_id' => $tcgdexId, 'set_id' => $set->id, 'local_id' => substr($tcgdexId, 5), 'name' => 'Card '.$tcgdexId]);
    [$source, $variant, $copyVariant] = $currency === 'USD' ? ['tcgplayer', 'normal', 'normal'] : ['cardmarket', 'default', null];
    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => $tcgdexId, 'condition' => 'NM', 'quantity' => $quantity, 'variant' => $copyVariant]);

    foreach ([1 => $from, 0 => $to] as $daysAgo => $minor) {
        CardPriceSnapshot::create(['card_id' => $card->id, 'source' => $source, 'variant' => $variant, 'captured_on' => today()->subDays($daysAgo), 'currency' => $currency, 'market_minor' => $minor]);
    }
}

test('a price move is listed from $1 up, per card, in its own currency; the copy itself always is', function (int $from, int $to, string $currency, int $quantity, bool $listed) {
    activityMover('me05-201', $from, $to, $currency, $quantity);

    $feed = Livewire::test(Activity::class, ['username' => 'carlos'])->viewData('feed');

    expect($feed->where('kind', 'move')->count())->toBe($listed ? 1 : 0)
        ->and($feed->where('kind', 'added')->count())->toBe(1);
})->with([
    'up $0.99' => [1000, 1099, 'USD', 1, false],
    'down $0.99' => [1099, 1000, 'USD', 1, false],
    'up exactly $1.00' => [1000, 1100, 'USD', 1, true],
    'down exactly $1.00' => [1100, 1000, 'USD', 1, true],
    'up €0.99' => [1000, 1099, 'EUR', 1, false],
    'up €1.50' => [1000, 1150, 'EUR', 1, true],
    'three copies that each moved $0.50' => [1000, 1050, 'USD', 3, false],
]);

test('small moves are dropped before the 40-entry cap, so they never push out a real one', function () {
    foreach (range(1, 41) as $i) {
        activityMover(sprintf('me05-%03d', $i), 1000, 1050);
    }
    ownedCardPricedDaysAgo([3 => 1000, 2 => 1500], 'me05-500');
    // Every copy is older than every move, so only moves compete for the first slots.
    CollectionItem::query()->update(['created_at' => now()->subDays(10)]);

    $feed = Livewire::test(Activity::class, ['username' => 'carlos'])->viewData('feed');

    expect($feed)->toHaveCount(40)
        ->and($feed->where('kind', 'move')->map(fn (array $e) => $e['delta']->deltaMinor)->values()->all())->toBe([500]);
});

// The page loads its cards in an order the database picks (not their
// creation order), so a couple of cards could come back biggest-first by
// luck. Six in a scrambled order make that luck negligible: only a real
// ordering rule lists them all biggest first.
test('moves of the same day are listed biggest first', function () {
    foreach ([300, 150, 500, 200, 400, 250] as $i => $move) {
        activityMover(sprintf('me05-%03d', 201 + $i), 1000, 1000 + $move);
    }

    $moves = Livewire::test(Activity::class, ['username' => 'carlos'])->viewData('feed')->where('kind', 'move');

    expect($moves->map(fn (array $e) => $e['delta']->deltaMinor)->values()->all())->toBe([500, 400, 300, 250, 200, 150]);
});

// Equal sizes leave the order to the database, which hands the cards back
// in no fixed order, so six of them: only a tie-breaker lists all six by
// card id, and the same page always reads the same.
test('moves of the same day and size are listed by card id, oldest card first', function () {
    foreach (range(206, 201) as $number) {
        activityMover(sprintf('me05-%03d', $number), 1000, 1200);
    }

    $moves = Livewire::test(Activity::class, ['username' => 'carlos'])->viewData('feed')->where('kind', 'move');
    $ids = $moves->map(fn (array $e) => $e['card']->id)->values()->all();

    expect($ids)->toHaveCount(6)
        ->and($ids)->toBe(collect($ids)->sort()->values()->all());
});

test('a newer day lists before an older day, whatever the sizes', function () {
    ownedCardPricedDaysAgo([3 => 1000, 2 => 1500], 'me05-501');
    ownedCardPricedDaysAgo([1 => 1000, 0 => 1150], 'me05-502');

    $moves = Livewire::test(Activity::class, ['username' => 'carlos'])->viewData('feed')->where('kind', 'move');

    expect($moves->map(fn (array $e) => $e['delta']->deltaMinor)->values()->all())->toBe([150, 500]);
});

test('the feed is cut into one group per day, newest day first, without changing what is listed', function () {
    ownedCardPricedDaysAgo([1 => 1000, 0 => 1200], 'me05-301');
    ownedCardPricedDaysAgo([2 => 400, 1 => 900], 'me05-302');
    CollectionItem::query()->update(['created_at' => now()->subDays(3)]);

    $page = Livewire::test(Activity::class, ['username' => 'carlos']);
    $days = $page->viewData('days');

    expect($days->map(fn (array $d) => [$d['date']->toDateString(), $d['entries']->pluck('kind')->all()])->all())->toBe([
        [today()->toDateString(), ['move']],
        [today()->subDay()->toDateString(), ['move']],
        [today()->subDays(3)->toDateString(), ['added', 'added']],
    ])->and($days->flatMap(fn (array $d) => $d['entries'])->all())->toBe($page->viewData('feed')->all());
});

test('a day is a UTC calendar day: copies added minutes either side of midnight land in different groups', function () {
    ownedCardPricedDaysAgo([], 'me05-311');
    ownedCardPricedDaysAgo([], 'me05-312');
    CollectionItem::query()->where('card_tcgdex_id', 'me05-311')->update(['created_at' => today()->subDay()->setTime(23, 59)]);
    CollectionItem::query()->where('card_tcgdex_id', 'me05-312')->update(['created_at' => today()->setTime(0, 1)]);

    $days = Livewire::test(Activity::class, ['username' => 'carlos'])->viewData('days');

    expect($days->map(fn (array $d) => [$d['date']->toDateString(), $d['entries']->map(fn (array $e) => $e['card']->tcgdex_id)->all()])->all())->toBe([
        [today()->toDateString(), ['me05-312']],
        [today()->subDay()->toDateString(), ['me05-311']],
    ]);
});

test('the summary is dated by the newest marketplace price, never a manual one', function () {
    ownedCardPricedDaysAgo([2 => 1000, 3 => 900], 'me05-401');
    CardPriceSnapshot::create(['card_id' => Card::where('tcgdex_id', 'me05-401')->sole()->id, 'source' => 'manual', 'variant' => 'normal', 'captured_on' => today(), 'currency' => 'USD', 'market_minor' => 5000]);

    $updatedAt = Livewire::test(Activity::class, ['username' => 'carlos'])->viewData('updatedAt');

    expect($updatedAt?->toDateString())->toBe(today()->subDays(2)->toDateString());
});

test('the summary date is the one the collection page shows for the same data', function () {
    ownedCardPricedDaysAgo([2 => 1000, 3 => 900], 'me05-401');
    CardPriceSnapshot::create(['card_id' => Card::where('tcgdex_id', 'me05-401')->sole()->id, 'source' => 'manual', 'variant' => 'normal', 'captured_on' => today()->addDay(), 'currency' => 'USD', 'market_minor' => 9000]);

    $activity = Livewire::test(Activity::class, ['username' => 'carlos'])->viewData('updatedAt');
    $collection = Livewire::test(Index::class, ['username' => 'carlos'])->viewData('updatedAt');

    expect($activity?->toDateString())->toBe($collection?->toDateString())->and($activity)->not->toBeNull();
});

test('the page\'s query count does not grow with the collection', function () {
    $add = function (int $from, int $to) {
        foreach (range($from, $to) as $i) {
            activityMover(sprintf('me05-%03d', $i), 1000, 1150);
        }
    };
    // Cold both times, so a cache hit on one side cannot hide a per-card read on the other.
    $queriesFor = function (): array {
        Cache::flush();
        $count = 0;
        DB::listen(function () use (&$count) {
            $count++;
        });
        $feed = Livewire::test(Activity::class, ['username' => 'carlos'])->viewData('feed');

        return [$count, $feed];
    };

    $add(1, 3);
    [$few, $fewFeed] = $queriesFor();
    $add(4, 30);
    [$many, $manyFeed] = $queriesFor();

    expect($fewFeed->where('kind', 'move'))->toHaveCount(3)
        ->and($manyFeed->where('kind', 'move')->count())->toBeGreaterThan(3)
        ->and($many)->toBe($few);
});

/**
 * Two cards: Mega Darkrai ex rose $2.00 today, Lampent fell $1.50
 * yesterday; both copies were added three days ago.
 */
function activitySeedFeed(): void
{
    $user = User::factory()->create(['username' => 'carlos', 'name' => 'Carlos']);
    $collection = Collection::factory()->for($user)->create(['is_public' => true, 'slug' => 'main']);
    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);

    foreach ([['me05-116', '116', 'Mega Darkrai ex', [1 => 1000, 0 => 1200]], ['me05-037', '037', 'Lampent', [2 => 1000, 1 => 850]]] as $n => [$tcgdexId, $localId, $name, $rows]) {
        $card = Card::create(['tcgdex_id' => $tcgdexId, 'set_id' => $set->id, 'local_id' => $localId, 'name' => $name, 'rarity' => 'Rare', 'official_image_url' => "https://assets.tcgdex.net/en/me/me05/{$localId}/high.webp"]);
        $item = CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => $tcgdexId, 'variant' => 'normal', 'condition' => 'NM', 'quantity' => 1]);
        CollectionItem::whereKey($item->id)->update(['created_at' => now()->subDays(3)->subMinutes($n)]);
        foreach ($rows as $daysAgo => $minor) {
            CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'tcgplayer', 'variant' => 'normal', 'captured_on' => today()->subDays($daysAgo), 'currency' => 'USD', 'market_minor' => $minor]);
        }
    }
}

/**
 * Every feed amount as [class attribute, visible text], in page order.
 *
 * @return list<array{0: string, 1: string}>
 */
function activityAmounts(string $html): array
{
    preg_match_all('#<p class="(act-amt[^"]*)">(.*?)</p>#s', $html, $m, PREG_SET_ORDER);

    return array_map(fn (array $x) => [$x[1], trim((string) preg_replace('/\s+/', ' ', strip_tags($x[2])))], $m);
}

test('the page sits on the revamp shell: Activity marked in the nav, its own stylesheet only, no client-side navigation, indexable', function () {
    activitySeedFeed();

    $html = $this->get('/carlos/activity')->assertOk()->getContent();
    $manifest = json_decode((string) file_get_contents(public_path('build/manifest.json')), true);

    expect(RevampHtml::currentNav($html))->toBe('Activity')
        ->and($html)->toContain('<header class="bar bar--nav">')
        ->and($html)->toContain($manifest['resources/css/revamp.css']['file'])
        ->and($html)->toContain($manifest['resources/css/activity.css']['file'])
        ->and($html)->not->toContain($manifest['resources/css/app.css']['file'])
        ->and($html)->not->toContain('wire:navigate')
        ->and($html)->not->toContain('noindex')
        ->and($html)->toContain('<title>Activity · Carlos&#039;s collection · tcg-vault</title>')
        ->and($html)->toContain('<meta name="description" content="Price movements and recent additions in Carlos&#039;s Pokémon TCG collection.">');
});

test('the head is the word Activity and one summary line; other currencies go to a note', function () {
    activitySeedFeed();
    $card = Card::create(['tcgdex_id' => 'me05-050', 'set_id' => Set::where('tcgdex_id', 'me05')->sole()->id, 'local_id' => '050', 'name' => 'Card me05-050']);
    CollectionItem::create(['collection_id' => Collection::withoutGlobalScopes()->where('slug', 'main')->sole()->id, 'card_id' => $card->id, 'card_tcgdex_id' => 'me05-050', 'condition' => 'NM', 'quantity' => 1]);
    CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'cardmarket', 'variant' => 'default', 'captured_on' => today(), 'currency' => 'EUR', 'market_minor' => 330]);

    $html = $this->get('/carlos/activity')->getContent();

    expect($html)->toContain('<h1 id="act-h" class="act-who">Activity</h1>')
        ->and(RevampHtml::text('act-summary', $html))->toBe('$20.50 on '.DayLabel::of(today()))
        ->and(RevampHtml::text('act-note', $html))->toBe('Also €3.30 priced in EUR, not added to the total.')
        ->and($html)->not->toContain('collection history');
});

test('the feed is one sheet per day under a tab that counts its updates, and says what it leaves out', function () {
    activitySeedFeed();

    $html = $this->get('/carlos/activity')->getContent();
    preg_match_all('#<h3 class="act-dtab"[^>]*>(.*?)</h3>#s', $html, $tabs);

    expect(RevampHtml::text('act-sec-t', $html))->toBe('Latest · 4 updates')
        ->and(RevampHtml::text('act-sec-r', $html))->toBe('Price moves under $1 are not listed.')
        ->and(array_map(fn (string $t) => trim((string) preg_replace('/\s+/', ' ', strip_tags($t))), $tabs[1]))->toBe([
            DayLabel::of(today()).' 1 update',
            DayLabel::of(today()->subDay()).' 1 update',
            DayLabel::of(today()->subDays(3)).' 2 updates',
        ]);
});

test('a day from an earlier year carries its year in its tab', function () {
    ownedCardPricedDaysAgo([], 'me05-321');
    CollectionItem::query()->update(['created_at' => today()->subYear()->startOfYear()->setTime(12, 0)]);

    $html = $this->get('/carlos/activity')->assertOk()->getContent();

    expect($html)->toContain('<b>1 Jan '.(today()->year - 1).'</b>');
});

test('each day sheet keeps its list role and the days are headings, not named landmarks', function () {
    activitySeedFeed();

    $html = $this->get('/carlos/activity')->getContent();

    expect(substr_count($html, '<ul class="act-dsheet" role="list">'))->toBe(3)
        ->and($html)->not->toContain('aria-labelledby="act-day-')
        ->and($html)->toContain('<h3 class="act-dtab" id="act-day-'.today()->toDateString().'">');
});

test('every row carries its card thumbnail, only additions the plus, a rise carries the up class and a fall the down class', function () {
    $this->freezeTime();
    activitySeedFeed();

    $html = $this->get('/carlos/activity')->getContent();

    expect(substr_count($html, 'src="https://assets.tcgdex.net/en/me/me05/116/low.webp"'))->toBe(2)
        ->and(substr_count($html, 'src="https://assets.tcgdex.net/en/me/me05/037/low.webp"'))->toBe(2)
        ->and(substr_count($html, 'class="act-plus"'))->toBe(2)
        ->and(activityAmounts($html))->toBe([
            ['act-amt act-amt--up', '+$2.00 now $12.00'],
            ['act-amt act-amt--down', '−$1.50 now $8.50'],
            ['act-amt', '$12.00 '.now()->subDays(3)->diffForHumans(short: true)],
            ['act-amt', '$8.50 '.now()->subDays(3)->subMinute()->diffForHumans(short: true)],
        ])
        ->and($html)->toContain('went up')
        ->and($html)->toContain('went down')
        ->and($html)->not->toContain('&#9660;')
        ->and($html)->not->toContain('▼');
});

test('a row owns its thumbnail: the picture is not optional, a morph leaves it alone, and every row is keyed', function () {
    activitySeedFeed();
    $darkrai = Card::where('tcgdex_id', 'me05-116')->sole();
    $lampent = Card::where('tcgdex_id', 'me05-037')->sole();

    $html = $this->get('/carlos/activity')->assertOk()->getContent();
    preg_match_all('#<img [^>]*src="https://assets\.tcgdex\.net/[^>]*>#', $html, $images);
    preg_match_all('#<span class="act-thumb"[^>]*>#', $html, $thumbs);
    preg_match_all('#<li class="act-row"[^>]*>#', $html, $rows);

    expect($images[0])->toHaveCount(4)
        ->and(array_filter($images[0], fn (string $img) => str_contains($img, 'data-optional')))->toBe([])
        ->and($thumbs[0])->toBe(array_fill(0, 4, '<span class="act-thumb" wire:ignore>'))
        ->and($rows[0])->toEqualCanonicalizing([
            '<li class="act-row" wire:key="move-'.$darkrai->id.'">',
            '<li class="act-row" wire:key="move-'.$lampent->id.'">',
            '<li class="act-row" wire:key="added-'.CollectionItem::where('card_id', $darkrai->id)->sole()->id.'">',
            '<li class="act-row" wire:key="added-'.CollectionItem::where('card_id', $lampent->id)->sole()->id.'">',
        ]);
});

test('rows link to the card page with a plain link', function () {
    activitySeedFeed();

    $html = $this->get('/carlos/activity')->getContent();
    $href = route('gallery.card', ['username' => 'carlos', 'setTcgdexId' => 'me05', 'localId' => '116']);

    expect(substr_count($html, '<a href="'.$href.'">'))->toBe(2);
});

test('a card without an image still gets its rows: name and amount, and no empty img', function () {
    $user = User::factory()->create(['username' => 'carlos']);
    $collection = Collection::factory()->for($user)->create(['is_public' => true, 'slug' => 'main']);
    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);
    $card = Card::create(['tcgdex_id' => 'me05-001', 'set_id' => $set->id, 'local_id' => '001', 'name' => 'Imageless Mew', 'rarity' => 'Rare']);
    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => 'me05-001', 'variant' => 'normal', 'condition' => 'NM', 'quantity' => 1]);
    foreach ([1 => 1000, 0 => 1200] as $daysAgo => $minor) {
        CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'tcgplayer', 'variant' => 'normal', 'captured_on' => today()->subDays($daysAgo), 'currency' => 'USD', 'market_minor' => $minor]);
    }

    $html = $this->get('/carlos/activity')->assertOk()->getContent();
    preg_match_all('#<li class="act-row"[^>]*>(.*?)</li>#s', $html, $rows);

    expect($rows[1])->toHaveCount(2)
        ->and(array_filter($rows[1], fn (string $row) => str_contains($row, '<img')))->toBe([])
        ->and(array_filter($rows[1], fn (string $row) => str_contains($row, 'Imageless Mew')))->toHaveCount(2)
        ->and(array_column(activityAmounts($html), 1))->toContain('+$2.00 now $12.00')
        ->and(array_filter(array_column(activityAmounts($html), 1), fn (string $amount) => str_contains($amount, '$12.00')))->toHaveCount(2);
});

test('an empty feed keeps the approved copy and still says which moves are left out', function () {
    $user = User::factory()->create(['username' => 'carlos']);
    Collection::factory()->for($user)->create(['is_public' => true, 'slug' => 'main']);

    $html = $this->get('/carlos/activity')->assertOk()->getContent();

    expect(RevampHtml::text('act-sec-t', $html))->toBe('Latest · 0 updates')
        ->and(RevampHtml::text('act-empty-t', $html))->toBe('Nothing yet')
        ->and($html)->toContain('Additions and price movements show up here as they happen.')
        ->and($html)->toContain('Price moves under $1 are not listed.')
        ->and($html)->not->toContain('act-day')
        ->and($html)->not->toContain('act-summary');
});

test('the range tabs are a tablist: the open range is selected and holds the tab stop', function () {
    ownedCardPricedDaysAgo([0 => 300, 2 => 200]);

    $html = $this->get('/carlos/activity?range=3m')->assertOk()->getContent();

    expect($html)->toContain('<div class="act-tabs" role="tablist" aria-label="Collection value range" data-act-tabs>')
        ->and($html)->toContain('<button type="button" role="tab" id="act-tab-3m" class="act-tab" aria-selected="true" tabindex="0" aria-controls="act-chart-panel" wire:click="selectRange(\'3m\')"')
        ->and($html)->toContain('<button type="button" role="tab" id="act-tab-1m" class="act-tab" aria-selected="false" tabindex="-1" aria-controls="act-chart-panel" wire:click="selectRange(\'1m\')"')
        ->and($html)->toContain('role="tabpanel" id="act-chart-panel" aria-labelledby="act-tab-3m"')
        ->and($html)->not->toContain('aria-pressed');
});

test('the chart is drawn on the server, and a rise over the range is green', function () {
    ownedCardPricedDaysAgo([0 => 300, 2 => 200]);

    $html = $this->get('/carlos/activity')->getContent();
    preg_match("#data-points='([^']*)'#", $html, $m);
    $points = json_decode($m[1] ?? '[]', true);
    preg_match('#<div class="act-chart"[^>]*>#', $html, $slider);

    expect($html)->toContain('<small class="act-move act-move--up">+$1.00 · 1 month</small>')
        ->and($html)->toContain('class="act-end act-end--up"')
        ->and($html)->toContain('preserveAspectRatio="none" aria-hidden="true">')
        ->and($html)->toContain('<h2 class="act-k" id="act-cv-h">Collection value · 1 month</h2>')
        ->and($slider[0] ?? '')->toContain('role="slider"')
        ->and($slider[0] ?? '')->toContain('aria-valuemin="0" aria-valuemax="2" aria-valuenow="2"')
        ->and($slider[0] ?? '')->toContain('aria-valuetext="'.DayLabel::of(today()).' · $3.00"')
        ->and($slider[0] ?? '')->toContain('aria-describedby="act-chart-sum"')
        ->and($html)->toContain('<p class="sr-only" id="act-chart-sum">Collection value over 1 month, from $2.00 to $3.00</p>')
        ->and($html)->not->toContain('aria-live')
        ->and($html)->not->toContain('data-act-announce')
        ->and($html)->not->toContain('class="nw-')
        ->and($points)->toHaveCount(3)
        ->and(end($points)['v'])->toBe('$3.00');
});

test('a fall over the range is neutral', function () {
    ownedCardPricedDaysAgo([0 => 200, 2 => 300]);

    $html = $this->get('/carlos/activity')->getContent();

    expect($html)->toContain('<small class="act-move">−$1.00 · 1 month</small>')
        ->and($html)->toContain('class="act-end"')
        ->and($html)->not->toContain('act-move--up')
        ->and($html)->not->toContain('act-end--up');
});

test('the page ships its own script, and neither the landing nor the collection loads it', function () {
    ownedCardPricedDaysAgo([0 => 300, 2 => 200]);
    $manifest = json_decode((string) file_get_contents(public_path('build/manifest.json')), true);
    $script = $manifest['resources/js/activity.js']['file'];

    expect($this->get('/carlos/activity')->getContent())->toContain($script)
        ->and($this->get('/')->getContent())->not->toContain($script)
        ->and($this->get('/carlos')->getContent())->not->toContain($script);
});

test('the chart carries the hooks its script reads', function () {
    ownedCardPricedDaysAgo([0 => 300, 2 => 200]);

    $html = $this->get('/carlos/activity')->assertOk()->getContent();
    preg_match("#data-points='([^']*)'#", $html, $m);
    $points = json_decode($m[1] ?? '[]', true);

    expect($html)->toContain('data-act-tabs')
        ->and($html)->toContain('data-act-history')
        ->and($html)->toContain('<div class="act-chart" tabindex="0" role="slider" aria-label="Value by day"')
        ->and($html)->toContain('data-act-chart')
        ->and($html)->toContain('data-points=\'')
        ->and($html)->toContain('data-act-read aria-hidden="true"')
        ->and($html)->toContain('class="act-cross"')
        ->and($html)->toContain('class="act-dot"')
        ->and($html)->toContain('class="act-cover"')
        ->and(array_keys($points[0] ?? []))->toEqualCanonicalizing(['x', 'y', 'd', 'v']);
});
