<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Catalog\Models\Card;
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
        ->set('editingVariant', 'Reverse Holo')
        ->set('editingGradeCompany', 'PSA')
        ->set('editingGradeValue', '9')
        ->call('saveItem');

    $fresh = $item->fresh();
    expect($fresh->condition)->toBe('LP');
    expect($fresh->quantity)->toBe(3);
    expect($fresh->variant)->toBe('Reverse Holo');
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
