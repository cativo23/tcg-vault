<?php

declare(strict_types=1);

use App\Livewire\Admin\CardEditor;
use App\Livewire\Admin\CollectionItems;
use App\Models\User;
use App\Modules\Catalog\Models\Card;
use App\Modules\Catalog\Models\CardPriceSnapshot;
use App\Modules\Catalog\Models\Set;
use App\Modules\Catalog\Services\CardPriceResolver;
use App\Modules\Collection\Models\Collection;
use App\Modules\Collection\Models\CollectionItem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\Support\FakePhotoMetadataStripper;

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

    $test = Livewire::test(CardEditor::class)->call('openCardEditor', $card->id);
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

    $test = Livewire::test(CardEditor::class)->call('openCardEditor', $card->id);
    $index = collect($test->get('editingRows'))->search(fn ($r) => $r['id'] === $item->id);
    $test->call('confirmRemoveRow', $index)->call('removeVariantRow', $index);

    expect(CollectionItem::find($item->id))->toBeNull();
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
    Livewire::test(CardEditor::class)
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

    Livewire::test(CardEditor::class)
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

    Livewire::test(CardEditor::class)
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

    $test = Livewire::test(CardEditor::class)->call('openCardEditor', $card->id);

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

    $test = Livewire::test(CardEditor::class)->call('openCardEditor', $card->id);

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

    Livewire::test(CardEditor::class)
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

    Livewire::test(CardEditor::class)
        ->call('openCardEditor', $card->id)
        ->set('editingRows.0.condition', 'XX')
        ->call('updateRow', 0)
        ->assertHasErrors(['editingRows.0.condition']);

    expect($item->fresh()->condition)->toBe('NM');
});

test('editing an items quantity rejects a value past the sane ceiling instead of overflowing the DB column', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);
    $card = Card::create(['tcgdex_id' => 'me05-116', 'set_id' => $set->id, 'local_id' => '116', 'name' => 'Mega Darkrai ex']);
    $collection = Collection::factory()->for($user)->create(['name' => 'Main', 'slug' => 'main']);
    $item = CollectionItem::create([
        'collection_id' => $collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => 'me05-116',
        'condition' => 'NM', 'quantity' => 1,
    ]);

    Livewire::test(CardEditor::class)
        ->call('openCardEditor', $card->id)
        ->set('editingRows.0.quantity', 5000000000)
        ->call('updateRow', 0)
        ->assertHasErrors(['editingRows.0.quantity']);

    expect($item->fresh()->quantity)->toBe(1);
});

test('an invalid quantity in the edit modal renders a visible error message, not just a silent failed save', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);
    $card = Card::create(['tcgdex_id' => 'me05-116', 'set_id' => $set->id, 'local_id' => '116', 'name' => 'Mega Darkrai ex']);
    $collection = Collection::factory()->for($user)->create(['name' => 'Main', 'slug' => 'main']);
    $item = CollectionItem::create([
        'collection_id' => $collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => 'me05-116',
        'condition' => 'NM', 'quantity' => 1,
    ]);

    $html = Livewire::test(CardEditor::class)
        ->call('openCardEditor', $card->id)
        ->set('editingRows.0.quantity', 5000000000)
        ->call('updateRow', 0)
        ->html();

    expect($html)->toContain('must not be greater than 9999');
    expect($item->fresh()->quantity)->toBe(1);
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

    Livewire::test(CardEditor::class)
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

    Livewire::test(CardEditor::class)
        ->call('openCardEditor', $card->id)
        ->set('editingRows.0.variant', 'first-edition-ultra-rainbow-secret')
        ->call('updateRow', 0)
        ->assertHasErrors(['editingRows.0.variant']);

    expect($item->fresh()->variant)->toBe('normal');
});

test('a validation error on one card does not bleed into the next cards editor after switching', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    $collection = Collection::factory()->for($user)->create(['name' => 'Main', 'slug' => 'main']);
    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);

    $cardA = Card::create(['tcgdex_id' => 'me05-116', 'set_id' => $set->id, 'local_id' => '116', 'name' => 'Mega Darkrai ex']);
    CollectionItem::create([
        'collection_id' => $collection->id, 'card_id' => $cardA->id, 'card_tcgdex_id' => 'me05-116',
        'condition' => 'NM', 'quantity' => 1,
    ]);

    $cardB = Card::create(['tcgdex_id' => 'me05-068', 'set_id' => $set->id, 'local_id' => '068', 'name' => 'Toucannon']);
    CollectionItem::create([
        'collection_id' => $collection->id, 'card_id' => $cardB->id, 'card_tcgdex_id' => 'me05-068',
        'condition' => 'NM', 'quantity' => 1,
    ]);

    Livewire::test(CardEditor::class)
        ->call('openCardEditor', $cardA->id)
        ->set('editingRows.0.condition', 'XX')
        ->call('updateRow', 0)
        ->assertHasErrors(['editingRows.0.condition'])
        ->call('closeCardEditor')
        ->call('openCardEditor', $cardB->id)
        ->assertHasNoErrors(['editingRows.0.condition']);
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

    $html = Livewire::test(CardEditor::class)
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

    $test = Livewire::test(CardEditor::class)
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

    $test = Livewire::test(CardEditor::class)->call('openCardEditor', $card->id);

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

    $html = Livewire::test(CardEditor::class)
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
    $test = Livewire::test(CardEditor::class)->call('openCardEditor', $card->id);

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

    $test = Livewire::test(CardEditor::class)
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

    Livewire::test(CardEditor::class)
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

    Livewire::test(CardEditor::class)
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

    Livewire::test(CardEditor::class)
        ->call('openCardEditor', $card->id)
        ->set('editingRows.0.id', $otherItem->id)
        ->call('updateRow', 0)
        ->assertStatus(404);

    expect($otherItem->fresh()->condition)->toBe('NM');
});

