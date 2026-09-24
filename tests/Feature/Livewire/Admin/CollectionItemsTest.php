<?php

declare(strict_types=1);

use App\Livewire\Admin\CollectionItems;
use App\Models\User;
use App\Modules\Catalog\Models\Card;
use App\Modules\Catalog\Models\CardPriceSnapshot;
use App\Modules\Catalog\Models\Set;
use App\Modules\Collection\Models\Collection;
use App\Modules\Collection\Models\CollectionItem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

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

    Livewire::test(CollectionItems::class)
        ->assertSee('Mega Darkrai ex')
        ->assertSee('NM');
});

test('the search box shows a loading indicator while a debounced search is in flight', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $html = Livewire::test(CollectionItems::class)->html();

    expect($html)->toContain('wire:loading')
        ->toContain('wire:target="search"');
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

    $test = Livewire::test(CollectionItems::class)->call('openCardEditor', $card->id);
    $index = collect($test->get('editingRows'))->search(fn ($r) => $r['id'] === $item->id);
    $test->set("editingRows.$index.notes", 'Bought at a con')->call('updateRow', $index);

    expect($item->fresh()->notes)->toBe('Bought at a con');
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

    $test = Livewire::test(CollectionItems::class)->call('openCardEditor', $card->id);
    $index = collect($test->get('editingRows'))->search(fn ($r) => $r['id'] === $item->id);
    $test->call('confirmRemoveRow', $index)->call('removeVariantRow', $index);

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

    Livewire::test(CollectionItems::class)
        ->assertDontSee('Mega Darkrai ex');
});

test('a user cannot delete another users item by guessing its ID (IDOR)', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $this->actingAs($user);

    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);
    $card = Card::create(['tcgdex_id' => 'me05-116', 'set_id' => $set->id, 'local_id' => '116', 'name' => 'Mega Darkrai ex']);
    $collection = Collection::factory()->for($user)->create(['name' => 'Main', 'slug' => 'main']);
    CollectionItem::create([
        'collection_id' => $collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => 'me05-116',
        'condition' => 'NM', 'quantity' => 1,
    ]);

    $otherCollection = Collection::factory()->for($otherUser)->create(['name' => 'Not mine', 'slug' => 'not-mine']);
    $otherItem = CollectionItem::create([
        'collection_id' => $otherCollection->id, 'card_id' => $card->id, 'card_tcgdex_id' => 'me05-116',
        'condition' => 'NM', 'quantity' => 1,
    ]);

    // removeVariantRow() only ever takes a row INDEX, not a raw item id, so
    // the attack surface here is a tampered editingRows payload — swap the
    // id at an index the caller legitimately owns and confirm the method
    // still refuses via ownedItemOrFail(), not whatever id sits in the row.
    Livewire::test(CollectionItems::class)
        ->call('openCardEditor', $card->id)
        ->set('editingRows.0.id', $otherItem->id)
        ->call('removeVariantRow', 0)
        ->assertStatus(404);

    expect(CollectionItem::find($otherItem->id))->not->toBeNull();
});

test('a user cannot edit another users item notes by guessing its ID (IDOR)', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $this->actingAs($user);

    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);
    $card = Card::create(['tcgdex_id' => 'me05-116', 'set_id' => $set->id, 'local_id' => '116', 'name' => 'Mega Darkrai ex']);
    $collection = Collection::factory()->for($user)->create(['name' => 'Main', 'slug' => 'main']);
    CollectionItem::create([
        'collection_id' => $collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => 'me05-116',
        'condition' => 'NM', 'quantity' => 1,
    ]);

    $otherCollection = Collection::factory()->for($otherUser)->create(['name' => 'Not mine', 'slug' => 'not-mine']);
    $otherItem = CollectionItem::create([
        'collection_id' => $otherCollection->id, 'card_id' => $card->id, 'card_tcgdex_id' => 'me05-116',
        'condition' => 'NM', 'quantity' => 1, 'notes' => 'original',
    ]);

    Livewire::test(CollectionItems::class)
        ->call('openCardEditor', $card->id)
        ->set('editingRows.0.id', $otherItem->id)
        ->set('editingRows.0.notes', 'tampered')
        ->call('updateRow', 0)
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

    Livewire::test(CollectionItems::class)
        ->call('openCardEditor', $card->id)
        ->set('editingRows.0.condition', 'LP')
        ->set('editingRows.0.quantity', 3)
        ->set('editingRows.0.variant', 'holofoil')
        ->set('editingRows.0.grade_company', 'PSA')
        ->set('editingRows.0.grade_value', '9')
        ->call('updateRow', 0);

    $fresh = $item->fresh();
    expect($fresh->condition)->toBe('LP');
    expect($fresh->quantity)->toBe(3);
    expect($fresh->variant)->toBe('holofoil');
    expect($fresh->grade_company)->toBe('PSA');
    expect($fresh->grade_value)->toBe('9');
});

