<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Catalog\Models\Card;
use App\Modules\Catalog\Models\CardPriceSnapshot;
use App\Modules\Catalog\Models\Set;
use App\Modules\Collection\Models\Collection;
use App\Modules\Collection\Models\CollectionItem;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

test('the collection index lists the authenticated users items', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);
    $card = Card::create(['tcgdex_id' => 'me05-116', 'set_id' => $set->id, 'local_id' => '116', 'name' => 'Mega Darkrai ex']);
    $collection = Collection::factory()->for($user)->create(['name' => 'Main', 'slug' => 'main']);
    CollectionItem::create([
        'collection_id' => $collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => 'me05-116',
        'condition' => 'NM', 'quantity' => 1,
    ]);

    Livewire::test(\App\Livewire\Admin\CollectionItems::class)
        ->assertSee('Mega Darkrai ex')
        ->assertSee('NM');
});

test('the value column prices each row at its OWN variant, not the card-level default for every row', function () {
    // A normal and a reverse-holofoil copy of the same card must not
    // show the same price — the cell must price each row by its own
    // variant, not by re-resolving the card-level default.
    $user = User::factory()->create();
    $this->actingAs($user);

    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);
    $wailmer = Card::create(['tcgdex_id' => 'me05-015', 'set_id' => $set->id, 'local_id' => '015', 'name' => 'Wailmer']);
    CardPriceSnapshot::create(['card_id' => $wailmer->id, 'source' => 'tcgplayer', 'variant' => 'normal', 'captured_on' => today(), 'currency' => 'USD', 'market_minor' => 16]);
    CardPriceSnapshot::create(['card_id' => $wailmer->id, 'source' => 'tcgplayer', 'variant' => 'reverse-holofoil', 'captured_on' => today(), 'currency' => 'USD', 'market_minor' => 27]);

    $collection = Collection::factory()->for($user)->create(['name' => 'Main', 'slug' => 'main']);
    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $wailmer->id, 'card_tcgdex_id' => 'me05-015', 'variant' => 'normal', 'condition' => 'NM', 'quantity' => 1]);
    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $wailmer->id, 'card_tcgdex_id' => 'me05-015', 'variant' => 'reverse-holofoil', 'condition' => 'NM', 'quantity' => 1]);

    Livewire::test(\App\Livewire\Admin\CollectionItems::class)
        ->assertSee('$0.16')
        ->assertSee('$0.27');
});

test('an admin can update an items notes', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);
    $card = Card::create(['tcgdex_id' => 'me05-116', 'set_id' => $set->id, 'local_id' => '116', 'name' => 'Mega Darkrai ex']);
    $collection = Collection::factory()->for($user)->create(['name' => 'Main', 'slug' => 'main']);
    $item = CollectionItem::create([
        'collection_id' => $collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => 'me05-116',
        'condition' => 'NM', 'quantity' => 1,
    ]);

    Livewire::test(\App\Livewire\Admin\CollectionItems::class)
        ->call('startEditingNotes', $item->id)
        ->set('editingNotes', 'Bought at a con')
        ->call('saveNotes');

    expect($item->fresh()->notes)->toBe('Bought at a con');
});

test('an item with a photo shows a thumbnail in the rendered view', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);
    $card = Card::create(['tcgdex_id' => 'me05-116', 'set_id' => $set->id, 'local_id' => '116', 'name' => 'Mega Darkrai ex']);
    $collection = Collection::factory()->for($user)->create(['name' => 'Main', 'slug' => 'main']);
    CollectionItem::create([
        'collection_id' => $collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => 'me05-116',
        'condition' => 'NM', 'quantity' => 1, 'photo_path' => 'card.jpg',
    ]);

    Livewire::test(\App\Livewire\Admin\CollectionItems::class)
        ->assertSee(Storage::disk('collection-photos')->url('card.jpg'), false);
});

test('an item with no photo renders no image tag for it', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);
    $card = Card::create(['tcgdex_id' => 'me05-116', 'set_id' => $set->id, 'local_id' => '116', 'name' => 'Mega Darkrai ex']);
    $collection = Collection::factory()->for($user)->create(['name' => 'Main', 'slug' => 'main']);
    CollectionItem::create([
        'collection_id' => $collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => 'me05-116',
        'condition' => 'NM', 'quantity' => 1,
    ]);

    Livewire::test(\App\Livewire\Admin\CollectionItems::class)
        ->assertDontSee('<img', false);
});