test('the needs-review badge explains what clears it and opens the edit modal when clicked', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    $collection = Collection::factory()->for($user)->create(['name' => 'Main', 'slug' => 'main']);
    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);
    $card = Card::create(['tcgdex_id' => 'me05-116', 'set_id' => $set->id, 'local_id' => '116', 'name' => 'Mega Darkrai ex']);
    $item = CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => 'me05-116', 'condition' => 'NM', 'quantity' => 1, 'needs_variant_review' => true]);

    Livewire::test(CardEditor::class)
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

    Livewire::test(CardEditor::class)
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

    Livewire::test(CardEditor::class)
        ->call('openCardEditor', $card->id)
        ->set('editingRows.0.id', $otherItem->id)
        ->set('editingRows.0.quantity', 5)
        ->call('updateRow', 0)
        ->assertStatus(404);

    expect($otherItem->fresh()->quantity)->toBe(1);
});

test('opening the card editor lists every owned variant as an editable row', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);
    $card = Card::create(['tcgdex_id' => 'me05-116', 'set_id' => $set->id, 'local_id' => '116', 'name' => 'Mega Darkrai ex']);
    $collection = Collection::factory()->for($user)->create(['name' => 'Main', 'slug' => 'main']);
    $normal = CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => 'me05-116', 'variant' => 'normal', 'condition' => 'NM', 'quantity' => 2]);
    $holo = CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => 'me05-116', 'variant' => 'holofoil', 'condition' => 'LP', 'quantity' => 1]);

    $test = Livewire::test(CardEditor::class)
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

    Livewire::test(CardEditor::class)
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

    Livewire::test(CardEditor::class)
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

    Livewire::test(CardEditor::class)
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

    Livewire::test(CardEditor::class)
        ->call('openCardEditor', $card->id)
        ->set('editingRows.0.quantity', 0)
        ->call('updateRow', 0)
        ->assertHasErrors(['editingRows.0.quantity']);

    expect($item->fresh()->quantity)->toBe(1);
});

test('updateRow rejects an edit that would collide with a sibling row instead of crashing', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);
    $card = Card::create(['tcgdex_id' => 'me05-116', 'set_id' => $set->id, 'local_id' => '116', 'name' => 'Mega Darkrai ex']);
    $collection = Collection::factory()->for($user)->create(['name' => 'Main', 'slug' => 'main']);
    $normal = CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => 'me05-116', 'variant' => 'normal', 'condition' => 'NM', 'quantity' => 1]);
    $holo = CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => 'me05-116', 'variant' => 'holofoil', 'condition' => 'NM', 'quantity' => 1]);

    // Editing the "normal" row's variant to "holofoil" would collide with
    // the other row's identity — the collection_items_identity_unique
    // index (added to close the concurrent-add race) now rejects the
    // UPDATE outright, since updateRow() writes directly and never went
    // through CollectionService's own merge-on-collision handling.
    Livewire::test(CardEditor::class)
        ->call('openCardEditor', $card->id)
        ->set('editingRows.0.variant', 'holofoil')
        ->call('updateRow', 0)
        ->assertHasErrors(['editingRows.0.variant']);

    expect($normal->fresh()->variant)->toBe('normal');
    expect($holo->fresh()->quantity)->toBe(1);
});

test('the remove and cancel icon-only buttons have accessible names', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);
    $card = Card::create(['tcgdex_id' => 'me05-116', 'set_id' => $set->id, 'local_id' => '116', 'name' => 'Mega Darkrai ex']);
    $collection = Collection::factory()->for($user)->create(['name' => 'Main', 'slug' => 'main']);
    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => 'me05-116', 'variant' => 'normal', 'condition' => 'NM', 'quantity' => 1]);
    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => 'me05-116', 'variant' => 'holofoil', 'condition' => 'NM', 'quantity' => 1]);

    // A "✕" with only a title attribute is not a reliable accessible
    // name and is nothing at all on touch — removeVariantRow only shows
    // its own "✕" once there's more than one row, so this needs two.
    // Labels also name the row itself, not just the action — with two
    // rows open, "Remove this variant" on both would be indistinguishable
    // in a screen reader's button list.
    $test = Livewire::test(CardEditor::class)->call('openCardEditor', $card->id);

    expect($test->html())->toContain('aria-label="Remove variant 1 (Normal, NM)"')
        ->toContain('aria-label="Remove variant 2 (Holofoil, NM)"');

    $test->call('confirmRemoveRow', 0);

    expect($test->html())->toContain('aria-label="Cancel removing variant 1 (Normal, NM)"')
        ->toContain('aria-label="Confirm removing variant 1 (Normal, NM)"');
});

