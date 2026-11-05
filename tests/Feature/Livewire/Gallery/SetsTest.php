<?php

declare(strict_types=1);

use App\Livewire\Gallery\Sets;
use App\Models\User;
use App\Modules\Catalog\Models\Card;
use App\Modules\Catalog\Models\CardPriceSnapshot;
use App\Modules\Catalog\Models\Set;
use App\Modules\Collection\Models\Collection;
use App\Modules\Collection\Models\CollectionItem;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/**
 * A demo-shaped collection over two sets. Prismatic Evolutions (older, no
 * logo, no abbreviation, unpriced) is created first, so newest-first is not
 * insertion order. Pitch Black is coded PBL and holds a third catalogue card
 * the collector does not own (imported, missing). Only Mega Darkrai ex is
 * priced: two copies at $12.50.
 */
function seedSetsShelf(): void
{
    $user = User::factory()->create(['username' => 'demo', 'name' => 'Demo']);
    $collection = Collection::factory()->for($user)->create(['is_public' => true, 'slug' => 'main']);
    $prismatic = Set::create(['tcgdex_id' => 'sv08.5', 'name' => 'Prismatic Evolutions', 'series' => 'Scarlet & Violet', 'card_count' => 180, 'released_on' => '2025-01-17']);
    $pitch = Set::create(['tcgdex_id' => 'me05', 'abbreviation' => 'PBL', 'name' => 'Pitch Black', 'series' => 'Mega Evolution', 'card_count' => 120, 'released_on' => '2026-07-17', 'logo_url' => 'https://assets.tcgdex.net/en/me/me05/logo.png']);

    foreach ([
        [$pitch, 'me05-116', '116', 'Mega Darkrai ex', 2],
        [$pitch, 'me05-003', '003', 'Fomantis', 1],
        [$prismatic, 'sv08.5-001', '001', 'Exeggcute', 1],
        [$prismatic, 'sv08.5-002', '002', 'Exeggutor', 1],
    ] as [$set, $tcgdexId, $localId, $name, $quantity]) {
        $card = Card::create(['tcgdex_id' => $tcgdexId, 'set_id' => $set->id, 'local_id' => $localId, 'name' => $name]);
        CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => $tcgdexId, 'variant' => 'normal', 'condition' => 'NM', 'quantity' => $quantity]);
    }
    Card::create(['tcgdex_id' => 'me05-004', 'set_id' => $pitch->id, 'local_id' => '004', 'name' => 'Lurantis']);

    CardPriceSnapshot::create(['card_id' => Card::where('tcgdex_id', 'me05-116')->value('id'), 'source' => 'tcgplayer', 'variant' => 'normal', 'captured_on' => today(), 'currency' => 'USD', 'market_minor' => 1250]);
}

test('lists sets the user has at least one card from, with correct completion counts', function () {
    $user = User::factory()->create(['username' => 'carlos']);
    $collection = Collection::factory()->for($user)->create(['is_public' => true, 'slug' => 'main']);

    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black', 'card_count' => 120]);
    $card = Card::create(['tcgdex_id' => 'me05-116', 'set_id' => $set->id, 'local_id' => '116', 'name' => 'Mega Darkrai ex']);
    $card2 = Card::create(['tcgdex_id' => 'me05-003', 'set_id' => $set->id, 'local_id' => '003', 'name' => 'Fomantis']);

    // two rows for the same card (different condition) must count once
    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => 'me05-116', 'condition' => 'NM', 'quantity' => 1]);
    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => 'me05-116', 'condition' => 'LP', 'quantity' => 1]);
    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $card2->id, 'card_tcgdex_id' => 'me05-003', 'condition' => 'NM', 'quantity' => 1]);

    $response = $this->get('/carlos/sets');

    $response->assertOk();
    $response->assertSee('Pitch Black');
    $response->assertSee('2 / 120'); // 2 distinct cards owned, out of the set's total
});

test('owning even one card out of a huge set never rounds the progress bar down to 0%', function () {
    // A tiny nonzero fraction (1/217 = 0.46%) must not round down to 0%,
    // which would be visually indistinguishable from owning nothing at all.
    $user = User::factory()->create(['username' => 'carlos']);
    $collection = Collection::factory()->for($user)->create(['is_public' => true, 'slug' => 'main']);
    $set = Set::create(['tcgdex_id' => 'me02.5', 'name' => 'Ascended Heroes', 'card_count' => 217]);
    $card = Card::create(['tcgdex_id' => 'me02.5-123', 'set_id' => $set->id, 'local_id' => '123', 'name' => 'Gastly']);
    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => 'me02.5-123', 'condition' => 'NM', 'quantity' => 1]);

    $response = $this->get('/carlos/sets');

    $response->assertOk();
    $response->assertSee('1 / 217');
    $response->assertSee('width: 1%', false);
});

test('a set the user has no cards from does not appear', function () {
    $user = User::factory()->create(['username' => 'carlos']);
    Collection::factory()->for($user)->create(['is_public' => true, 'slug' => 'main']);

    Set::create(['tcgdex_id' => 'untouched', 'name' => 'Never Added']);

    $response = $this->get('/carlos/sets');

    $response->assertOk();
    $response->assertDontSee('Never Added');
});

test('a nonexistent username 404s, not an empty page', function () {
    $response = $this->get('/nobody-here/sets');

    $response->assertNotFound();
});

