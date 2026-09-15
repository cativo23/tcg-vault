<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Catalog\Models\Card;
use App\Modules\Catalog\Models\CardPriceSnapshot;
use App\Modules\Catalog\Models\Set;
use App\Modules\Collection\Models\Collection;
use App\Modules\Collection\Models\CollectionItem;
use App\Modules\Collection\Services\PublicCollection;
use App\Modules\Collection\Services\Valuation;

test('totals are kept per currency and multiplied by owned quantity, USD listed first', function () {
    $user = User::factory()->create(['username' => 'carlos']);
    $collection = Collection::factory()->for($user)->create(['is_public' => true, 'slug' => 'main']);
    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);

    $usd = Card::create(['tcgdex_id' => 'me05-116', 'set_id' => $set->id, 'local_id' => '116', 'name' => 'Mega Darkrai ex']);
    $eur = Card::create(['tcgdex_id' => 'me05-003', 'set_id' => $set->id, 'local_id' => '003', 'name' => 'Fomantis']);
    $unpriced = Card::create(['tcgdex_id' => 'me05-004', 'set_id' => $set->id, 'local_id' => '004', 'name' => 'Lurantis']);

    CardPriceSnapshot::create(['card_id' => $eur->id, 'source' => 'cardmarket', 'variant' => 'default', 'captured_on' => today(), 'currency' => 'EUR', 'market_minor' => 900]);
    CardPriceSnapshot::create(['card_id' => $usd->id, 'source' => 'tcgplayer', 'variant' => 'normal', 'captured_on' => today(), 'currency' => 'USD', 'market_minor' => 1000]);

    // two copies of the USD card across two rows (NM ×2, LP ×1) → 3 × $10.00
    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $usd->id, 'card_tcgdex_id' => 'me05-116', 'condition' => 'NM', 'quantity' => 2]);
    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $usd->id, 'card_tcgdex_id' => 'me05-116', 'condition' => 'LP', 'quantity' => 1]);
    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $eur->id, 'card_tcgdex_id' => 'me05-003', 'condition' => 'NM', 'quantity' => 1]);
    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $unpriced->id, 'card_tcgdex_id' => 'me05-004', 'condition' => 'NM', 'quantity' => 1]);

    $cards = PublicCollection::for($user)->cardsQuery()->get();

    $totals = (new Valuation)->totalsByCurrency($cards);

    expect($totals)->toBe(['USD' => 3000, 'EUR' => 900]);
});

test('a private collection contributes nothing to the public read model', function () {
    $user = User::factory()->create(['username' => 'carlos']);
    $private = Collection::factory()->for($user)->create(['is_public' => false, 'slug' => 'private']);
    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);
    $card = Card::create(['tcgdex_id' => 'me05-116', 'set_id' => $set->id, 'local_id' => '116', 'name' => 'Mega Darkrai ex']);
    CollectionItem::create(['collection_id' => $private->id, 'card_id' => $card->id, 'card_tcgdex_id' => 'me05-116', 'condition' => 'NM', 'quantity' => 1]);

    $public = PublicCollection::for($user);

    expect($public->isEmpty())->toBeTrue()
        ->and($public->cardsQuery()->count())->toBe(0)
        ->and($public->setsQuery()->count())->toBe(0)
        ->and($public->ownsSet($set))->toBeFalse();
});

test('sets are annotated with distinct owned cards, not item rows', function () {
    $user = User::factory()->create(['username' => 'carlos']);
    $collection = Collection::factory()->for($user)->create(['is_public' => true, 'slug' => 'main']);
    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black', 'card_count' => 120]);
    $card = Card::create(['tcgdex_id' => 'me05-116', 'set_id' => $set->id, 'local_id' => '116', 'name' => 'Mega Darkrai ex']);
    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => 'me05-116', 'condition' => 'NM', 'quantity' => 1]);
    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => 'me05-116', 'condition' => 'LP', 'quantity' => 1]);

    $annotated = PublicCollection::for($user)->setsQuery()->first();

    expect($annotated->owned_card_count)->toBe(1)
        ->and($annotated->real_card_count)->toBe(1);
});