test('the card editor modal has dialog semantics', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);
    $card = Card::create(['tcgdex_id' => 'me05-116', 'set_id' => $set->id, 'local_id' => '116', 'name' => 'Mega Darkrai ex']);
    $collection = Collection::factory()->for($user)->create(['name' => 'Main', 'slug' => 'main']);
    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => 'me05-116', 'condition' => 'NM', 'quantity' => 1]);

    $html = Livewire::test(CardEditor::class)
        ->call('openCardEditor', $card->id)
        ->html();

    expect($html)->toContain('role="dialog"')
        ->toContain('aria-modal="true"')
        ->toContain('aria-labelledby="card-editor-title"')
        ->toContain('id="card-editor-title"');
});

test('closing the card editor dispatches an event to return focus to the row that opened it', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);
    $card = Card::create(['tcgdex_id' => 'me05-116', 'set_id' => $set->id, 'local_id' => '116', 'name' => 'Mega Darkrai ex']);
    $collection = Collection::factory()->for($user)->create(['name' => 'Main', 'slug' => 'main']);
    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => 'me05-116', 'condition' => 'NM', 'quantity' => 1]);

    // Closing removes the modal's whole subtree — including whatever
    // inside it held focus — rather than just hiding it, so without this
    // a keyboard/screen-reader user's focus silently drops to <body> and
    // they lose their place in the table.
    Livewire::test(CardEditor::class)
        ->call('openCardEditor', $card->id)
        ->call('closeCardEditor')
        ->assertDispatched('card-editor-closed', triggerId: "card-editor-trigger-{$card->id}");
});

test('adding a variant row creates a new item and appends it to the modal instantly', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);
    $card = Card::create(['tcgdex_id' => 'me05-116', 'set_id' => $set->id, 'local_id' => '116', 'name' => 'Mega Darkrai ex']);
    $collection = Collection::factory()->for($user)->create(['name' => 'Main', 'slug' => 'main']);
    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => 'me05-116', 'variant' => 'normal', 'condition' => 'NM', 'quantity' => 1]);

    $test = Livewire::test(CardEditor::class)
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

    $test = Livewire::test(CardEditor::class)
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

    $component = Livewire::test(CardEditor::class)
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

    $test = Livewire::test(CardEditor::class)
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

    Livewire::test(CardEditor::class)
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

    Livewire::test(CardEditor::class)
        ->call('openCardEditor', $card->id)
        ->call('confirmRemoveRow', 0)
        ->call('removeVariantRow', 0)
        ->assertSet('editingCardId', null)
        ->assertSet('editingRows', []);
});

test('validation errors on hidden detail fields auto-expands the details section', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);
    $card = Card::create(['tcgdex_id' => 'me05-116', 'set_id' => $set->id, 'local_id' => '116', 'name' => 'Mega Darkrai ex']);
    $collection = Collection::factory()->for($user)->create(['name' => 'Main', 'slug' => 'main']);
    $item = CollectionItem::create([
        'collection_id' => $collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => 'me05-116',
        'condition' => 'NM', 'quantity' => 1,
    ]);

    // Open card editor with showDetails false (default). Trigger a validation error
    // on a hidden detail field by setting grade_company over the max:32 limit.
    $html = Livewire::test(CardEditor::class)
        ->call('openCardEditor', $card->id)
        ->set('editingRows.0.grade_company', 'This is a very long grading company name that exceeds the limit')
        ->call('updateRow', 0)
        ->html();

    // The Details section auto-expanded due to the validation error. Assert the
    // error message and grading inputs are now visible in the rendered HTML.
    expect($html)->toContain('must not be greater than 32');
    expect($html)->toContain('Grading company');
});

/** A signed-in user's item for Mega Darkrai ex with a stored photo, open in the card editor. */
function cardEditorWithStoredPhoto(object $test): array
{
    Storage::fake('collection-photos');
    Storage::disk('collection-photos')->put('old-photo.jpg', 'old-bytes');

    $user = User::factory()->create();
    $test->actingAs($user);
    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);
    $card = Card::create(['tcgdex_id' => 'me05-116', 'set_id' => $set->id, 'local_id' => '116', 'name' => 'Mega Darkrai ex']);
    $collection = Collection::factory()->for($user)->create(['name' => 'Main', 'slug' => 'main']);
    $item = CollectionItem::create([
        'collection_id' => $collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => 'me05-116',
        'condition' => 'NM', 'quantity' => 1, 'photo_path' => 'old-photo.jpg',
    ]);

    return [Livewire::test(CardEditor::class)->call('openCardEditor', $card->id), $item];
}