test('deleting an item removes its stored photo from disk', function () {
    Storage::fake('collection-photos');
    Storage::disk('collection-photos')->put('card.jpg', 'fake-image-bytes');

    $user = User::factory()->create();
    $this->actingAs($user);

    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);
    $card = Card::create(['tcgdex_id' => 'me05-116', 'set_id' => $set->id, 'local_id' => '116', 'name' => 'Mega Darkrai ex']);
    $collection = Collection::factory()->for($user)->create(['name' => 'Main', 'slug' => 'main']);
    $item = CollectionItem::create([
        'collection_id' => $collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => 'me05-116',
        'condition' => 'NM', 'quantity' => 1, 'photo_path' => 'card.jpg',
    ]);

    Livewire::test(\App\Livewire\Admin\CollectionItems::class)
        ->call('delete', $item->id);

    Storage::disk('collection-photos')->assertMissing('card.jpg');
});

test('deleting an item with an already-missing photo file does not throw', function () {
    Storage::fake('collection-photos');

    $user = User::factory()->create();
    $this->actingAs($user);

    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);
    $card = Card::create(['tcgdex_id' => 'me05-116', 'set_id' => $set->id, 'local_id' => '116', 'name' => 'Mega Darkrai ex']);
    $collection = Collection::factory()->for($user)->create(['name' => 'Main', 'slug' => 'main']);
    $item = CollectionItem::create([
        'collection_id' => $collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => 'me05-116',
        'condition' => 'NM', 'quantity' => 1, 'photo_path' => 'already-gone.jpg',
    ]);

    Livewire::test(\App\Livewire\Admin\CollectionItems::class)
        ->call('delete', $item->id);

    expect(CollectionItem::find($item->id))->toBeNull();
});

test('an admin can delete an item', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);
    $card = Card::create(['tcgdex_id' => 'me05-116', 'set_id' => $set->id, 'local_id' => '116', 'name' => 'Mega Darkrai ex']);
    $collection = Collection::factory()->for($user)->create(['name' => 'Main', 'slug' => 'main']);
    $item = CollectionItem::create([
        'collection_id' => $collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => 'me05-116',
        'condition' => 'NM', 'quantity' => 1,
    ]);

    Livewire::test(\App\Livewire\Admin\CollectionItems::class)
        ->call('delete', $item->id);

    expect(CollectionItem::find($item->id))->toBeNull();
});

test('a user only sees their own items, never another users', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $this->actingAs($user);

    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);
    $card = Card::create(['tcgdex_id' => 'me05-116', 'set_id' => $set->id, 'local_id' => '116', 'name' => 'Mega Darkrai ex']);
    $otherCollection = Collection::factory()->for($otherUser)->create(['name' => 'Not mine', 'slug' => 'not-mine']);
    CollectionItem::create([
        'collection_id' => $otherCollection->id, 'card_id' => $card->id, 'card_tcgdex_id' => 'me05-116',
        'condition' => 'NM', 'quantity' => 1,
    ]);

    Livewire::test(\App\Livewire\Admin\CollectionItems::class)
        ->assertDontSee('Mega Darkrai ex');
});

test('a user cannot delete another users item by guessing its ID (IDOR)', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $this->actingAs($user);

    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);
    $card = Card::create(['tcgdex_id' => 'me05-116', 'set_id' => $set->id, 'local_id' => '116', 'name' => 'Mega Darkrai ex']);
    $otherCollection = Collection::factory()->for($otherUser)->create(['name' => 'Not mine', 'slug' => 'not-mine']);
    $otherItem = CollectionItem::create([
        'collection_id' => $otherCollection->id, 'card_id' => $card->id, 'card_tcgdex_id' => 'me05-116',
        'condition' => 'NM', 'quantity' => 1,
    ]);

    Livewire::test(\App\Livewire\Admin\CollectionItems::class)
        ->call('delete', $otherItem->id)
        ->assertStatus(404);

    expect(CollectionItem::find($otherItem->id))->not->toBeNull();
});

test('a user cannot edit another users item notes by guessing its ID (IDOR)', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $this->actingAs($user);

    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);
    $card = Card::create(['tcgdex_id' => 'me05-116', 'set_id' => $set->id, 'local_id' => '116', 'name' => 'Mega Darkrai ex']);
    $otherCollection = Collection::factory()->for($otherUser)->create(['name' => 'Not mine', 'slug' => 'not-mine']);
    $otherItem = CollectionItem::create([
        'collection_id' => $otherCollection->id, 'card_id' => $card->id, 'card_tcgdex_id' => 'me05-116',
        'condition' => 'NM', 'quantity' => 1, 'notes' => 'original',
    ]);

    Livewire::test(\App\Livewire\Admin\CollectionItems::class)
        ->call('startEditingNotes', $otherItem->id)
        ->assertStatus(404);

    expect($otherItem->fresh()->notes)->toBe('original');
});

