<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Catalog\Models\Card;
use App\Modules\Catalog\Models\Set;
use App\Modules\Collection\Models\Collection;
use App\Modules\Collection\Models\CollectionItem;

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

    $response = $this->get("/carlos/gallery");

    $response->assertOk();
    $response->assertSee('Pitch Black');
    $response->assertSee('2 / 120'); // 2 distinct cards owned, out of the set's total
});

test('a set the user has no cards from does not appear', function () {
    $user = User::factory()->create(['username' => 'carlos']);
    Collection::factory()->for($user)->create(['is_public' => true, 'slug' => 'main']);

    Set::create(['tcgdex_id' => 'untouched', 'name' => 'Never Added']);

    $response = $this->get('/carlos/gallery');

    $response->assertOk();
    $response->assertDontSee('Never Added');
});

test('a nonexistent username 404s, not an empty page', function () {
    $response = $this->get('/nobody-here/gallery');

    $response->assertNotFound();
});

test('a private (non-public) collection contributes nothing to the gallery', function () {
    $user = User::factory()->create(['username' => 'carlos']);
    $collection = Collection::factory()->for($user)->create(['is_public' => false, 'slug' => 'private']);
    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black', 'card_count' => 120]);
    $card = Card::create(['tcgdex_id' => 'me05-116', 'set_id' => $set->id, 'local_id' => '116', 'name' => 'Mega Darkrai ex']);
    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => 'me05-116', 'condition' => 'NM', 'quantity' => 1]);

    $response = $this->get('/carlos/gallery');

    $response->assertOk();
    $response->assertDontSee('Pitch Black');
});

test('the gallery route requires no authentication', function () {
    $user = User::factory()->create(['username' => 'carlos']);
    Collection::factory()->for($user)->create(['is_public' => true, 'slug' => 'main']);

    // Explicitly NOT calling $this->actingAs(...) — a guest must be able to load this.
    $response = $this->get('/carlos/gallery');

    $response->assertOk();
});