test('a replacement photo in the card editor has its metadata stripped before it is stored', function () {
    [$component, $item] = cardEditorWithStoredPhoto($this);

    $component->set('editingRows.0.photo', UploadedFile::fake()->image('new.jpg'))->assertHasNoErrors();

    $stored = $item->fresh()->photo_path;

    expect($this->photoStripper->stripped)->toHaveCount(1)
        ->and($stored)->not->toBe('old-photo.jpg')
        ->and(Storage::disk('collection-photos')->get($stored))->toEndWith(FakePhotoMetadataStripper::MARKER);
});

test('a replacement photo that cannot be stripped is refused and the old photo is kept', function () {
    [$component, $item] = cardEditorWithStoredPhoto($this);
    $this->photoStripper->fail = true;

    $component->set('editingRows.0.photo', UploadedFile::fake()->image('new.jpg'))
        ->assertHasErrors(['editingRows.0.photo'])
        ->assertSee('This photo couldn’t be processed. Try a different file.');

    expect($item->fresh()->photo_path)->toBe('old-photo.jpg')
        ->and(Storage::disk('collection-photos')->allFiles())->toBe(['old-photo.jpg']);
});

test('a user over the photo limit keeps the old photo and the new one is not processed', function () {
    [$component, $item] = cardEditorWithStoredPhoto($this);
    RateLimiter::increment('photo-strip:'.auth()->id(), amount: CardEditor::PHOTO_LIMIT_PER_MINUTE);

    $component->set('editingRows.0.photo', UploadedFile::fake()->image('new.jpg'))
        ->assertHasErrors(['editingRows.0.photo'])
        ->assertSet('editingRows.0.photo', fn ($photo) => $photo !== null);

    expect($this->photoStripper->stripped)->toBe([])
        ->and($item->fresh()->photo_path)->toBe('old-photo.jpg');
});

test('the card editor offers special-foil prints from variants_detailed and saves one', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    $card = budewCard();
    $collection = Collection::factory()->for($user)->create(['name' => 'Main', 'slug' => 'main']);
    $item = CollectionItem::create([
        'collection_id' => $collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => 'sv08.5-004',
        'condition' => 'NM', 'quantity' => 1, 'variant' => 'reverse-holofoil',
    ]);

    Livewire::test(CardEditor::class)
        ->call('openCardEditor', $card->id)
        ->assertSet('editingAvailableVariants', ['normal', 'reverse-holofoil', 'reverse-holofoil:pokeball', 'reverse-holofoil:masterball'])
        ->set('editingRows.0.variant', 'reverse-holofoil:masterball')
        ->call('updateRow', 0)
        ->assertHasNoErrors();

    expect($item->fresh()->variant)->toBe('reverse-holofoil:masterball');
});

test('the card editor can record a print tcgdex does not list, from its vocabulary', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    $card = budewCard();
    $collection = Collection::factory()->for($user)->create(['name' => 'Main', 'slug' => 'main']);
    $item = CollectionItem::create([
        'collection_id' => $collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => 'sv08.5-004',
        'condition' => 'NM', 'quantity' => 1, 'variant' => 'normal',
    ]);

    Livewire::test(CardEditor::class)
        ->call('openCardEditor', $card->id)
        ->set('customBase', 'holofoil')
        ->set('customFoil', 'cosmos')
        ->set('customStamp', 'player-rewards-program')
        ->call('useCustomVariant', 0)
        ->assertSet('editingRows.0.variant', 'holofoil:cosmos+player-rewards-program')
        ->assertSee('Holofoil · Cosmos · Player Rewards Program')
        ->call('updateRow', 0)
        ->assertHasNoErrors();

    expect($item->fresh()->variant)->toBe('holofoil:cosmos+player-rewards-program');
});

test('a hand-entered print outside tcgdex\'s vocabulary is refused', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    [$card] = prizePackBudew($user);

    Livewire::test(CardEditor::class)
        ->call('openCardEditor', $card->id)
        ->set('customBase', 'holofoil')
        ->set('customFoil', 'sparkly')
        ->call('useCustomVariant', 0)
        ->assertHasErrors('customPrint.0')
        ->assertSet('editingRows.0.variant', 'holofoil:cosmos+player-rewards-program');
});

test('a super-admin can set a manual market price for a print, shared like any catalog price', function () {
    Role::findOrCreate('super-admin');
    $admin = User::factory()->create();
    $admin->assignRole('super-admin');
    $this->actingAs($admin);
    [$card, $item] = prizePackBudew($admin);

    Livewire::test(CardEditor::class)
        ->call('openCardEditor', $card->id)
        ->set('editingRows.0.manual_price', '0.70')
        ->call('saveManualPrice', 0)
        ->assertHasNoErrors();

    $snapshot = CardPriceSnapshot::where('card_id', $card->id)->sole();
    expect([$snapshot->source, $snapshot->variant, $snapshot->currency, $snapshot->market_minor, $snapshot->capturedOnKey()])
        ->toBe(['manual', 'holofoil:cosmos+player-rewards-program', 'USD', 70, today()->toDateString()]);

    Livewire::test(CollectionItems::class)->assertSee('0.70');
});