test('an admin can edit an items full details', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);
    $card = Card::create(['tcgdex_id' => 'me05-116', 'set_id' => $set->id, 'local_id' => '116', 'name' => 'Mega Darkrai ex']);
    $collection = Collection::factory()->for($user)->create(['name' => 'Main', 'slug' => 'main']);
    $item = CollectionItem::create([
        'collection_id' => $collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => 'me05-116',
        'condition' => 'NM', 'quantity' => 1,
    ]);

    Livewire::test(\App\Livewire\Admin\CollectionItems::class)
        ->call('startEditingItem', $item->id)
        ->set('editingCondition', 'LP')
        ->set('editingQuantity', 3)
        ->set('editingVariant', 'holofoil')
        ->set('editingGradeCompany', 'PSA')
        ->set('editingGradeValue', '9')
        ->call('saveItem');

    $fresh = $item->fresh();
    expect($fresh->condition)->toBe('LP');
    expect($fresh->quantity)->toBe(3);
    expect($fresh->variant)->toBe('holofoil');
    expect($fresh->grade_company)->toBe('PSA');
    expect($fresh->grade_value)->toBe('9');
});

test('editing an items condition rejects a value outside the allowed set', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);
    $card = Card::create(['tcgdex_id' => 'me05-116', 'set_id' => $set->id, 'local_id' => '116', 'name' => 'Mega Darkrai ex']);
    $collection = Collection::factory()->for($user)->create(['name' => 'Main', 'slug' => 'main']);
    $item = CollectionItem::create([
        'collection_id' => $collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => 'me05-116',
        'condition' => 'NM', 'quantity' => 1,
    ]);

    Livewire::test(\App\Livewire\Admin\CollectionItems::class)
        ->call('startEditingItem', $item->id)
        ->set('editingCondition', 'NOT_A_REAL_CONDITION')
        ->call('saveItem')
        ->assertHasErrors('editingCondition');

    expect($item->fresh()->condition)->toBe('NM');
});

test('editing an items variant only accepts the known values', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);
    $card = Card::create(['tcgdex_id' => 'me05-116', 'set_id' => $set->id, 'local_id' => '116', 'name' => 'Mega Darkrai ex']);
    $collection = Collection::factory()->for($user)->create(['name' => 'Main', 'slug' => 'main']);
    $item = CollectionItem::create([
        'collection_id' => $collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => 'me05-116',
        'condition' => 'NM', 'quantity' => 1,
    ]);

    Livewire::test(\App\Livewire\Admin\CollectionItems::class)
        ->call('startEditingItem', $item->id)
        ->set('editingVariant', 'reverse-holofoil')
        ->call('saveItem');

    expect($item->fresh()->variant)->toBe('reverse-holofoil');
});

test('an invalid variant value is rejected', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);
    $card = Card::create(['tcgdex_id' => 'me05-116', 'set_id' => $set->id, 'local_id' => '116', 'name' => 'Mega Darkrai ex']);
    $collection = Collection::factory()->for($user)->create(['name' => 'Main', 'slug' => 'main']);
    $item = CollectionItem::create([
        'collection_id' => $collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => 'me05-116',
        'condition' => 'NM', 'quantity' => 1, 'variant' => 'normal',
    ]);

    Livewire::test(\App\Livewire\Admin\CollectionItems::class)
        ->call('startEditingItem', $item->id)
        ->set('editingVariant', 'first-edition-ultra-rainbow-secret')
        ->call('saveItem')
        ->assertHasErrors('editingVariant');

    expect($item->fresh()->variant)->toBe('normal');
});

test('the variant dropdown only offers the variants that actually occur for that card', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);
    $card = Card::create(['tcgdex_id' => 'me05-116', 'set_id' => $set->id, 'local_id' => '116', 'name' => 'Mega Darkrai ex']);
    $collection = Collection::factory()->for($user)->create(['name' => 'Main', 'slug' => 'main']);
    $item = CollectionItem::create([
        'collection_id' => $collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => 'me05-116',
        'condition' => 'NM', 'quantity' => 1,
    ]);

    foreach (['holofoil', 'reverse-holofoil'] as $variant) {
        CardPriceSnapshot::create([
            'card_id' => $card->id, 'source' => 'tcgplayer', 'variant' => $variant,
            'captured_on' => now()->toDateString(), 'currency' => 'USD',
            'market_minor' => 100, 'low_minor' => 80, 'trend_minor' => 90,
        ]);
    }

    $html = Livewire::test(\App\Livewire\Admin\CollectionItems::class)
        ->call('startEditingItem', $item->id)
        ->html();

    // Scoped to the modal's own <select> — the toolbar's variant FILTER
    // dropdown (added in Task 2) statically lists all three variant
    // values on every render, so an unscoped assertDontSee would false-
    // positive on that unrelated markup.
    preg_match('/<select wire:model="editingVariant".*?<\/select>/s', $html, $matches);
    $modalSelect = $matches[0] ?? '';

    expect($modalSelect)->toContain('value="holofoil"')
        ->toContain('value="reverse-holofoil"')
        ->not->toContain('value="normal"');
});