test('the edit modal preloads an items existing notes', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);
    $card = Card::create(['tcgdex_id' => 'me05-116', 'set_id' => $set->id, 'local_id' => '116', 'name' => 'Mega Darkrai ex']);
    $collection = Collection::factory()->for($user)->create(['name' => 'Main', 'slug' => 'main']);
    $item = CollectionItem::create([
        'collection_id' => $collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => 'me05-116',
        'condition' => 'NM', 'quantity' => 1, 'notes' => 'Bought at a local shop',
    ]);

    $test = Livewire::test(CollectionItems::class)->call('openCardEditor', $card->id);

    expect($test->get('editingRows')[0]['notes'])->toBe('Bought at a local shop');
});

test('the edit modal can set notes for the first time, not just after the item already has some', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);
    $card = Card::create(['tcgdex_id' => 'me05-116', 'set_id' => $set->id, 'local_id' => '116', 'name' => 'Mega Darkrai ex']);
    $collection = Collection::factory()->for($user)->create(['name' => 'Main', 'slug' => 'main']);
    $item = CollectionItem::create([
        'collection_id' => $collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => 'me05-116',
        'condition' => 'NM', 'quantity' => 1,
    ]);

    $test = Livewire::test(CollectionItems::class)->call('openCardEditor', $card->id);

    expect($test->get('editingRows')[0]['notes'])->toBeNull();

    $test->set('editingRows.0.notes', 'Never got a photo of this one')
        ->call('updateRow', 0);

    expect($item->fresh()->notes)->toBe('Never got a photo of this one');
});

test('saving the edit modal without touching the photo field keeps the existing photo', function () {
    Storage::fake('collection-photos');
    $user = User::factory()->create();
    $this->actingAs($user);

    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);
    $card = Card::create(['tcgdex_id' => 'me05-116', 'set_id' => $set->id, 'local_id' => '116', 'name' => 'Mega Darkrai ex']);
    $collection = Collection::factory()->for($user)->create(['name' => 'Main', 'slug' => 'main']);
    Storage::disk('collection-photos')->put('existing-photo.jpg', 'contents');
    $item = CollectionItem::create([
        'collection_id' => $collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => 'me05-116',
        'condition' => 'NM', 'quantity' => 1, 'photo_path' => 'existing-photo.jpg',
    ]);

    Livewire::test(CollectionItems::class)
        ->call('openCardEditor', $card->id)
        ->set('editingRows.0.quantity', 2)
        ->call('updateRow', 0);

    expect($item->fresh()->photo_path)->toBe('existing-photo.jpg');
    Storage::disk('collection-photos')->assertExists('existing-photo.jpg');
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

    Livewire::test(CollectionItems::class)
        ->call('openCardEditor', $card->id)
        ->set('editingRows.0.condition', 'XX')
        ->call('updateRow', 0)
        ->assertHasErrors(['editingRows.0.condition']);

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

    Livewire::test(CollectionItems::class)
        ->call('openCardEditor', $card->id)
        ->set('editingRows.0.variant', 'reverse-holofoil')
        ->call('updateRow', 0);

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

    Livewire::test(CollectionItems::class)
        ->call('openCardEditor', $card->id)
        ->set('editingRows.0.variant', 'first-edition-ultra-rainbow-secret')
        ->call('updateRow', 0)
        ->assertHasErrors(['editingRows.0.variant']);

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

    $html = Livewire::test(CollectionItems::class)
        ->call('openCardEditor', $card->id)
        ->html();

    // Scoped to the modal's own <select> — the toolbar's variant FILTER
    // dropdown (added in Task 2) statically lists all three variant
    // values on every render, so an unscoped assertDontSee would false-
    // positive on that unrelated markup.
    preg_match('/<select wire:model="editingRows\.0\.variant".*?<\/select>/s', $html, $matches);
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

    $test = Livewire::test(CollectionItems::class)
        ->call('openCardEditor', $card->id)
        ->assertSet('editingAvailableVariants', ['holofoil']);

    expect($test->get('editingRows')[0]['variant'])->toBe('holofoil');
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

    $test = Livewire::test(CollectionItems::class)->call('openCardEditor', $card->id);

    expect($test->get('editingRows')[0]['variant'])->toBe('normal');
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

    $html = Livewire::test(CollectionItems::class)
        ->call('openCardEditor', $card->id)
        ->html();

    // Scoped to the modal's own <select> — see the identical note above.
    preg_match('/<select wire:model="editingRows\.0\.variant".*?<\/select>/s', $html, $matches);
    $modalSelect = $matches[0] ?? '';

    expect($modalSelect)->toContain('value="normal"')
        ->not->toContain('value="holofoil"')
        ->not->toContain('value="reverse-holofoil"');
});