test('setting the manual price again the same day updates it instead of adding a row', function () {
    Role::findOrCreate('super-admin');
    $admin = User::factory()->create();
    $admin->assignRole('super-admin');
    $this->actingAs($admin);
    [$card] = prizePackBudew($admin);

    Livewire::test(CardEditor::class)
        ->call('openCardEditor', $card->id)
        ->set('editingRows.0.manual_price', '0.70')
        ->call('saveManualPrice', 0)
        ->set('editingRows.0.manual_price', '0.75')
        ->call('saveManualPrice', 0);

    expect(CardPriceSnapshot::where('card_id', $card->id)->pluck('market_minor')->all())->toBe([75]);
});

test('the editor preloads the print\'s current manual price', function () {
    Role::findOrCreate('super-admin');
    $admin = User::factory()->create();
    $admin->assignRole('super-admin');
    $this->actingAs($admin);
    [$card] = prizePackBudew($admin);
    CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'manual', 'variant' => 'holofoil:cosmos+player-rewards-program', 'captured_on' => today()->subDay(), 'currency' => 'USD', 'market_minor' => 69]);

    Livewire::test(CardEditor::class)
        ->call('openCardEditor', $card->id)
        ->assertSet('editingRows.0.manual_price', '0.69');
});

test('a user without manage-catalog-prices cannot set a manual price, and is not offered the field', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    [$card] = prizePackBudew($user);

    Livewire::test(CardEditor::class)
        ->call('openCardEditor', $card->id)
        ->assertDontSee('Manual market price')
        ->set('editingRows.0.manual_price', '999')
        ->call('saveManualPrice', 0)
        ->assertForbidden();

    expect(CardPriceSnapshot::count())->toBe(0);
});

test('a manual price must be a sane positive amount', function (string $bad) {
    Role::findOrCreate('super-admin');
    $admin = User::factory()->create();
    $admin->assignRole('super-admin');
    $this->actingAs($admin);
    [$card] = prizePackBudew($admin);

    Livewire::test(CardEditor::class)
        ->call('openCardEditor', $card->id)
        ->set('editingRows.0.manual_price', $bad)
        ->call('saveManualPrice', 0)
        ->assertHasErrors('editingRows.0.manual_price');

    expect(CardPriceSnapshot::count())->toBe(0);
})->with(['-1', 'abc', '1000000', '0.001']);

test('a manual price needs the item to have a saved variant', function () {
    Role::findOrCreate('super-admin');
    $admin = User::factory()->create();
    $admin->assignRole('super-admin');
    $this->actingAs($admin);
    [$card, $item] = prizePackBudew($admin);
    $item->update(['variant' => null]);

    Livewire::test(CardEditor::class)
        ->call('openCardEditor', $card->id)
        ->set('editingRows.0.manual_price', '0.70')
        ->call('saveManualPrice', 0)
        ->assertHasErrors('editingRows.0.manual_price');

    expect(CardPriceSnapshot::count())->toBe(0);
});

test('a super-admin sees the manual price field and everyone sees the other-print picker', function () {
    Role::findOrCreate('super-admin');
    $admin = User::factory()->create();
    $admin->assignRole('super-admin');
    $this->actingAs($admin);
    [$card] = prizePackBudew($admin);

    Livewire::test(CardEditor::class)
        ->call('openCardEditor', $card->id)
        ->assertSee('Manual market price (USD)')
        ->assertSee('Other print')
        ->assertSeeHtml('<option value="player-rewards-program">Player Rewards Program</option>')
        ->assertSeeHtml('<option value="cosmos">Cosmos</option>');
});

test('picking an other print saves it on the copy right away', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    [$card, $item] = prizePackBudew($user);
    $item->update(['variant' => 'holofoil']);

    Livewire::test(CardEditor::class)
        ->call('openCardEditor', $card->id)
        ->set('customBase', 'holofoil')
        ->set('customFoil', 'cosmos')
        ->set('customStamp', 'player-rewards-program')
        ->call('useCustomVariant', 0);

    expect($item->fresh()->variant)->toBe('holofoil:cosmos+player-rewards-program');
});

test('a manual price is refused when the copy on screen is not the print saved', function () {
    Role::findOrCreate('super-admin');
    $admin = User::factory()->create();
    $admin->assignRole('super-admin');
    $this->actingAs($admin);
    [$card] = prizePackBudew($admin);

    Livewire::test(CardEditor::class)
        ->call('openCardEditor', $card->id)
        ->set('editingRows.0.variant', 'reverse-holofoil:masterball')
        ->set('editingRows.0.manual_price', '0.70')
        ->call('saveManualPrice', 0)
        ->assertHasErrors('editingRows.0.manual_price');

    expect(CardPriceSnapshot::count())->toBe(0);
});

test('a manual price is refused for a print tcgdex already prices', function () {
    Role::findOrCreate('super-admin');
    $admin = User::factory()->create();
    $admin->assignRole('super-admin');
    $this->actingAs($admin);
    [$card, $item] = prizePackBudew($admin);
    $item->update(['variant' => 'reverse-holofoil:masterball']);
    CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'cardmarket', 'variant' => 'reverse-holofoil:masterball', 'captured_on' => today()->subDays(3), 'currency' => 'EUR', 'market_minor' => 266]);

    Livewire::test(CardEditor::class)
        ->call('openCardEditor', $card->id)
        ->set('editingRows.0.manual_price', '0.70')
        ->call('saveManualPrice', 0)
        ->assertHasErrors('editingRows.0.manual_price');

    expect(CardPriceSnapshot::where('source', 'manual')->count())->toBe(0);
});

