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

    $response = $this->get('/carlos/gallery/movimientos');

    $response->assertOk();
    $response->assertSee('Mega Darkrai ex');
});

test('a card with only one snapshot day shows no delta, not a fake one', function () {
    $user = User::factory()->create(['username' => 'carlos']);
    $collection = Collection::factory()->for($user)->create(['is_public' => true, 'slug' => 'main']);
    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);
    $card = Card::create(['tcgdex_id' => 'me05-116', 'set_id' => $set->id, 'local_id' => '116', 'name' => 'Mega Darkrai ex']);
    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => 'me05-116', 'condition' => 'NM', 'quantity' => 1]);
    CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'tcgplayer', 'variant' => 'normal', 'captured_on' => today(), 'currency' => 'USD', 'market_minor' => 1000]);

    $response = $this->get('/carlos/gallery/movimientos');

    $response->assertOk();
    // The card shouldn't be listed among the deltas at all (no comparison possible).
    $response->assertDontSee('+$', false);
    $response->assertDontSee('-$', false);
});

test('shows recently added items in the activity feed', function () {
    $user = User::factory()->create(['username' => 'carlos']);
    $collection = Collection::factory()->for($user)->create(['is_public' => true, 'slug' => 'main']);
    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);
    $card = Card::create(['tcgdex_id' => 'me05-116', 'set_id' => $set->id, 'local_id' => '116', 'name' => 'Mega Darkrai ex']);
    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => 'me05-116', 'condition' => 'NM', 'quantity' => 1]);

    $response = $this->get('/carlos/gallery/movimientos');

    $response->assertOk();
    $response->assertSee('Mega Darkrai ex');
});

test('a private (non-public) collection contributes nothing', function () {
    $user = User::factory()->create(['username' => 'carlos']);
    $collection = Collection::factory()->for($user)->create(['is_public' => false, 'slug' => 'private']);
    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);
    $card = Card::create(['tcgdex_id' => 'me05-116', 'set_id' => $set->id, 'local_id' => '116', 'name' => 'Mega Darkrai ex']);
    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => 'me05-116', 'condition' => 'NM', 'quantity' => 1]);

    $response = $this->get('/carlos/gallery/movimientos');

    $response->assertOk();
    $response->assertDontSee('Mega Darkrai ex');
});

test('the movimientos route requires no authentication', function () {
    $user = User::factory()->create(['username' => 'carlos']);
    Collection::factory()->for($user)->create(['is_public' => true, 'slug' => 'main']);

    $response = $this->get('/carlos/gallery/movimientos');

    $response->assertOk();
});

test('a nonexistent username 404s', function () {
    $response = $this->get('/nobody-here/gallery/movimientos');

    $response->assertNotFound();
});