test('the no-pricing-data variant fallback uses only the primary item, not every row in the card group', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);
    $card = Card::create(['tcgdex_id' => 'me05-116', 'set_id' => $set->id, 'local_id' => '116', 'name' => 'Mega Darkrai ex']);
    $collection = Collection::factory()->for($user)->create(['name' => 'Main', 'slug' => 'main']);
    CollectionItem::create([
        'collection_id' => $collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => 'me05-116',
        'condition' => 'NM', 'quantity' => 1, 'variant' => 'normal',
    ]);
    CollectionItem::create([
        'collection_id' => $collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => 'me05-116',
        'condition' => 'LP', 'quantity' => 1, 'variant' => 'holofoil',
    ]);

    // No tcgdex `variants` flags and no synced pricing at all for this
    // card — the fallback must offer only the primary (first) owned
    // item's own variant, not the union of every row's variant in this
    // card group, or a row could pick up another row's variant as a
    // selectable option.
    $test = Livewire::test(CollectionItems::class)->call('openCardEditor', $card->id);

    expect($test->get('editingAvailableVariants'))->toBe(['normal']);
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

    $test = Livewire::test(CollectionItems::class)
        ->call('openCardEditor', $card->id)
        ->assertSet('editingAvailableVariants', ['normal', 'reverse-holofoil']);

    expect($test->get('editingRows')[0]['variant'])->toBe('normal');
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

    Livewire::test(CollectionItems::class)
        ->call('openCardEditor', $card->id)
        ->set('editingRows.0.variant', 'holofoil')
        ->call('updateRow', 0);

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

    Livewire::test(CollectionItems::class)
        ->call('openCardEditor', $card->id)
        ->set('editingRows.0.notes', 'a note')
        ->call('updateRow', 0);

    expect($item->fresh()->needs_variant_review)->toBeTrue();
});

test('a user cannot edit another users item full details by guessing its ID (IDOR)', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $this->actingAs($user);

    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);
    $card = Card::create(['tcgdex_id' => 'me05-116', 'set_id' => $set->id, 'local_id' => '116', 'name' => 'Mega Darkrai ex']);
    $collection = Collection::factory()->for($user)->create(['name' => 'Main', 'slug' => 'main']);
    CollectionItem::create([
        'collection_id' => $collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => 'me05-116',
        'condition' => 'NM', 'quantity' => 1,
    ]);

    $otherCollection = Collection::factory()->for($otherUser)->create(['name' => 'Not mine', 'slug' => 'not-mine']);
    $otherItem = CollectionItem::create([
        'collection_id' => $otherCollection->id, 'card_id' => $card->id, 'card_tcgdex_id' => 'me05-116',
        'condition' => 'NM', 'quantity' => 1,
    ]);

    Livewire::test(CollectionItems::class)
        ->call('openCardEditor', $card->id)
        ->set('editingRows.0.id', $otherItem->id)
        ->call('updateRow', 0)
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

    Livewire::test(CollectionItems::class)
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

    Livewire::test(CollectionItems::class)
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

    Livewire::test(CollectionItems::class)
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

    Livewire::test(CollectionItems::class)
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

    Livewire::test(CollectionItems::class)
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

    Livewire::test(CollectionItems::class)
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

    $component = Livewire::test(CollectionItems::class)->call('sortBy', 'name');

    $names = $component->viewData('cardGroups')->pluck('card.name')->all();
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

    $component = Livewire::test(CollectionItems::class);

    $names = $component->viewData('cardGroups')->pluck('card.name')->all();
    expect($names)->toBe(['Pricey Card', 'Cheap Card']);
});

test('an item with no priced snapshot shows an em dash for value', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    $collection = Collection::factory()->for($user)->create(['name' => 'Main', 'slug' => 'main']);
    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);
    $card = Card::create(['tcgdex_id' => 'me05-116', 'set_id' => $set->id, 'local_id' => '116', 'name' => 'Mega Darkrai ex']);
    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => 'me05-116', 'condition' => 'NM', 'quantity' => 1]);

    $html = Livewire::test(CollectionItems::class)
        ->assertSee('—')
        ->html();

    // A plain assertDontSee('$') would false-positive on the toolbar's
    // unrelated `wire:click="$set('needsReviewOnly', ...)"` markup — match
    // the actual currency shape (e.g. "$40.00") instead, so this test would
    // really fail if the Value column stopped rendering an em dash.
    expect($html)->not->toMatch('/\$\d/');
    // A bare "—" gives no reason it's blank — a newly-added card with no
    // price data yet reads identically to something actually broken.
    expect($html)->toContain('title="No price data synced for this card/variant yet"');
});

test('a flagged item shows the needs-review badge', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    $collection = Collection::factory()->for($user)->create(['name' => 'Main', 'slug' => 'main']);
    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);
    $card = Card::create(['tcgdex_id' => 'me05-116', 'set_id' => $set->id, 'local_id' => '116', 'name' => 'Mega Darkrai ex']);
    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => 'me05-116', 'condition' => 'NM', 'quantity' => 1, 'needs_variant_review' => true]);

    Livewire::test(CollectionItems::class)
        ->assertSee('Review');
});

test('the needs-review badge explains what clears it and opens the edit modal when clicked', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    $collection = Collection::factory()->for($user)->create(['name' => 'Main', 'slug' => 'main']);
    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);
    $card = Card::create(['tcgdex_id' => 'me05-116', 'set_id' => $set->id, 'local_id' => '116', 'name' => 'Mega Darkrai ex']);
    $item = CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => 'me05-116', 'condition' => 'NM', 'quantity' => 1, 'needs_variant_review' => true]);

    Livewire::test(CollectionItems::class)
        ->call('openCardEditor', $card->id)
        ->assertSet('editingCardId', $card->id);
});