test('a manual price refused for a print tcgcsv fills does not blame tcgdex', function () {
    Role::findOrCreate('super-admin');
    $admin = User::factory()->create();
    $admin->assignRole('super-admin');
    $this->actingAs($admin);
    [$card, $item] = prizePackBudew($admin);
    $item->update(['variant' => 'reverse-holofoil:masterball']);
    CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'tcgplayer', 'origin' => 'tcgcsv', 'variant' => 'reverse-holofoil:masterball', 'captured_on' => today(), 'currency' => 'USD', 'market_minor' => 70]);

    Livewire::test(CardEditor::class)
        ->call('openCardEditor', $card->id)
        ->set('editingRows.0.manual_price', '0.70')
        ->call('saveManualPrice', 0)
        ->assertHasErrors('editingRows.0.manual_price')
        ->assertSee('already synced (tcgdex or tcgcsv)');
});

test('reopening the editor still shows a hand-entered print as the copy\'s variant', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    [$card] = prizePackBudew($user);

    Livewire::test(CardEditor::class)
        ->call('openCardEditor', $card->id)
        ->assertSet('editingAvailableVariants', ['normal', 'reverse-holofoil', 'reverse-holofoil:pokeball', 'reverse-holofoil:masterball', 'holofoil:cosmos+player-rewards-program'])
        ->assertSeeHtml('<option value="holofoil:cosmos+player-rewards-program">');
});

test('changing a copy\'s variant reloads the manual price shown for it', function () {
    Role::findOrCreate('super-admin');
    $admin = User::factory()->create();
    $admin->assignRole('super-admin');
    $this->actingAs($admin);
    [$card] = prizePackBudew($admin);
    CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'manual', 'variant' => 'holofoil:cosmos+player-rewards-program', 'captured_on' => today(), 'currency' => 'USD', 'market_minor' => 70]);

    Livewire::test(CardEditor::class)
        ->call('openCardEditor', $card->id)
        ->assertSet('editingRows.0.manual_price', '0.70')
        ->set('editingRows.0.variant', 'reverse-holofoil:pokeball')
        ->call('updateRow', 0)
        ->assertSet('editingRows.0.manual_price', null);
});

test('a picker error shows under the row that caused it only, and the picker resets after a good pick', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    [$card] = prizePackBudew($user);

    Livewire::test(CardEditor::class)
        ->call('openCardEditor', $card->id)
        ->call('useCustomVariant', 0)
        ->assertHasErrors('customPrint.0')
        ->assertHasNoErrors('customPrint.1')
        ->set('customFoil', 'cosmos')
        ->call('useCustomVariant', 0)
        ->assertHasNoErrors('customPrint.0')
        ->assertSet('customFoil', null)
        ->assertSet('customStamp', null)
        ->assertSeeHtml('aria-label="Use this print for variant 1');
});

test('saving another field keeps a manual price typed but not yet saved', function () {
    Role::findOrCreate('super-admin');
    $admin = User::factory()->create();
    $admin->assignRole('super-admin');
    $this->actingAs($admin);
    [$card] = prizePackBudew($admin);

    Livewire::test(CardEditor::class)
        ->call('openCardEditor', $card->id)
        ->set('editingRows.0.manual_price', '1.25')
        ->set('editingRows.0.condition', 'LP')
        ->call('updateRow', 0)
        ->assertSet('editingRows.0.manual_price', '1.25');
});

test('a manual price is allowed when the print\'s only market price has frozen', function () {
    Role::findOrCreate('super-admin');
    $admin = User::factory()->create();
    $admin->assignRole('super-admin');
    $this->actingAs($admin);
    [$card, $item] = prizePackBudew($admin);
    $item->update(['variant' => 'normal']);
    // 30th Celebration's shape: TCGplayer froze 12 days ago, cardmarket syncs daily as 'default'.
    CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'tcgplayer', 'variant' => 'normal', 'captured_on' => today()->subDays(12), 'currency' => 'USD', 'market_minor' => 1231]);
    CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'cardmarket', 'variant' => 'default', 'captured_on' => today(), 'currency' => 'EUR', 'market_minor' => 1522]);

    Livewire::test(CardEditor::class)
        ->call('openCardEditor', $card->id)
        ->set('editingRows.0.manual_price', '5.71')
        ->call('saveManualPrice', 0)
        ->assertHasNoErrors();

    expect(CardPriceSnapshot::where('source', 'manual')->where('variant', 'normal')->value('market_minor'))->toBe(571);
    expect((new CardPriceResolver)->resolveForVariant($card->fresh(), 'normal')->source)->toBe('manual');
});

