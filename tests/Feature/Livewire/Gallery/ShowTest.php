<?php

declare(strict_types=1);

use App\Livewire\Gallery\Show;
use App\Models\User;
use App\Modules\Catalog\Models\Card;
use App\Modules\Catalog\Models\CardPriceSnapshot;
use App\Modules\Catalog\Models\Set;
use App\Modules\Collection\Models\Collection;
use App\Modules\Collection\Models\CollectionItem;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

test('shows only owned cards by default, computes stats from the whole set', function () {
    $user = User::factory()->create(['username' => 'carlos']);
    $collection = Collection::factory()->for($user)->create(['is_public' => true, 'slug' => 'main']);

    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black', 'card_count' => 2]);
    $owned = Card::create(['tcgdex_id' => 'me05-116', 'set_id' => $set->id, 'local_id' => '116', 'name' => 'Mega Darkrai ex']);
    $notOwned = Card::create(['tcgdex_id' => 'me05-003', 'set_id' => $set->id, 'local_id' => '003', 'name' => 'Fomantis']);

    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $owned->id, 'card_tcgdex_id' => 'me05-116', 'condition' => 'NM', 'quantity' => 1]);

    CardPriceSnapshot::create(['card_id' => $owned->id, 'source' => 'tcgplayer', 'variant' => 'normal', 'captured_on' => today(), 'currency' => 'USD', 'market_minor' => 5000]);
    CardPriceSnapshot::create(['card_id' => $notOwned->id, 'source' => 'tcgplayer', 'variant' => 'normal', 'captured_on' => today(), 'currency' => 'USD', 'market_minor' => 1000]);

    $response = $this->get('/carlos/me05');

    $response->assertOk();
    $response->assertSee('Mega Darkrai ex'); // owned, shown
    $response->assertDontSee('Fomantis'); // not owned — hidden by default (missing cards are opt-in)
    $response->assertSee('Collected</div>', escape: false); // stats still cover the whole set, unfiltered
});

test('owning even one card out of a huge set never rounds the progress bar down to 0%', function () {
    // Same rounding bug as Sets.php, on the set-detail page's own progress
    // bar/percentage text.
    $user = User::factory()->create(['username' => 'carlos']);
    $collection = Collection::factory()->for($user)->create(['is_public' => true, 'slug' => 'main']);
    $set = Set::create(['tcgdex_id' => 'me02.5', 'name' => 'Ascended Heroes', 'card_count' => 217]);
    $card = Card::create(['tcgdex_id' => 'me02.5-123', 'set_id' => $set->id, 'local_id' => '123', 'name' => 'Gastly']);
    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => 'me02.5-123', 'condition' => 'NM', 'quantity' => 1]);

    $response = $this->get('/carlos/me02.5');

    $response->assertOk();
    $response->assertSee('1', false);
    $response->assertSee('of 217 · 1%', false);
    $response->assertSee('width: 1%', false);
});

test('toggling "show missing" reveals cards the collector does not own', function () {
    $user = User::factory()->create(['username' => 'carlos']);
    $collection = Collection::factory()->for($user)->create(['is_public' => true, 'slug' => 'main']);

    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black', 'card_count' => 2]);
    $owned = Card::create(['tcgdex_id' => 'me05-116', 'set_id' => $set->id, 'local_id' => '116', 'name' => 'Mega Darkrai ex']);
    Card::create(['tcgdex_id' => 'me05-003', 'set_id' => $set->id, 'local_id' => '003', 'name' => 'Fomantis']);

    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $owned->id, 'card_tcgdex_id' => 'me05-116', 'condition' => 'NM', 'quantity' => 1]);

    Livewire::test(Show::class, ['username' => 'carlos', 'setTcgdexId' => 'me05'])
        ->assertDontSee('Fomantis')
        ->call('toggleMissing')
        ->assertSee('Fomantis')
        ->call('toggleMissing')
        ->assertDontSee('Fomantis');
});

test('a private (non-public) collection contributes nothing to the set-detail screen', function () {
    $user = User::factory()->create(['username' => 'carlos']);
    $collection = Collection::factory()->for($user)->create(['is_public' => false, 'slug' => 'private']);
    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black', 'card_count' => 1]);
    $card = Card::create(['tcgdex_id' => 'me05-116', 'set_id' => $set->id, 'local_id' => '116', 'name' => 'Mega Darkrai ex']);
    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => 'me05-116', 'condition' => 'NM', 'quantity' => 1, 'photo_path' => 'secret.jpg']);

    $response = $this->get('/carlos/me05');

    // The set only exists in this user's gallery via a public collection
    // — with none, the same existence-gate that hides an untouched set
    // must also hide a set touched only through a private one, and the
    // private item's photo must never leak into the response either way.
    $response->assertNotFound();
    $response->assertDontSee('secret.jpg', false);
});

test('the set-detail route requires no authentication', function () {
    $user = User::factory()->create(['username' => 'carlos']);
    $collection = Collection::factory()->for($user)->create(['is_public' => true, 'slug' => 'main']);
    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black', 'card_count' => 1]);
    $card = Card::create(['tcgdex_id' => 'me05-116', 'set_id' => $set->id, 'local_id' => '116', 'name' => 'Mega Darkrai ex']);
    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => 'me05-116', 'condition' => 'NM', 'quantity' => 1]);

    // Explicitly NOT calling $this->actingAs(...) — a guest must be able to load this.
    $response = $this->get('/carlos/me05');

    $response->assertOk();
});