test('quantity can be edited inline', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    $collection = Collection::factory()->for($user)->create(['name' => 'Main', 'slug' => 'main']);
    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);
    $card = Card::create(['tcgdex_id' => 'me05-116', 'set_id' => $set->id, 'local_id' => '116', 'name' => 'Mega Darkrai ex']);
    $item = CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => 'me05-116', 'condition' => 'NM', 'quantity' => 1]);

    Livewire::test(CollectionItems::class)
        ->call('openCardEditor', $card->id)
        ->set('editingRows.0.quantity', 5)
        ->call('updateRow', 0);

    expect($item->fresh()->quantity)->toBe(5);
});

test('a user cannot inline-edit quantity on another users item by guessing its ID (IDOR)', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $this->actingAs($user);

    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);
    $card = Card::create(['tcgdex_id' => 'me05-116', 'set_id' => $set->id, 'local_id' => '116', 'name' => 'Mega Darkrai ex']);
    $collection = Collection::factory()->for($user)->create(['name' => 'Main', 'slug' => 'main']);
    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => 'me05-116', 'condition' => 'NM', 'quantity' => 1]);

    $otherCollection = Collection::factory()->for($otherUser)->create(['name' => 'Not mine', 'slug' => 'not-mine']);
    $otherItem = CollectionItem::create(['collection_id' => $otherCollection->id, 'card_id' => $card->id, 'card_tcgdex_id' => 'me05-116', 'condition' => 'NM', 'quantity' => 1]);

    Livewire::test(CollectionItems::class)
        ->call('openCardEditor', $card->id)
        ->set('editingRows.0.id', $otherItem->id)
        ->set('editingRows.0.quantity', 5)
        ->call('updateRow', 0)
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

    $component = Livewire::test(CollectionItems::class);

    expect($component->viewData('cardGroups'))->toHaveCount(24);
    expect($component->viewData('cardGroups')->total())->toBe(26);
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

    $component = Livewire::test(CollectionItems::class)
        ->set('conditionFilter', 'NM') // an interaction that triggers a component update, same class of bug as search/sort/edit
        ->call('sortBy', 'name')
        ->call('gotoPage', 2);

    expect($component->viewData('cardGroups'))->toHaveCount(2); // 26 items, 24 on page 1, 2 remain on page 2
});

test('pagination links point back at /admin, not the site root', function () {
    // Bug: the default "value" sort can't be expressed as a SQL ORDER
    // BY (CardPriceResolver's priority chain lives in PHP), so this
    // branch builds a LengthAwarePaginator by hand with no explicit
    // `path` option. Left to its default resolver, its links rendered
    // as "/?page=2" instead of "/admin?page=2" — clicking "Next"
    // took you to the marketing home page, not page 2 of your own
    // collection.
    Role::findOrCreate('user')->givePermissionTo(
        Permission::findOrCreate('use-collection'),
    );
    $user = User::factory()->create();
    $user->assignRole('user');
    $this->actingAs($user);

    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);
    $collection = Collection::factory()->for($user)->create(['name' => 'Main', 'slug' => 'main']);

    foreach (range(1, 25) as $i) {
        $card = Card::create(['tcgdex_id' => "me05-{$i}", 'set_id' => $set->id, 'local_id' => (string) $i, 'name' => "Card {$i}"]);
        CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => "me05-{$i}", 'condition' => 'NM', 'quantity' => 1]);
    }

    $response = $this->get('/admin');

    $response->assertOk();
    $response->assertSee('/admin?page=2', false);
    $response->assertDontSee('href="/?page=2"', false);
});

test('the empty state links straight into adding a card, not just inert text', function () {
    // Was plain text ("No cards yet — add your first one.") with no
    // link anywhere on the row — the "+ Add card" button above the
    // table is easy to miss on first load, and this was the only other
    // thing on the page telling a new user what to do.
    $user = User::factory()->create();
    $this->actingAs($user);

    $html = Livewire::test(CollectionItems::class)->html();

    // Before the fix, the add-card route only appears once (the "+ Add
    // card" button in the header) — the empty-state row is plain text
    // with no link of its own. After the fix it appears a second time,
    // in the row itself.
    expect(substr_count($html, route('admin.collection.add')))->toBe(2);
});

test('a new collection defaults to private, matching every other creation path', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    Livewire::test(CollectionItems::class)
        ->assertSet('isPublic', false);

    expect(Collection::where('user_id', $user->id)->where('slug', 'my-collection')->first()->is_public)->toBeFalse();
});

test('toggling visibility persists it to the users collection', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    Livewire::test(CollectionItems::class)
        ->set('isPublic', true)
        ->call('updateVisibility')
        ->assertSet('isPublic', true);

    expect(Collection::where('user_id', $user->id)->where('slug', 'my-collection')->first()->is_public)->toBeTrue();
});