test('when a card has exactly one real variant it is pre-selected instead of left blank', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);
    $card = Card::create(['tcgdex_id' => 'me05-116', 'set_id' => $set->id, 'local_id' => '116', 'name' => 'Mega Darkrai ex']);
    $collection = Collection::factory()->for($user)->create(['name' => 'Main', 'slug' => 'main']);
    $item = CollectionItem::create([
        'collection_id' => $collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => 'me05-116',
        'condition' => 'NM', 'quantity' => 1,
    ]);

    CardPriceSnapshot::create([
        'card_id' => $card->id, 'source' => 'tcgplayer', 'variant' => 'holofoil',
        'captured_on' => now()->toDateString(), 'currency' => 'USD',
        'market_minor' => 100, 'low_minor' => 80, 'trend_minor' => 90,
    ]);

    Livewire::test(\App\Livewire\Admin\CollectionItems::class)
        ->call('startEditingItem', $item->id)
        ->assertSet('editingAvailableVariants', ['holofoil'])
        ->assertSet('editingVariant', 'holofoil');
});

test('a single available variant never overrides an items existing explicit variant', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);
    $card = Card::create(['tcgdex_id' => 'me05-116', 'set_id' => $set->id, 'local_id' => '116', 'name' => 'Mega Darkrai ex']);
    $collection = Collection::factory()->for($user)->create(['name' => 'Main', 'slug' => 'main']);
    $item = CollectionItem::create([
        'collection_id' => $collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => 'me05-116',
        'condition' => 'NM', 'quantity' => 1, 'variant' => 'normal',
    ]);

    CardPriceSnapshot::create([
        'card_id' => $card->id, 'source' => 'tcgplayer', 'variant' => 'holofoil',
        'captured_on' => now()->toDateString(), 'currency' => 'USD',
        'market_minor' => 100, 'low_minor' => 80, 'trend_minor' => 90,
    ]);

    Livewire::test(\App\Livewire\Admin\CollectionItems::class)
        ->call('startEditingItem', $item->id)
        ->assertSet('editingVariant', 'normal');
});

test('when a card has no synced pricing data the variant dropdown falls back to just the items current value', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);
    $card = Card::create(['tcgdex_id' => 'me05-116', 'set_id' => $set->id, 'local_id' => '116', 'name' => 'Mega Darkrai ex']);
    $collection = Collection::factory()->for($user)->create(['name' => 'Main', 'slug' => 'main']);
    $item = CollectionItem::create([
        'collection_id' => $collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => 'me05-116',
        'condition' => 'NM', 'quantity' => 1, 'variant' => 'normal',
    ]);

    $html = Livewire::test(\App\Livewire\Admin\CollectionItems::class)
        ->call('startEditingItem', $item->id)
        ->html();

    // Scoped to the modal's own <select> — see the identical note above.
    preg_match('/<select wire:model="editingVariant".*?<\/select>/s', $html, $matches);
    $modalSelect = $matches[0] ?? '';

    expect($modalSelect)->toContain('value="normal"')
        ->not->toContain('value="holofoil"')
        ->not->toContain('value="reverse-holofoil"');
});

test('the variant dropdown uses the card\'s own print flags, not just synced pricing coverage', function () {
    // A card that is normal + reverse-holofoil (no straight holo), owned
    // as "normal" but only synced with a cardmarket 'holofoil' price row
    // (the importer mislabels cardmarket's reverse-holo price as
    // 'holofoil' for cards with no straight holo print), must still offer
    // the correct variants. The card's own `variants` flags (from tcgdex,
    // always synced) are the reliable source, not synced pricing rows.
    $user = User::factory()->create();
    $this->actingAs($user);

    $set = Set::create(['tcgdex_id' => 'me03', 'name' => 'Perfect Order']);
    $card = Card::create([
        'tcgdex_id' => 'me03-068', 'set_id' => $set->id, 'local_id' => '068', 'name' => 'Antique Jaw Fossil',
        'variants' => ['holo' => false, 'normal' => true, 'wPromo' => false, 'reverse' => true, 'firstEdition' => false],
    ]);
    CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'cardmarket', 'variant' => 'default', 'captured_on' => today(), 'currency' => 'EUR', 'market_minor' => 4]);
    CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'cardmarket', 'variant' => 'holofoil', 'captured_on' => today(), 'currency' => 'EUR', 'market_minor' => 9]);

    $collection = Collection::factory()->for($user)->create(['name' => 'Main', 'slug' => 'main']);
    $item = CollectionItem::create([
        'collection_id' => $collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => 'me03-068',
        'condition' => 'NM', 'quantity' => 1, 'variant' => 'normal',
    ]);

    Livewire::test(\App\Livewire\Admin\CollectionItems::class)
        ->call('startEditingItem', $item->id)
        ->assertSet('editingAvailableVariants', ['normal', 'reverse-holofoil'])
        ->assertSet('editingVariant', 'normal');
});

