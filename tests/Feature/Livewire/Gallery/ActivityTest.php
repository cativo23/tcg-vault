<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Catalog\Models\Card;
use App\Modules\Catalog\Models\CardPriceSnapshot;
use App\Modules\Catalog\Models\Set;
use App\Modules\Collection\Models\Collection;
use App\Modules\Collection\Models\CollectionItem;

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
    // +$0.02. The normal print moved +$1.00 over the same two days.
    // Running the card-level chain reports the normal print's move for a
    // card no normal copy of which is owned.
    $user = User::factory()->create(['username' => 'carlos']);
    $collection = Collection::factory()->for($user)->create(['is_public' => true, 'slug' => 'main']);
    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);
    $card = Card::create(['tcgdex_id' => 'me05-001', 'set_id' => $set->id, 'local_id' => '001', 'name' => 'Tropius', 'variants' => ['normal' => true, 'reverse' => true]]);
    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => 'me05-001', 'condition' => 'NM', 'quantity' => 1, 'variant' => 'reverse-holofoil']);

    CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'tcgplayer', 'variant' => 'normal', 'captured_on' => today()->subDay(), 'currency' => 'USD', 'market_minor' => 100]);
    CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'tcgplayer', 'variant' => 'reverse-holofoil', 'captured_on' => today()->subDay(), 'currency' => 'USD', 'market_minor' => 10]);
    CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'tcgplayer', 'variant' => 'normal', 'captured_on' => today(), 'currency' => 'USD', 'market_minor' => 200]);
    CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'tcgplayer', 'variant' => 'reverse-holofoil', 'captured_on' => today(), 'currency' => 'USD', 'market_minor' => 12]);

    $response = $this->get('/carlos/activity');

    $response->assertOk();
    $response->assertSee('+$0.02');
    $response->assertDontSee('+$1.00');
});