test('an existing collections current visibility is reflected, not silently reset', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    Collection::create(['user_id' => $user->id, 'slug' => 'my-collection', 'name' => 'My Collection', 'is_public' => true]);

    Livewire::test(CollectionItems::class)
        ->assertSet('isPublic', true);
});

test('the visibility button toggles and persists in one click, no separate save step', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    Livewire::test(CollectionItems::class)
        ->assertSet('isPublic', false)
        ->assertSee('Private')
        ->call('toggleVisibility')
        ->assertSet('isPublic', true)
        ->assertSee('Public');

    expect(Collection::where('user_id', $user->id)->where('slug', 'my-collection')->first()->is_public)->toBeTrue();
});

test('a card with 3 items across 2 conditions groups into one row with the right totals', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);
    $card = Card::create(['tcgdex_id' => 'me05-116', 'set_id' => $set->id, 'local_id' => '116', 'name' => 'Mega Darkrai ex']);
    CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'tcgplayer', 'variant' => 'normal', 'captured_on' => today(), 'currency' => 'USD', 'market_minor' => 100]);
    CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'tcgplayer', 'variant' => 'holofoil', 'captured_on' => today(), 'currency' => 'USD', 'market_minor' => 300]);

    $collection = Collection::factory()->for($user)->create(['name' => 'Main', 'slug' => 'main']);
    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => 'me05-116', 'variant' => 'normal', 'condition' => 'NM', 'quantity' => 2]);
    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => 'me05-116', 'variant' => 'holofoil', 'condition' => 'NM', 'quantity' => 3]);
    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => 'me05-116', 'variant' => 'holofoil', 'condition' => 'LP', 'quantity' => 1]);

    $test = Livewire::test(CollectionItems::class);

    // One row, not three: 2 + 3 + 1 copies, ($1.00*2)+($3.00*3)+($3.00*1) minor units.
    expect($test->viewData('cardGroups'))->toHaveCount(1);
    $group = $test->viewData('cardGroups')->first();
    expect($group->totalQuantity)->toBe(6);
    expect($group->totalValueMinor)->toBe(200 + 900 + 300);
    expect($test->viewData('totalCards'))->toBe(1);
    expect($test->viewData('totalCopies'))->toBe(6);
});

test('a cardmarket-only card group shows its total in EUR, not mislabeled as USD', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);
    $card = Card::create(['tcgdex_id' => 'me05-116', 'set_id' => $set->id, 'local_id' => '116', 'name' => 'Mega Darkrai ex']);
    CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'cardmarket', 'variant' => 'default', 'captured_on' => today(), 'currency' => 'EUR', 'market_minor' => 500]);

    $collection = Collection::factory()->for($user)->create(['name' => 'Main', 'slug' => 'main']);
    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => 'me05-116', 'condition' => 'NM', 'quantity' => 1]);

    $html = Livewire::test(CollectionItems::class)->html();

    expect($html)->toContain('€5.00');
    expect($html)->not->toMatch('/\$5\.00/');
});

test('a card group with items priced in different currencies shows no single summed total', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);
    $card = Card::create(['tcgdex_id' => 'me05-116', 'set_id' => $set->id, 'local_id' => '116', 'name' => 'Mega Darkrai ex']);
    CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'tcgplayer', 'variant' => 'normal', 'captured_on' => today(), 'currency' => 'USD', 'market_minor' => 100]);
    CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'cardmarket', 'variant' => 'holofoil', 'captured_on' => today(), 'currency' => 'EUR', 'market_minor' => 900]);

    $collection = Collection::factory()->for($user)->create(['name' => 'Main', 'slug' => 'main']);
    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => 'me05-116', 'variant' => 'normal', 'condition' => 'NM', 'quantity' => 1]);
    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => 'me05-116', 'variant' => 'holofoil', 'condition' => 'NM', 'quantity' => 1]);

    $test = Livewire::test(CollectionItems::class);
    $group = $test->viewData('cardGroups')->first();

    expect($group->totalValueMinor)->toBeNull();
    expect($group->hasMixedCurrencyPricing)->toBeTrue();
    expect($test->html())->toContain('Mixed currencies');
});