test('assigning a variant clears needs_variant_review', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);
    $card = Card::create(['tcgdex_id' => 'me05-116', 'set_id' => $set->id, 'local_id' => '116', 'name' => 'Mega Darkrai ex']);
    $collection = Collection::factory()->for($user)->create(['name' => 'Main', 'slug' => 'main']);
    $item = CollectionItem::create([
        'collection_id' => $collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => 'me05-116',
        'condition' => 'NM', 'quantity' => 1, 'needs_variant_review' => true,
    ]);

    Livewire::test(\App\Livewire\Admin\CollectionItems::class)
        ->call('startEditingItem', $item->id)
        ->set('editingVariant', 'holofoil')
        ->call('saveItem');

    expect($item->fresh()->needs_variant_review)->toBeFalse();
});

test('editing notes on a flagged item does not clear needs_variant_review', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);
    $card = Card::create(['tcgdex_id' => 'me05-116', 'set_id' => $set->id, 'local_id' => '116', 'name' => 'Mega Darkrai ex']);
    $collection = Collection::factory()->for($user)->create(['name' => 'Main', 'slug' => 'main']);
    $item = CollectionItem::create([
        'collection_id' => $collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => 'me05-116',
        'condition' => 'NM', 'quantity' => 1, 'needs_variant_review' => true,
    ]);

    Livewire::test(\App\Livewire\Admin\CollectionItems::class)
        ->call('startEditingNotes', $item->id)
        ->set('editingNotes', 'a note')
        ->call('saveNotes');

    expect($item->fresh()->needs_variant_review)->toBeTrue();
});

test('a user cannot edit another users item full details by guessing its ID (IDOR)', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $this->actingAs($user);

    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);
    $card = Card::create(['tcgdex_id' => 'me05-116', 'set_id' => $set->id, 'local_id' => '116', 'name' => 'Mega Darkrai ex']);
    $otherCollection = Collection::factory()->for($otherUser)->create(['name' => 'Not mine', 'slug' => 'not-mine']);
    $otherItem = CollectionItem::create([
        'collection_id' => $otherCollection->id, 'card_id' => $card->id, 'card_tcgdex_id' => 'me05-116',
        'condition' => 'NM', 'quantity' => 1,
    ]);

    Livewire::test(\App\Livewire\Admin\CollectionItems::class)
        ->call('startEditingItem', $otherItem->id)
        ->assertStatus(404);

    expect($otherItem->fresh()->condition)->toBe('NM');
});

test('search matches by card name', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    $collection = Collection::factory()->for($user)->create(['name' => 'Main', 'slug' => 'main']);
    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);
    $darkrai = Card::create(['tcgdex_id' => 'me05-116', 'set_id' => $set->id, 'local_id' => '116', 'name' => 'Mega Darkrai ex']);
    $toucannon = Card::create(['tcgdex_id' => 'me05-068', 'set_id' => $set->id, 'local_id' => '068', 'name' => 'Toucannon']);
    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $darkrai->id, 'card_tcgdex_id' => 'me05-116', 'condition' => 'NM', 'quantity' => 1]);
    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $toucannon->id, 'card_tcgdex_id' => 'me05-068', 'condition' => 'NM', 'quantity' => 1]);

    Livewire::test(\App\Livewire\Admin\CollectionItems::class)
        ->set('search', 'darkrai')
        ->assertSee('Mega Darkrai ex')
        ->assertDontSee('Toucannon');
});

test('search matches by set name', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    $collection = Collection::factory()->for($user)->create(['name' => 'Main', 'slug' => 'main']);
    $pitchBlack = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);
    $surging = Set::create(['tcgdex_id' => 'sv08', 'name' => 'Surging Sparks']);
    $darkrai = Card::create(['tcgdex_id' => 'me05-116', 'set_id' => $pitchBlack->id, 'local_id' => '116', 'name' => 'Mega Darkrai ex']);
    $other = Card::create(['tcgdex_id' => 'sv08-1', 'set_id' => $surging->id, 'local_id' => '1', 'name' => 'Something Else']);
    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $darkrai->id, 'card_tcgdex_id' => 'me05-116', 'condition' => 'NM', 'quantity' => 1]);
    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $other->id, 'card_tcgdex_id' => 'sv08-1', 'condition' => 'NM', 'quantity' => 1]);

    Livewire::test(\App\Livewire\Admin\CollectionItems::class)
        ->set('search', 'pitch black')
        ->assertSee('Mega Darkrai ex')
        ->assertDontSee('Something Else');
});