test('a set that exists but the user has never touched 404s', function () {
    $user = User::factory()->create(['username' => 'carlos']);
    Collection::factory()->for($user)->create(['is_public' => true, 'slug' => 'main']);
    Set::create(['tcgdex_id' => 'untouched', 'name' => 'Never Added']);

    $response = $this->get('/carlos/untouched');

    $response->assertNotFound();
});

test('uses the users own photo over official art when owned and photographed', function () {
    $user = User::factory()->create(['username' => 'carlos']);
    $collection = Collection::factory()->for($user)->create(['is_public' => true, 'slug' => 'main']);
    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black', 'card_count' => 1]);
    $card = Card::create(['tcgdex_id' => 'me05-116', 'set_id' => $set->id, 'local_id' => '116', 'name' => 'Mega Darkrai ex', 'official_image_url' => 'https://official.example/card.webp']);
    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => 'me05-116', 'condition' => 'NM', 'quantity' => 1, 'photo_path' => 'my-photo.jpg']);

    $response = $this->get('/carlos/me05');

    $response->assertSee(Storage::disk('collection-photos')->url('my-photo.jpg'), false);
    $response->assertDontSee('https://official.example/card.webp', false);
});

test('search filters the card grid by name', function () {
    $user = User::factory()->create(['username' => 'carlos']);
    $collection = Collection::factory()->for($user)->create(['is_public' => true, 'slug' => 'main']);
    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black', 'card_count' => 2]);
    $card1 = Card::create(['tcgdex_id' => 'me05-116', 'set_id' => $set->id, 'local_id' => '116', 'name' => 'Mega Darkrai ex']);
    $card2 = Card::create(['tcgdex_id' => 'me05-003', 'set_id' => $set->id, 'local_id' => '003', 'name' => 'Fomantis']);

    // mount()'s existence-gate (borrowed from Task 4) only shows a set the
    // user has touched — without this, the set 404s before search is ever
    // exercised.
    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $card1->id, 'card_tcgdex_id' => 'me05-116', 'condition' => 'NM', 'quantity' => 1]);

    Livewire::test(Show::class, ['username' => 'carlos', 'setTcgdexId' => 'me05'])
        ->set('search', 'Darkrai')
        ->assertSee('Mega Darkrai ex')
        ->assertDontSee('Fomantis');
});

test('search is case-insensitive', function () {
    $user = User::factory()->create(['username' => 'carlos']);
    $collection = Collection::factory()->for($user)->create(['is_public' => true, 'slug' => 'main']);
    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black', 'card_count' => 1]);
    $card = Card::create(['tcgdex_id' => 'me05-003', 'set_id' => $set->id, 'local_id' => '003', 'name' => 'Fomantis']);
    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => 'me05-003', 'condition' => 'NM', 'quantity' => 1]);

    // Postgres' LIKE is case-sensitive by default — a lowercase search
    // must still find a card whose stored name is capitalized.
    Livewire::test(Show::class, ['username' => 'carlos', 'setTcgdexId' => 'me05'])
        ->set('search', 'fomantis')
        ->assertSee('Fomantis');
});

test('rarity filter only shows cards of the selected rarity', function () {
    $user = User::factory()->create(['username' => 'carlos']);
    $collection = Collection::factory()->for($user)->create(['is_public' => true, 'slug' => 'main']);
    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black', 'card_count' => 2]);
    $card1 = Card::create(['tcgdex_id' => 'me05-116', 'set_id' => $set->id, 'local_id' => '116', 'name' => 'Mega Darkrai ex', 'rarity' => 'SIR']);
    Card::create(['tcgdex_id' => 'me05-003', 'set_id' => $set->id, 'local_id' => '003', 'name' => 'Fomantis', 'rarity' => 'Common']);

    // Same existence-gate note as the search test above.
    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $card1->id, 'card_tcgdex_id' => 'me05-116', 'condition' => 'NM', 'quantity' => 1]);

    Livewire::test(Show::class, ['username' => 'carlos', 'setTcgdexId' => 'me05'])
        ->set('rarityFilter', 'SIR')
        ->assertSee('Mega Darkrai ex')
        ->assertDontSee('Fomantis');
});

test('tcgdex\'s literal "None" rarity never becomes a selectable filter option', function () {
    // Regression: whereNotNull('rarity') only excludes a real NULL —
    // tcgdex's literal string "None" (promos/unrated prints) survived
    // as a selectable-but-blank-labeled option (Rarity::label('None')
    // is already ''), and picking it matched only cards literally rated
    // "None", hiding every properly-rated card in the set.
    $user = User::factory()->create(['username' => 'carlos']);
    $collection = Collection::factory()->for($user)->create(['is_public' => true, 'slug' => 'main']);
    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black', 'card_count' => 3]);
    $darkrai = Card::create(['tcgdex_id' => 'me05-116', 'set_id' => $set->id, 'local_id' => '116', 'name' => 'Mega Darkrai ex', 'rarity' => 'Special illustration rare']);
    Card::create(['tcgdex_id' => 'me05-003', 'set_id' => $set->id, 'local_id' => '003', 'name' => 'Fomantis', 'rarity' => 'Common']);
    Card::create(['tcgdex_id' => 'me05-200', 'set_id' => $set->id, 'local_id' => '200', 'name' => 'Pikachu on the Ball', 'rarity' => 'None']);

    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $darkrai->id, 'card_tcgdex_id' => 'me05-116', 'condition' => 'NM', 'quantity' => 1]);

    $response = $this->get('/carlos/me05');

    $response->assertOk();
    $response->assertSee('value="Special illustration rare"', false);
    $response->assertSee('value="Common"', false);
    $response->assertDontSee('value="None"', false);
    $response->assertDontSee('value="none"', false);
});