test('a private (non-public) collection contributes nothing to the gallery', function () {
    $user = User::factory()->create(['username' => 'carlos']);
    $collection = Collection::factory()->for($user)->create(['is_public' => false, 'slug' => 'private']);
    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black', 'card_count' => 120]);
    $card = Card::create(['tcgdex_id' => 'me05-116', 'set_id' => $set->id, 'local_id' => '116', 'name' => 'Mega Darkrai ex']);
    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => 'me05-116', 'condition' => 'NM', 'quantity' => 1]);

    $response = $this->get('/carlos/sets');

    $response->assertOk();
    $response->assertDontSee('Pitch Black');
});

test('the sets route requires no authentication', function () {
    $user = User::factory()->create(['username' => 'carlos']);
    Collection::factory()->for($user)->create(['is_public' => true, 'slug' => 'main']);

    // Explicitly NOT calling $this->actingAs(...) — a guest must be able to load this.
    $response = $this->get('/carlos/sets');

    $response->assertOk();
});

test('falls back to the local card count when tcgdex reports zero', function () {
    $user = User::factory()->create(['username' => 'carlos']);
    $collection = Collection::factory()->for($user)->create(['is_public' => true, 'slug' => 'main']);
    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black', 'card_count' => 0]);
    $owned = Card::create(['tcgdex_id' => 'me05-116', 'set_id' => $set->id, 'local_id' => '116', 'name' => 'Mega Darkrai ex']);
    Card::create(['tcgdex_id' => 'me05-003', 'set_id' => $set->id, 'local_id' => '003', 'name' => 'Fomantis']);
    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $owned->id, 'card_tcgdex_id' => 'me05-116', 'condition' => 'NM', 'quantity' => 1]);

    $this->get('/carlos/sets')
        ->assertOk()
        ->assertSee('1 / 2')
        ->assertDontSee('1 / 0');
});

test('the sets page reads the same number of queries however many sets it lists', function () {
    $user = User::factory()->create(['username' => 'demo', 'name' => 'Demo']);
    $collection = Collection::factory()->for($user)->create(['is_public' => true, 'slug' => 'main']);
    $addSets = function (int $from, int $to) use ($collection): void {
        foreach (range($from, $to) as $n) {
            $set = Set::create(['tcgdex_id' => "s{$n}", 'name' => "Set {$n}", 'card_count' => 10, 'released_on' => today()->subYears($n), 'logo_url' => "https://assets.tcgdex.net/en/s/s{$n}/logo.png"]);
            foreach (['001', '002'] as $localId) {
                $card = Card::create(['tcgdex_id' => "s{$n}-{$localId}", 'set_id' => $set->id, 'local_id' => $localId, 'name' => "Card {$n}-{$localId}"]);
                CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'tcgplayer', 'variant' => 'normal', 'captured_on' => today(), 'currency' => 'USD', 'market_minor' => 100 * $n]);
                CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => "s{$n}-{$localId}", 'variant' => 'normal', 'condition' => 'NM', 'quantity' => 1]);
            }
            // Imported but not owned: its number must reach the page without a query of its own.
            Card::create(['tcgdex_id' => "s{$n}-003", 'set_id' => $set->id, 'local_id' => '003', 'name' => "Card {$n}-003"]);
        }
    };
    // Cold both times (cache flushed), so the deciding price-row query is counted on each side.
    $queriesFor = function (): int {
        Cache::flush();
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->get('/demo/sets')->assertOk();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    };

    $addSets(1, 2);
    // Warm-up: the old layout resolves a scoped settings object (one `settings` read) on the first request
    // of a test and reuses it afterwards, which would make the first measured request one query heavier.
    // Cache::flush() inside $queriesFor still keeps the deciding-row load cold.
    $this->get('/demo/sets')->assertOk();
    $few = $queriesFor();
    $addSets(3, 8);
    $many = $queriesFor();

    expect($few)->toBe(7)
        ->and($many)->toBe($few);
});

test('the page gets each set\'s strip as runs, from the catalogue numbers in the sets query and the cards it already loads', function () {
    seedSetsShelf();
    $ids = Set::pluck('id', 'tcgdex_id');

    $strips = Livewire::test(Sets::class, ['username' => 'demo'])->viewData('strips');

    expect($strips)->toBeInstanceOf(SupportCollection::class)
        ->and($strips->get($ids['me05']))->toBe([
            ['state' => 'owned', 'left' => 1.6667, 'width' => 0.8333],
            ['state' => 'missing', 'left' => 2.5, 'width' => 0.8333],
            ['state' => 'owned', 'left' => 95.8333, 'width' => 0.8333],
        ])
        ->and($strips->get($ids['sv08.5']))->toBe([['state' => 'owned', 'left' => 0.0, 'width' => 1.1111]]);
});

test('sets released on the same day are listed by name', function () {
    $user = User::factory()->create(['username' => 'demo', 'name' => 'Demo']);
    $collection = Collection::factory()->for($user)->create(['is_public' => true, 'slug' => 'main']);
    foreach (['Beta', 'Alpha'] as $name) {
        $set = Set::create(['tcgdex_id' => strtolower($name), 'name' => $name, 'card_count' => 10, 'released_on' => '2025-01-17']);
        $card = Card::create(['tcgdex_id' => strtolower($name).'-001', 'set_id' => $set->id, 'local_id' => '001', 'name' => "{$name} card"]);
        CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => strtolower($name).'-001', 'variant' => 'normal', 'condition' => 'NM', 'quantity' => 1]);
    }

    $sets = Livewire::test(Sets::class, ['username' => 'demo'])->viewData('sets');

    expect($sets->pluck('name')->all())->toBe(['Alpha', 'Beta']);
});