test('search matches by notes', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    $collection = Collection::factory()->for($user)->create(['name' => 'Main', 'slug' => 'main']);
    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);
    $darkrai = Card::create(['tcgdex_id' => 'me05-116', 'set_id' => $set->id, 'local_id' => '116', 'name' => 'Mega Darkrai ex']);
    $other = Card::create(['tcgdex_id' => 'me05-068', 'set_id' => $set->id, 'local_id' => '068', 'name' => 'Toucannon']);
    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $darkrai->id, 'card_tcgdex_id' => 'me05-116', 'condition' => 'NM', 'quantity' => 1, 'notes' => 'bought at a con']);
    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $other->id, 'card_tcgdex_id' => 'me05-068', 'condition' => 'NM', 'quantity' => 1]);

    Livewire::test(\App\Livewire\Admin\CollectionItems::class)
        ->set('search', 'con')
        ->assertSee('Mega Darkrai ex')
        ->assertDontSee('Toucannon');
});

test('condition filter narrows the list', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    $collection = Collection::factory()->for($user)->create(['name' => 'Main', 'slug' => 'main']);
    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);
    $darkrai = Card::create(['tcgdex_id' => 'me05-116', 'set_id' => $set->id, 'local_id' => '116', 'name' => 'Mega Darkrai ex']);
    $other = Card::create(['tcgdex_id' => 'me05-068', 'set_id' => $set->id, 'local_id' => '068', 'name' => 'Toucannon']);
    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $darkrai->id, 'card_tcgdex_id' => 'me05-116', 'condition' => 'NM', 'quantity' => 1]);
    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $other->id, 'card_tcgdex_id' => 'me05-068', 'condition' => 'LP', 'quantity' => 1]);

    Livewire::test(\App\Livewire\Admin\CollectionItems::class)
        ->set('conditionFilter', 'LP')
        ->assertSee('Toucannon')
        ->assertDontSee('Mega Darkrai ex');
});

test('variant filter narrows the list', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    $collection = Collection::factory()->for($user)->create(['name' => 'Main', 'slug' => 'main']);
    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);
    $darkrai = Card::create(['tcgdex_id' => 'me05-116', 'set_id' => $set->id, 'local_id' => '116', 'name' => 'Mega Darkrai ex']);
    $other = Card::create(['tcgdex_id' => 'me05-068', 'set_id' => $set->id, 'local_id' => '068', 'name' => 'Toucannon']);
    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $darkrai->id, 'card_tcgdex_id' => 'me05-116', 'condition' => 'NM', 'quantity' => 1, 'variant' => 'holofoil']);
    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $other->id, 'card_tcgdex_id' => 'me05-068', 'condition' => 'NM', 'quantity' => 1, 'variant' => 'normal']);

    Livewire::test(\App\Livewire\Admin\CollectionItems::class)
        ->set('variantFilter', 'holofoil')
        ->assertSee('Mega Darkrai ex')
        ->assertDontSee('Toucannon');
});

test('needs-review filter shows only flagged items', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    $collection = Collection::factory()->for($user)->create(['name' => 'Main', 'slug' => 'main']);
    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);
    $darkrai = Card::create(['tcgdex_id' => 'me05-116', 'set_id' => $set->id, 'local_id' => '116', 'name' => 'Mega Darkrai ex']);
    $other = Card::create(['tcgdex_id' => 'me05-068', 'set_id' => $set->id, 'local_id' => '068', 'name' => 'Toucannon']);
    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $darkrai->id, 'card_tcgdex_id' => 'me05-116', 'condition' => 'NM', 'quantity' => 1, 'needs_variant_review' => true]);
    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $other->id, 'card_tcgdex_id' => 'me05-068', 'condition' => 'NM', 'quantity' => 1]);

    Livewire::test(\App\Livewire\Admin\CollectionItems::class)
        ->set('needsReviewOnly', true)
        ->assertSee('Mega Darkrai ex')
        ->assertDontSee('Toucannon');
});

test('sorting by name orders alphabetically', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    $collection = Collection::factory()->for($user)->create(['name' => 'Main', 'slug' => 'main']);
    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);
    $zebra = Card::create(['tcgdex_id' => 'me05-1', 'set_id' => $set->id, 'local_id' => '1', 'name' => 'Zebstrika']);
    $abra = Card::create(['tcgdex_id' => 'me05-2', 'set_id' => $set->id, 'local_id' => '2', 'name' => 'Abra']);
    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $zebra->id, 'card_tcgdex_id' => 'me05-1', 'condition' => 'NM', 'quantity' => 1]);
    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $abra->id, 'card_tcgdex_id' => 'me05-2', 'condition' => 'NM', 'quantity' => 1]);

    $component = Livewire::test(\App\Livewire\Admin\CollectionItems::class)->call('sortBy', 'name');

    $names = $component->viewData('items')->pluck('card.name')->all();
    expect($names)->toBe(['Abra', 'Zebstrika']);
});