test('a manual price is allowed when every market price froze before the listing window', function () {
    Role::findOrCreate('super-admin');
    $admin = User::factory()->create();
    $admin->assignRole('super-admin');
    $this->actingAs($admin);
    [$card, $item] = prizePackBudew($admin);
    $item->update(['variant' => 'normal']);
    CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'tcgplayer', 'variant' => 'normal', 'captured_on' => today()->subDays(40), 'currency' => 'USD', 'market_minor' => 1231]);

    Livewire::test(CardEditor::class)
        ->call('openCardEditor', $card->id)
        ->set('editingRows.0.manual_price', '5.71')
        ->call('saveManualPrice', 0)
        ->assertHasNoErrors();

    expect(CardPriceSnapshot::where('source', 'manual')->count())->toBe(1);
});

test('a manual price is recorded as entered by hand', function () {
    Role::findOrCreate('super-admin');
    $admin = User::factory()->create();
    $admin->assignRole('super-admin');
    $this->actingAs($admin);
    [$card] = prizePackBudew($admin);

    Livewire::test(CardEditor::class)
        ->call('openCardEditor', $card->id)
        ->set('editingRows.0.manual_price', '0.70')
        ->call('saveManualPrice', 0)
        ->assertHasNoErrors();

    expect(CardPriceSnapshot::where('source', 'manual')->value('origin'))->toBe('hand');
});

test('the editor opens when a host dispatches open-card-editor', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);
    $card = Card::create(['tcgdex_id' => 'me05-116', 'set_id' => $set->id, 'local_id' => '116', 'name' => 'Mega Darkrai ex']);
    $collection = Collection::factory()->for($user)->create(['name' => 'Main', 'slug' => 'main']);
    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => 'me05-116', 'condition' => 'NM', 'quantity' => 1]);

    Livewire::test(CardEditor::class)
        ->dispatch('open-card-editor', cardId: $card->id)
        ->assertSet('editingCardId', $card->id)
        ->assertSee('Mega Darkrai ex');
});

test('saving a row tells hosts which card changed', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);
    $card = Card::create(['tcgdex_id' => 'me05-116', 'set_id' => $set->id, 'local_id' => '116', 'name' => 'Mega Darkrai ex']);
    $collection = Collection::factory()->for($user)->create(['name' => 'Main', 'slug' => 'main']);
    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => 'me05-116', 'condition' => 'NM', 'quantity' => 1]);

    Livewire::test(CardEditor::class)
        ->call('openCardEditor', $card->id)
        ->set('editingRows.0.quantity', 4)
        ->call('updateRow', 0)
        ->assertDispatched('card-copies-changed', cardId: $card->id);
});

test('saving a manual price tells hosts which card changed', function () {
    Role::findOrCreate('super-admin');
    $admin = User::factory()->create();
    $admin->assignRole('super-admin');
    $this->actingAs($admin);
    [$card] = prizePackBudew($admin);

    Livewire::test(CardEditor::class)
        ->call('openCardEditor', $card->id)
        ->set('editingRows.0.manual_price', '0.70')
        ->call('saveManualPrice', 0)
        ->assertDispatched('card-copies-changed', cardId: $card->id);
});

test('a refused manual price leaves the list alone', function () {
    Role::findOrCreate('super-admin');
    $admin = User::factory()->create();
    $admin->assignRole('super-admin');
    $this->actingAs($admin);
    [$card, $item] = prizePackBudew($admin);
    $item->update(['variant' => 'normal']);
    CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'tcgplayer', 'variant' => 'normal', 'captured_on' => today(), 'currency' => 'USD', 'market_minor' => 1231]);

    Livewire::test(CardEditor::class)
        ->call('openCardEditor', $card->id)
        ->set('editingRows.0.manual_price', '5.71')
        ->call('saveManualPrice', 0)
        ->assertHasErrors(['editingRows.0.manual_price'])
        ->assertNotDispatched('card-copies-changed');

    expect(CardPriceSnapshot::where('source', 'manual')->count())->toBe(0);
});

test('adding a variant row tells hosts which card changed', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);
    $card = Card::create(['tcgdex_id' => 'me05-116', 'set_id' => $set->id, 'local_id' => '116', 'name' => 'Mega Darkrai ex']);
    $collection = Collection::factory()->for($user)->create(['name' => 'Main', 'slug' => 'main']);
    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => 'me05-116', 'condition' => 'NM', 'quantity' => 1]);

    Livewire::test(CardEditor::class)
        ->call('openCardEditor', $card->id)
        ->call('addVariantRow')
        ->assertDispatched('card-copies-changed', cardId: $card->id);
});

test('removing a variant row tells hosts which card changed', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);
    $card = Card::create(['tcgdex_id' => 'me05-116', 'set_id' => $set->id, 'local_id' => '116', 'name' => 'Mega Darkrai ex']);
    $collection = Collection::factory()->for($user)->create(['name' => 'Main', 'slug' => 'main']);
    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => 'me05-116', 'condition' => 'NM', 'quantity' => 1]);

    Livewire::test(CardEditor::class)
        ->call('openCardEditor', $card->id)
        ->call('confirmRemoveRow', 0)
        ->call('removeVariantRow', 0)
        ->assertDispatched('card-copies-changed', cardId: $card->id);
});