test('the default value sort never compares raw amounts across different currencies', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);
    $collection = Collection::factory()->for($user)->create(['name' => 'Main', 'slug' => 'main']);

    // USD group's raw minor units (1000) are larger than the EUR group's
    // (500) — a naive cross-currency comparison would rank USD first.
    // There's no live exchange rate to convert fairly, so groups are
    // ordered by currency code first instead, EUR ('E') before USD ('U').
    $usdCard = Card::create(['tcgdex_id' => 'me05-001', 'set_id' => $set->id, 'local_id' => '001', 'name' => 'Tropius']);
    CardPriceSnapshot::create(['card_id' => $usdCard->id, 'source' => 'tcgplayer', 'variant' => 'normal', 'captured_on' => today(), 'currency' => 'USD', 'market_minor' => 1000]);
    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $usdCard->id, 'card_tcgdex_id' => 'me05-001', 'variant' => 'normal', 'condition' => 'NM', 'quantity' => 1]);

    $eurCard = Card::create(['tcgdex_id' => 'me05-002', 'set_id' => $set->id, 'local_id' => '002', 'name' => 'Grubbin']);
    CardPriceSnapshot::create(['card_id' => $eurCard->id, 'source' => 'cardmarket', 'variant' => 'default', 'captured_on' => today(), 'currency' => 'EUR', 'market_minor' => 500]);
    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $eurCard->id, 'card_tcgdex_id' => 'me05-002', 'condition' => 'NM', 'quantity' => 1]);

    $test = Livewire::test(CollectionItems::class);
    $groups = $test->viewData('cardGroups');

    expect($groups)->toHaveCount(2);
    expect($groups[0]->card->id)->toBe($eurCard->id);
    expect($groups[0]->totalValueCurrency)->toBe('EUR');
    expect($groups[1]->card->id)->toBe($usdCard->id);
    expect($groups[1]->totalValueCurrency)->toBe('USD');
});

test('the grouped query orders deterministically before its row-count ceiling applies', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);
    $card = Card::create(['tcgdex_id' => 'me05-116', 'set_id' => $set->id, 'local_id' => '116', 'name' => 'Mega Darkrai ex']);
    $collection = Collection::factory()->for($user)->create(['name' => 'Main', 'slug' => 'main']);
    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => 'me05-116', 'condition' => 'NM', 'quantity' => 1]);

    $queries = [];
    DB::listen(function ($query) use (&$queries) {
        $queries[] = $query->sql;
    });

    Livewire::test(CollectionItems::class);

    $limitedQuery = collect($queries)->first(
        fn ($sql) => str_contains($sql, 'limit') && str_contains($sql, 'collection_items'),
    );

    expect($limitedQuery)->not->toBeNull();
    expect($limitedQuery)->toContain('order by');
    expect($limitedQuery)->toContain('"card_id" asc');
});

test('two different cards each with one item produce two separate groups', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);
    $a = Card::create(['tcgdex_id' => 'me05-001', 'set_id' => $set->id, 'local_id' => '001', 'name' => 'Tropius']);
    $b = Card::create(['tcgdex_id' => 'me05-002', 'set_id' => $set->id, 'local_id' => '002', 'name' => 'Grubbin']);
    $collection = Collection::factory()->for($user)->create(['name' => 'Main', 'slug' => 'main']);
    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $a->id, 'card_tcgdex_id' => 'me05-001', 'condition' => 'NM', 'quantity' => 1]);
    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $b->id, 'card_tcgdex_id' => 'me05-002', 'condition' => 'NM', 'quantity' => 1]);

    $test = Livewire::test(CollectionItems::class);

    expect($test->viewData('cardGroups'))->toHaveCount(2);
    expect($test->viewData('totalCards'))->toBe(2);
    expect($test->viewData('totalCopies'))->toBe(2);
});

test('the toolbar count reads distinct cards and total copies, not row count', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);
    $card = Card::create(['tcgdex_id' => 'me05-116', 'set_id' => $set->id, 'local_id' => '116', 'name' => 'Mega Darkrai ex']);
    $collection = Collection::factory()->for($user)->create(['name' => 'Main', 'slug' => 'main']);
    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => 'me05-116', 'variant' => 'normal', 'condition' => 'NM', 'quantity' => 2]);
    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => 'me05-116', 'variant' => 'holofoil', 'condition' => 'NM', 'quantity' => 3]);

    Livewire::test(CollectionItems::class)
        ->assertSee('Showing')
        ->assertSee('1', false)
        ->assertSee('card', false)
        ->assertSee('5', false)
        ->assertSee('copies', false);
});

test('a card row shows each owned variant as a chip with its quantity', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);
    $card = Card::create(['tcgdex_id' => 'me05-116', 'set_id' => $set->id, 'local_id' => '116', 'name' => 'Mega Darkrai ex']);
    $collection = Collection::factory()->for($user)->create(['name' => 'Main', 'slug' => 'main']);
    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => 'me05-116', 'variant' => 'normal', 'condition' => 'NM', 'quantity' => 2]);
    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => 'me05-116', 'variant' => 'holofoil', 'condition' => 'LP', 'quantity' => 3]);

    Livewire::test(CollectionItems::class)
        ->assertSee('Mega Darkrai ex')
        ->assertSee('Normal · NM')
        ->assertSee('×2', false)
        ->assertSee('Holofoil · LP')
        ->assertSee('×3', false);
});

test('opening the card editor lists every owned variant as an editable row', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);
    $card = Card::create(['tcgdex_id' => 'me05-116', 'set_id' => $set->id, 'local_id' => '116', 'name' => 'Mega Darkrai ex']);
    $collection = Collection::factory()->for($user)->create(['name' => 'Main', 'slug' => 'main']);
    $normal = CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => 'me05-116', 'variant' => 'normal', 'condition' => 'NM', 'quantity' => 2]);
    $holo = CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => 'me05-116', 'variant' => 'holofoil', 'condition' => 'LP', 'quantity' => 1]);

    $test = Livewire::test(CollectionItems::class)
        ->call('openCardEditor', $card->id)
        ->assertSet('editingCardId', $card->id)
        ->assertSet('editingCardName', 'Mega Darkrai ex');

    $rows = collect($test->get('editingRows'));
    expect($rows->pluck('id')->sort()->values()->all())->toBe([$normal->id, $holo->id]);
    expect($rows->firstWhere('id', $normal->id)['quantity'])->toBe(2);
});