test('default sort is value descending', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    $collection = Collection::factory()->for($user)->create(['name' => 'Main', 'slug' => 'main']);
    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);
    $cheap = Card::create(['tcgdex_id' => 'me05-1', 'set_id' => $set->id, 'local_id' => '1', 'name' => 'Cheap Card']);
    $pricey = Card::create(['tcgdex_id' => 'me05-2', 'set_id' => $set->id, 'local_id' => '2', 'name' => 'Pricey Card']);
    CardPriceSnapshot::create(['card_id' => $cheap->id, 'source' => 'tcgplayer', 'variant' => 'normal', 'captured_on' => today(), 'currency' => 'USD', 'market_minor' => 100]);
    CardPriceSnapshot::create(['card_id' => $pricey->id, 'source' => 'tcgplayer', 'variant' => 'normal', 'captured_on' => today(), 'currency' => 'USD', 'market_minor' => 10000]);
    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $cheap->id, 'card_tcgdex_id' => 'me05-1', 'condition' => 'NM', 'quantity' => 1]);
    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $pricey->id, 'card_tcgdex_id' => 'me05-2', 'condition' => 'NM', 'quantity' => 1]);

    $component = Livewire::test(\App\Livewire\Admin\CollectionItems::class);

    $names = $component->viewData('items')->pluck('card.name')->all();
    expect($names)->toBe(['Pricey Card', 'Cheap Card']);
});

test('the table shows variant, grading, value, and status columns', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    $collection = Collection::factory()->for($user)->create(['name' => 'Main', 'slug' => 'main']);
    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);
    $card = Card::create(['tcgdex_id' => 'me05-116', 'set_id' => $set->id, 'local_id' => '116', 'name' => 'Mega Darkrai ex']);
    CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'tcgplayer', 'variant' => 'holofoil', 'captured_on' => today(), 'currency' => 'USD', 'market_minor' => 2000]);
    CollectionItem::create([
        'collection_id' => $collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => 'me05-116',
        'condition' => 'NM', 'quantity' => 2, 'variant' => 'holofoil',
        'grade_company' => 'PSA', 'grade_value' => '10',
    ]);

    Livewire::test(\App\Livewire\Admin\CollectionItems::class)
        ->assertSee('Holofoil')
        ->assertSee('PSA 10')
        ->assertSee('$40.00'); // 2000 minor * qty 2 = 4000 minor = $40.00
});

test('an item with no priced snapshot shows an em dash for value', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    $collection = Collection::factory()->for($user)->create(['name' => 'Main', 'slug' => 'main']);
    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);
    $card = Card::create(['tcgdex_id' => 'me05-116', 'set_id' => $set->id, 'local_id' => '116', 'name' => 'Mega Darkrai ex']);
    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => 'me05-116', 'condition' => 'NM', 'quantity' => 1]);

    $html = Livewire::test(\App\Livewire\Admin\CollectionItems::class)
        ->assertSee('—')
        ->html();

    // A plain assertDontSee('$') would false-positive on the toolbar's
    // unrelated `wire:click="$set('needsReviewOnly', ...)"` markup — match
    // the actual currency shape (e.g. "$40.00") instead, so this test would
    // really fail if the Value column stopped rendering an em dash.
    expect($html)->not->toMatch('/\$\d/');
});

test('a flagged item shows the needs-review badge', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    $collection = Collection::factory()->for($user)->create(['name' => 'Main', 'slug' => 'main']);
    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);
    $card = Card::create(['tcgdex_id' => 'me05-116', 'set_id' => $set->id, 'local_id' => '116', 'name' => 'Mega Darkrai ex']);
    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => 'me05-116', 'condition' => 'NM', 'quantity' => 1, 'needs_variant_review' => true]);

    Livewire::test(\App\Livewire\Admin\CollectionItems::class)
        ->assertSee('Review');
});

test('quantity can be edited inline', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    $collection = Collection::factory()->for($user)->create(['name' => 'Main', 'slug' => 'main']);
    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);
    $card = Card::create(['tcgdex_id' => 'me05-116', 'set_id' => $set->id, 'local_id' => '116', 'name' => 'Mega Darkrai ex']);
    $item = CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => 'me05-116', 'condition' => 'NM', 'quantity' => 1]);

    Livewire::test(\App\Livewire\Admin\CollectionItems::class)
        ->call('startEditingQty', $item->id)
        ->set('editingQtyValue', 5)
        ->call('saveQty');

    expect($item->fresh()->quantity)->toBe(5);
});

test('a user cannot inline-edit quantity on another users item by guessing its ID (IDOR)', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $this->actingAs($user);

    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);
    $card = Card::create(['tcgdex_id' => 'me05-116', 'set_id' => $set->id, 'local_id' => '116', 'name' => 'Mega Darkrai ex']);
    $otherCollection = Collection::factory()->for($otherUser)->create(['name' => 'Not mine', 'slug' => 'not-mine']);
    $otherItem = CollectionItem::create(['collection_id' => $otherCollection->id, 'card_id' => $card->id, 'card_tcgdex_id' => 'me05-116', 'condition' => 'NM', 'quantity' => 1]);

    Livewire::test(\App\Livewire\Admin\CollectionItems::class)
        ->call('startEditingQty', $otherItem->id)
        ->assertStatus(404);

    expect($otherItem->fresh()->quantity)->toBe(1);
});