test('a host can open the editor for a card the owner has no copies of', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);
    $card = Card::create(['tcgdex_id' => 'me05-116', 'set_id' => $set->id, 'local_id' => '116', 'name' => 'Mega Darkrai ex', 'variants' => ['normal' => true, 'holo' => true]]);

    Livewire::test(CardEditor::class)
        ->dispatch('open-card-editor', cardId: $card->id, allowEmpty: true)
        ->assertSet('editingCardId', $card->id)
        ->assertSet('editingRows', [])
        ->assertSet('editingAvailableVariants', ['normal', 'holofoil'])
        ->assertSee('Mega Darkrai ex')
        ->assertSee('+ Add a copy');
});

test('opening the empty editor writes nothing and the first added copy creates the collection', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);
    $card = Card::create(['tcgdex_id' => 'me05-116', 'set_id' => $set->id, 'local_id' => '116', 'name' => 'Mega Darkrai ex']);

    $component = Livewire::test(CardEditor::class)
        ->call('openCardEditor', $card->id, true)
        ->assertSet('editingCollectionId', null);

    expect(Collection::withoutGlobalScopes()->where('user_id', $user->id)->exists())->toBeFalse();

    $component->call('addVariantRow')->assertCount('editingRows', 1);

    $collection = Collection::where('slug', 'my-collection')->sole();
    expect($collection->is_public)->toBeFalse()
        ->and(CollectionItem::sole()->collection_id)->toBe($collection->id);
});

test('opening an unknown card is a 404 and creates no collection', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    Livewire::test(CardEditor::class)
        ->call('openCardEditor', 999999, true)
        ->assertStatus(404);

    expect(Collection::withoutGlobalScopes()->where('user_id', $user->id)->exists())->toBeFalse();
});

test('the empty open never shows or touches another users copies of the card', function () {
    $owner = User::factory()->create();
    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);
    $card = Card::create(['tcgdex_id' => 'me05-116', 'set_id' => $set->id, 'local_id' => '116', 'name' => 'Mega Darkrai ex']);
    $ownerCollection = Collection::withoutGlobalScopes()->create(['user_id' => $owner->id, 'name' => 'Mine', 'slug' => 'my-collection', 'is_public' => false]);
    $item = CollectionItem::create(['collection_id' => $ownerCollection->id, 'card_id' => $card->id, 'card_tcgdex_id' => $card->tcgdex_id, 'condition' => 'NM', 'quantity' => 2]);

    $intruder = User::factory()->create();
    $this->actingAs($intruder);

    $component = Livewire::test(CardEditor::class)
        ->call('openCardEditor', $card->id, true)
        ->assertSet('editingRows', [])
        ->assertSet('editingCollectionId', null)
        ->call('addVariantRow');

    $own = Collection::withoutGlobalScopes()->where('user_id', $intruder->id)->sole();
    expect($own->id)->not->toBe($ownerCollection->id)
        ->and($item->fresh()->quantity)->toBe(2)
        ->and(CollectionItem::where('collection_id', $own->id)->count())->toBe(1);
});

test('adding a copy from the empty editor creates the first row', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);
    $card = Card::create(['tcgdex_id' => 'me05-116', 'set_id' => $set->id, 'local_id' => '116', 'name' => 'Mega Darkrai ex']);

    Livewire::test(CardEditor::class)
        ->call('openCardEditor', $card->id, true)
        ->call('addVariantRow')
        ->assertCount('editingRows', 1);

    $item = CollectionItem::sole();
    expect($item->card_id)->toBe($card->id)
        ->and($item->condition)->toBe('NM')
        ->and($item->quantity)->toBe(1);
});

test('the strict open still refuses a card the owner has nothing of', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);
    $card = Card::create(['tcgdex_id' => 'me05-116', 'set_id' => $set->id, 'local_id' => '116', 'name' => 'Mega Darkrai ex']);

    Livewire::test(CardEditor::class)
        ->call('openCardEditor', $card->id)
        ->assertStatus(404);
});

test('the panel presentation renders a drawer with the same dialog semantics as the modal', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);
    $card = Card::create(['tcgdex_id' => 'me05-116', 'set_id' => $set->id, 'local_id' => '116', 'name' => 'Mega Darkrai ex']);

    $html = Livewire::test(CardEditor::class, ['presentation' => 'panel'])
        ->call('openCardEditor', $card->id, true)
        ->html();

    expect($html)->toContain('nw-drawer-backdrop')
        ->and($html)->toContain('nw-drawer')
        ->and($html)->toContain('role="dialog"')
        ->and($html)->toContain('aria-modal="true"')
        ->and($html)->not->toContain('max-width: 640px');
});

test('an unknown presentation falls back to the modal', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);
    $card = Card::create(['tcgdex_id' => 'me05-116', 'set_id' => $set->id, 'local_id' => '116', 'name' => 'Mega Darkrai ex']);

    $html = Livewire::test(CardEditor::class, ['presentation' => 'sideways'])
        ->call('openCardEditor', $card->id, true)
        ->html();

    expect($html)->toContain('modal-in')->and($html)->not->toContain('nw-drawer');
});