test('a user cannot open another users card in the editor (IDOR)', function () {
    $owner = User::factory()->create();
    $stranger = User::factory()->create();
    $this->actingAs($stranger);

    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);
    $card = Card::create(['tcgdex_id' => 'me05-116', 'set_id' => $set->id, 'local_id' => '116', 'name' => 'Mega Darkrai ex']);
    $collection = Collection::factory()->for($owner)->create(['name' => 'Main', 'slug' => 'main']);
    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => 'me05-116', 'condition' => 'NM', 'quantity' => 1]);

    Livewire::test(CollectionItems::class)
        ->call('openCardEditor', $card->id)
        ->assertStatus(404);
});

test('closing the card editor clears its state', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);
    $card = Card::create(['tcgdex_id' => 'me05-116', 'set_id' => $set->id, 'local_id' => '116', 'name' => 'Mega Darkrai ex']);
    $collection = Collection::factory()->for($user)->create(['name' => 'Main', 'slug' => 'main']);
    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => 'me05-116', 'condition' => 'NM', 'quantity' => 1]);

    Livewire::test(CollectionItems::class)
        ->call('openCardEditor', $card->id)
        ->call('closeCardEditor')
        ->assertSet('editingCardId', null)
        ->assertSet('editingRows', []);
});

test('editing a rows quantity in the card modal autosaves it immediately', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);
    $card = Card::create(['tcgdex_id' => 'me05-116', 'set_id' => $set->id, 'local_id' => '116', 'name' => 'Mega Darkrai ex']);
    $collection = Collection::factory()->for($user)->create(['name' => 'Main', 'slug' => 'main']);
    $item = CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => 'me05-116', 'variant' => 'normal', 'condition' => 'NM', 'quantity' => 1]);

    Livewire::test(CollectionItems::class)
        ->call('openCardEditor', $card->id)
        ->set('editingRows.0.quantity', 5)
        ->call('updateRow', 0)
        ->assertDispatched('row-saved', index: 0);

    expect($item->fresh()->quantity)->toBe(5);
});

test('updateRow rejects a quantity below 1 without saving it', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);
    $card = Card::create(['tcgdex_id' => 'me05-116', 'set_id' => $set->id, 'local_id' => '116', 'name' => 'Mega Darkrai ex']);
    $collection = Collection::factory()->for($user)->create(['name' => 'Main', 'slug' => 'main']);
    $item = CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => 'me05-116', 'condition' => 'NM', 'quantity' => 1]);

    Livewire::test(CollectionItems::class)
        ->call('openCardEditor', $card->id)
        ->set('editingRows.0.quantity', 0)
        ->call('updateRow', 0)
        ->assertHasErrors(['editingRows.0.quantity']);

    expect($item->fresh()->quantity)->toBe(1);
});

test('adding a variant row creates a new item and appends it to the modal instantly', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);
    $card = Card::create(['tcgdex_id' => 'me05-116', 'set_id' => $set->id, 'local_id' => '116', 'name' => 'Mega Darkrai ex']);
    $collection = Collection::factory()->for($user)->create(['name' => 'Main', 'slug' => 'main']);
    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => 'me05-116', 'variant' => 'normal', 'condition' => 'NM', 'quantity' => 1]);

    $test = Livewire::test(CollectionItems::class)
        ->call('openCardEditor', $card->id)
        ->call('addVariantRow');

    expect($test->get('editingRows'))->toHaveCount(2);
    expect(CollectionItem::where('card_id', $card->id)->count())->toBe(2);
});

test('adding a variant row that collides with one already open reflects the bumped quantity instead of silently doing nothing', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);
    $card = Card::create(['tcgdex_id' => 'me05-116', 'set_id' => $set->id, 'local_id' => '116', 'name' => 'Mega Darkrai ex']);
    $collection = Collection::factory()->for($user)->create(['name' => 'Main', 'slug' => 'main']);
    // A row that's already the exact identity addVariantRow() creates
    // (unspecified variant, NM) — CollectionService::addItem()'s existing
    // merge-by-identity means the "new" row is really a quantity bump on
    // this one.
    $item = CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => 'me05-116', 'condition' => 'NM', 'quantity' => 1]);

    $test = Livewire::test(CollectionItems::class)
        ->call('openCardEditor', $card->id)
        ->call('addVariantRow');

    expect($test->get('editingRows'))->toHaveCount(1);
    expect($test->get('editingRows')[0]['quantity'])->toBe(2);
    expect($item->fresh()->quantity)->toBe(2);
});