test('the list paginates at 24 items per page', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    $collection = Collection::factory()->for($user)->create(['name' => 'Main', 'slug' => 'main']);
    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);

    for ($i = 1; $i <= 26; $i++) {
        $card = Card::create(['tcgdex_id' => "me05-{$i}", 'set_id' => $set->id, 'local_id' => (string) $i, 'name' => "Card {$i}"]);
        CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => "me05-{$i}", 'condition' => 'NM', 'quantity' => 1]);
    }

    $component = Livewire::test(\App\Livewire\Admin\CollectionItems::class);

    expect($component->viewData('items'))->toHaveCount(24);
    expect($component->viewData('items')->total())->toBe(26);
});

test('page 2 is reachable and shows the right items after a Livewire interaction', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    $collection = Collection::factory()->for($user)->create(['name' => 'Main', 'slug' => 'main']);
    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);

    for ($i = 1; $i <= 26; $i++) {
        $card = Card::create(['tcgdex_id' => "me05-{$i}", 'set_id' => $set->id, 'local_id' => (string) $i, 'name' => "Card {$i}"]);
        CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => "me05-{$i}", 'condition' => 'NM', 'quantity' => 1]);
    }

    $component = Livewire::test(\App\Livewire\Admin\CollectionItems::class)
        ->set('conditionFilter', 'NM') // an interaction that triggers a component update, same class of bug as search/sort/edit
        ->call('sortBy', 'name')
        ->call('gotoPage', 2);

    expect($component->viewData('items'))->toHaveCount(2); // 26 items, 24 on page 1, 2 remain on page 2
});

test('clicking delete opens a confirmation modal instead of deleting immediately', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    $collection = Collection::factory()->for($user)->create(['name' => 'Main', 'slug' => 'main']);
    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);
    $card = Card::create(['tcgdex_id' => 'me05-116', 'set_id' => $set->id, 'local_id' => '116', 'name' => 'Mega Darkrai ex']);
    $item = CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => 'me05-116', 'condition' => 'NM', 'quantity' => 1]);

    Livewire::test(\App\Livewire\Admin\CollectionItems::class)
        ->call('confirmDelete', $item->id)
        ->assertSet('confirmingDeleteItemId', $item->id);

    expect(CollectionItem::find($item->id))->not->toBeNull();
});

test('confirming delete in the modal actually deletes the item', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    $collection = Collection::factory()->for($user)->create(['name' => 'Main', 'slug' => 'main']);
    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);
    $card = Card::create(['tcgdex_id' => 'me05-116', 'set_id' => $set->id, 'local_id' => '116', 'name' => 'Mega Darkrai ex']);
    $item = CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => 'me05-116', 'condition' => 'NM', 'quantity' => 1]);

    Livewire::test(\App\Livewire\Admin\CollectionItems::class)
        ->call('confirmDelete', $item->id)
        ->call('delete', $item->id)
        ->assertSet('confirmingDeleteItemId', null);

    expect(CollectionItem::find($item->id))->toBeNull();
});

test('cancelling the delete modal closes it without deleting', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    $collection = Collection::factory()->for($user)->create(['name' => 'Main', 'slug' => 'main']);
    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);
    $card = Card::create(['tcgdex_id' => 'me05-116', 'set_id' => $set->id, 'local_id' => '116', 'name' => 'Mega Darkrai ex']);
    $item = CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => 'me05-116', 'condition' => 'NM', 'quantity' => 1]);

    Livewire::test(\App\Livewire\Admin\CollectionItems::class)
        ->call('confirmDelete', $item->id)
        ->call('cancelDelete')
        ->assertSet('confirmingDeleteItemId', null);

    expect(CollectionItem::find($item->id))->not->toBeNull();
});

test('a user cannot open the delete modal for another users item by guessing its ID (IDOR)', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $this->actingAs($user);

    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);
    $card = Card::create(['tcgdex_id' => 'me05-116', 'set_id' => $set->id, 'local_id' => '116', 'name' => 'Mega Darkrai ex']);
    $otherCollection = Collection::factory()->for($otherUser)->create(['name' => 'Not mine', 'slug' => 'not-mine']);
    $otherItem = CollectionItem::create(['collection_id' => $otherCollection->id, 'card_id' => $card->id, 'card_tcgdex_id' => 'me05-116', 'condition' => 'NM', 'quantity' => 1]);

    Livewire::test(\App\Livewire\Admin\CollectionItems::class)
        ->call('confirmDelete', $otherItem->id)
        ->assertStatus(404);
});