test('uploading a new photo in the card editor replaces the old file and deletes it from disk', function () {
    Storage::fake('collection-photos');
    Storage::disk('collection-photos')->put('old-photo.jpg', 'old-bytes');

    $user = User::factory()->create();
    $this->actingAs($user);

    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);
    $card = Card::create(['tcgdex_id' => 'me05-116', 'set_id' => $set->id, 'local_id' => '116', 'name' => 'Mega Darkrai ex']);
    $collection = Collection::factory()->for($user)->create(['name' => 'Main', 'slug' => 'main']);
    $item = CollectionItem::create([
        'collection_id' => $collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => 'me05-116',
        'condition' => 'NM', 'quantity' => 1, 'photo_path' => 'old-photo.jpg',
    ]);

    $component = Livewire::test(CollectionItems::class)
        ->call('openCardEditor', $card->id)
        ->call('toggleRowDetails', 0);

    expect($component->html())->toContain('wire:model="editingRows.0.photo"')
        ->toContain('type="file"');

    $component->set('editingRows.0.photo', UploadedFile::fake()->image('new.jpg'));

    $fresh = $item->fresh();
    expect($fresh->photo_path)->not->toBeNull();
    expect($fresh->photo_path)->not->toBe('old-photo.jpg');
    Storage::disk('collection-photos')->assertExists($fresh->photo_path);
    Storage::disk('collection-photos')->assertMissing('old-photo.jpg');
});

test('removing a variant row deletes the item and its stored photo', function () {
    Storage::fake('collection-photos');
    Storage::disk('collection-photos')->put('card.jpg', 'fake-image-bytes');

    $user = User::factory()->create();
    $this->actingAs($user);

    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);
    $card = Card::create(['tcgdex_id' => 'me05-116', 'set_id' => $set->id, 'local_id' => '116', 'name' => 'Mega Darkrai ex']);
    $collection = Collection::factory()->for($user)->create(['name' => 'Main', 'slug' => 'main']);
    $normal = CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => 'me05-116', 'variant' => 'normal', 'condition' => 'NM', 'quantity' => 1, 'photo_path' => 'card.jpg']);
    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => 'me05-116', 'variant' => 'holofoil', 'condition' => 'NM', 'quantity' => 1]);

    $test = Livewire::test(CollectionItems::class)
        ->call('openCardEditor', $card->id);

    $normalIndex = collect($test->get('editingRows'))->search(fn ($r) => $r['id'] === $normal->id);

    $test->call('confirmRemoveRow', $normalIndex)
        ->assertSet('confirmingRemoveRowIndex', $normalIndex)
        ->call('removeVariantRow', $normalIndex)
        ->assertSet('confirmingRemoveRowIndex', null);

    expect(CollectionItem::find($normal->id))->toBeNull();
    Storage::disk('collection-photos')->assertMissing('card.jpg');
    expect(collect($test->get('editingRows')))->toHaveCount(1);
});

test('closing the card editor clears a pending remove confirmation so it does not bleed into the next card opened', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);
    $cardA = Card::create(['tcgdex_id' => 'me05-116', 'set_id' => $set->id, 'local_id' => '116', 'name' => 'Mega Darkrai ex']);
    $cardB = Card::create(['tcgdex_id' => 'me05-015', 'set_id' => $set->id, 'local_id' => '015', 'name' => 'Wailmer']);
    $collection = Collection::factory()->for($user)->create(['name' => 'Main', 'slug' => 'main']);

    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $cardA->id, 'card_tcgdex_id' => 'me05-116', 'variant' => 'normal', 'condition' => 'NM', 'quantity' => 1]);
    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $cardA->id, 'card_tcgdex_id' => 'me05-116', 'variant' => 'holofoil', 'condition' => 'NM', 'quantity' => 1]);
    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $cardB->id, 'card_tcgdex_id' => 'me05-015', 'variant' => 'normal', 'condition' => 'NM', 'quantity' => 1]);
    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $cardB->id, 'card_tcgdex_id' => 'me05-015', 'variant' => 'holofoil', 'condition' => 'NM', 'quantity' => 1]);

    Livewire::test(CollectionItems::class)
        ->call('openCardEditor', $cardA->id)
        ->call('confirmRemoveRow', 1)
        ->assertSet('confirmingRemoveRowIndex', 1)
        ->call('closeCardEditor')
        ->call('openCardEditor', $cardB->id)
        ->assertSet('confirmingRemoveRowIndex', null);
});

test('removing the last variant row in the editor closes it instead of leaving a phantom empty modal', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);
    $card = Card::create(['tcgdex_id' => 'me05-116', 'set_id' => $set->id, 'local_id' => '116', 'name' => 'Mega Darkrai ex']);
    $collection = Collection::factory()->for($user)->create(['name' => 'Main', 'slug' => 'main']);
    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => 'me05-116', 'condition' => 'NM', 'quantity' => 1]);

    Livewire::test(CollectionItems::class)
        ->call('openCardEditor', $card->id)
        ->call('confirmRemoveRow', 0)
        ->call('removeVariantRow', 0)
        ->assertSet('editingCardId', null)
        ->assertSet('editingRows', []);
});
