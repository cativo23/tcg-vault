<?php

declare(strict_types=1);

use App\Jobs\ImportSetJob;
use App\Models\User;
use App\Modules\Catalog\Contracts\CardCatalogProvider;
use App\Modules\Catalog\Data\CardDetailData;
use App\Modules\Catalog\Data\PriceEntryData;
use App\Modules\Catalog\Data\SetSummaryData;
use App\Modules\Catalog\Models\Card;
use App\Modules\Catalog\Models\Set;
use App\Modules\Collection\Models\Collection;
use App\Modules\Collection\Models\CollectionItem;
use App\Modules\Collection\Services\CollectionService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Spatie\LaravelData\DataCollection;

test('addItem syncs the card into the Catalog and creates a CollectionItem', function () {
    // The test queue connection is 'sync', so an un-faked ImportSetJob
    // dispatch would run inline against this test's provider mock,
    // which never stubs listSetCardIds() — not this test's concern.
    Queue::fake();

    $user = User::factory()->create();
    $this->actingAs($user);
    $collection = Collection::factory()->for($user)->create(['name' => 'Main', 'slug' => 'main']);

    $provider = Mockery::mock(CardCatalogProvider::class);
    $provider->shouldReceive('findCard')->with('me05-116')->once()->andReturn(new CardDetailData(
        tcgdexId: 'me05-116', setTcgdexId: 'me05', localId: '116', name: 'Mega Darkrai ex',
        rarity: 'Special Illustration Rare', variants: [], officialImageUrl: null,
        prices: new DataCollection(PriceEntryData::class, []), raw: ['id' => 'me05-116'],
    ));
    $provider->shouldReceive('findSet')->with('me05')->once()->andReturn(new SetSummaryData(
        tcgdexId: 'me05', name: 'Pitch Black', series: null, releasedOn: null, cardCount: null, logoUrl: null,
    ));
    $this->app->instance(CardCatalogProvider::class, $provider);

    $service = app(CollectionService::class);
    $item = $service->addItem($collection, 'me05-116', [
        'variant' => 'holofoil',
        'condition' => 'NM',
        'grade_company' => null,
        'grade_value' => null,
        'quantity' => 1,
        'notes' => 'Pulled at a local shop',
        'photo_path' => null,
    ]);

    expect($item->collection_id)->toBe($collection->id);
    expect($item->card_tcgdex_id)->toBe('me05-116');
    expect($item->card->name)->toBe('Mega Darkrai ex');
    expect($item->condition)->toBe('NM');
    expect($item->notes)->toBe('Pulled at a local shop');
});

test('addItem defaults quantity to 1 when not provided', function () {
    Queue::fake();

    $user = User::factory()->create();
    $this->actingAs($user);
    $collection = Collection::factory()->for($user)->create(['name' => 'Main', 'slug' => 'main']);

    $provider = Mockery::mock(CardCatalogProvider::class);
    $provider->shouldReceive('findCard')->andReturn(new CardDetailData(
        tcgdexId: 'me05-007', setTcgdexId: 'me05', localId: '007', name: 'Heatran',
        rarity: 'Rare Holo', variants: [], officialImageUrl: null,
        prices: new DataCollection(PriceEntryData::class, []), raw: [],
    ));
    $provider->shouldReceive('findSet')->andReturn(new SetSummaryData(
        tcgdexId: 'me05', name: 'Pitch Black', series: null, releasedOn: null, cardCount: null, logoUrl: null,
    ));
    $this->app->instance(CardCatalogProvider::class, $provider);

    $service = app(CollectionService::class);
    $item = $service->addItem($collection, 'me05-007', ['condition' => 'LP']);

    expect($item->quantity)->toBe(1);
});

test('adding an identical printing again increments quantity instead of creating a new row', function () {
    Queue::fake();

    $user = User::factory()->create();
    $this->actingAs($user);
    $collection = Collection::factory()->for($user)->create(['name' => 'Main', 'slug' => 'main']);

    $provider = Mockery::mock(CardCatalogProvider::class);
    $provider->shouldReceive('findCard')->with('me05-116')->twice()->andReturn(new CardDetailData(
        tcgdexId: 'me05-116', setTcgdexId: 'me05', localId: '116', name: 'Mega Darkrai ex',
        rarity: 'Special Illustration Rare', variants: [], officialImageUrl: null,
        prices: new DataCollection(PriceEntryData::class, []), raw: ['id' => 'me05-116'],
    ));
    $provider->shouldReceive('findSet')->with('me05')->once()->andReturn(new SetSummaryData(
        tcgdexId: 'me05', name: 'Pitch Black', series: null, releasedOn: null, cardCount: null, logoUrl: null,
    ));
    $this->app->instance(CardCatalogProvider::class, $provider);

    $service = app(CollectionService::class);
    $first = $service->addItem($collection, 'me05-116', ['condition' => 'NM', 'quantity' => 1]);
    $second = $service->addItem($collection, 'me05-116', ['condition' => 'NM', 'quantity' => 2]);

    expect($second->id)->toBe($first->id);
    expect($first->fresh()->quantity)->toBe(3);
    expect(CollectionItem::where('collection_id', $collection->id)->count())->toBe(1);
});

test('a quantity merge does not overwrite notes from the original item', function () {
    Queue::fake();

    $user = User::factory()->create();
    $this->actingAs($user);
    $collection = Collection::factory()->for($user)->create(['name' => 'Main', 'slug' => 'main']);

    $provider = Mockery::mock(CardCatalogProvider::class);
    $provider->shouldReceive('findCard')->with('me05-116')->twice()->andReturn(new CardDetailData(
        tcgdexId: 'me05-116', setTcgdexId: 'me05', localId: '116', name: 'Mega Darkrai ex',
        rarity: 'Special Illustration Rare', variants: [], officialImageUrl: null,
        prices: new DataCollection(PriceEntryData::class, []), raw: ['id' => 'me05-116'],
    ));
    $provider->shouldReceive('findSet')->with('me05')->once()->andReturn(new SetSummaryData(
        tcgdexId: 'me05', name: 'Pitch Black', series: null, releasedOn: null, cardCount: null, logoUrl: null,
    ));
    $this->app->instance(CardCatalogProvider::class, $provider);

    $service = app(CollectionService::class);
    $first = $service->addItem($collection, 'me05-116', [
        'condition' => 'NM',
        'quantity' => 1,
        'notes' => 'Original notes',
        'photo_path' => null,
    ]);
    $second = $service->addItem($collection, 'me05-116', [
        'condition' => 'NM',
        'quantity' => 1,
        'notes' => 'Different notes from the second call',
    ]);

    expect($second->id)->toBe($first->id);
    expect($first->fresh()->quantity)->toBe(2);
    expect($first->fresh()->notes)->toBe('Original notes');
});

test('a quantity merge adopts the new photo_path when the existing item has none', function () {
    Queue::fake();
    Storage::fake('collection-photos');

    $user = User::factory()->create();
    $this->actingAs($user);
    $collection = Collection::factory()->for($user)->create(['name' => 'Main', 'slug' => 'main']);

    $provider = Mockery::mock(CardCatalogProvider::class);
    $provider->shouldReceive('findCard')->with('me05-116')->twice()->andReturn(new CardDetailData(
        tcgdexId: 'me05-116', setTcgdexId: 'me05', localId: '116', name: 'Mega Darkrai ex',
        rarity: 'Special Illustration Rare', variants: [], officialImageUrl: null,
        prices: new DataCollection(PriceEntryData::class, []), raw: ['id' => 'me05-116'],
    ));
    $provider->shouldReceive('findSet')->with('me05')->once()->andReturn(new SetSummaryData(
        tcgdexId: 'me05', name: 'Pitch Black', series: null, releasedOn: null, cardCount: null, logoUrl: null,
    ));
    $this->app->instance(CardCatalogProvider::class, $provider);

    Storage::disk('collection-photos')->put('new-photo.jpg', 'contents');

    $service = app(CollectionService::class);
    $first = $service->addItem($collection, 'me05-116', ['condition' => 'NM', 'quantity' => 1]);
    $second = $service->addItem($collection, 'me05-116', [
        'condition' => 'NM',
        'quantity' => 1,
        'photo_path' => 'new-photo.jpg',
    ]);

    expect($second->id)->toBe($first->id);
    expect($first->fresh()->quantity)->toBe(2);
    expect($first->fresh()->photo_path)->toBe('new-photo.jpg');
    Storage::disk('collection-photos')->assertExists('new-photo.jpg');
});

test('a quantity merge discards and deletes the new photo when the existing item already has one', function () {
    Queue::fake();
    Storage::fake('collection-photos');

    $user = User::factory()->create();
    $this->actingAs($user);
    $collection = Collection::factory()->for($user)->create(['name' => 'Main', 'slug' => 'main']);

    $provider = Mockery::mock(CardCatalogProvider::class);
    $provider->shouldReceive('findCard')->with('me05-116')->twice()->andReturn(new CardDetailData(
        tcgdexId: 'me05-116', setTcgdexId: 'me05', localId: '116', name: 'Mega Darkrai ex',
        rarity: 'Special Illustration Rare', variants: [], officialImageUrl: null,
        prices: new DataCollection(PriceEntryData::class, []), raw: ['id' => 'me05-116'],
    ));
    $provider->shouldReceive('findSet')->with('me05')->once()->andReturn(new SetSummaryData(
        tcgdexId: 'me05', name: 'Pitch Black', series: null, releasedOn: null, cardCount: null, logoUrl: null,
    ));
    $this->app->instance(CardCatalogProvider::class, $provider);

    Storage::disk('collection-photos')->put('original-photo.jpg', 'contents');
    Storage::disk('collection-photos')->put('second-photo.jpg', 'contents');

    $service = app(CollectionService::class);
    $first = $service->addItem($collection, 'me05-116', [
        'condition' => 'NM',
        'quantity' => 1,
        'photo_path' => 'original-photo.jpg',
    ]);
    $second = $service->addItem($collection, 'me05-116', [
        'condition' => 'NM',
        'quantity' => 1,
        'photo_path' => 'second-photo.jpg',
    ]);

    expect($second->id)->toBe($first->id);
    expect($first->fresh()->quantity)->toBe(2);
    expect($first->fresh()->photo_path)->toBe('original-photo.jpg');
    Storage::disk('collection-photos')->assertExists('original-photo.jpg');
    Storage::disk('collection-photos')->assertMissing('second-photo.jpg');
});

test('a different variant or condition of the same card creates a separate row, not a merge', function () {
    Queue::fake();

    $user = User::factory()->create();
    $this->actingAs($user);
    $collection = Collection::factory()->for($user)->create(['name' => 'Main', 'slug' => 'main']);

    $provider = Mockery::mock(CardCatalogProvider::class);
    $provider->shouldReceive('findCard')->with('me05-116')->twice()->andReturn(new CardDetailData(
        tcgdexId: 'me05-116', setTcgdexId: 'me05', localId: '116', name: 'Mega Darkrai ex',
        rarity: 'Special Illustration Rare', variants: [], officialImageUrl: null,
        prices: new DataCollection(PriceEntryData::class, []), raw: ['id' => 'me05-116'],
    ));
    $provider->shouldReceive('findSet')->with('me05')->once()->andReturn(new SetSummaryData(
        tcgdexId: 'me05', name: 'Pitch Black', series: null, releasedOn: null, cardCount: null, logoUrl: null,
    ));
    $this->app->instance(CardCatalogProvider::class, $provider);

    $service = app(CollectionService::class);
    $service->addItem($collection, 'me05-116', ['condition' => 'NM', 'quantity' => 1]);
    $service->addItem($collection, 'me05-116', ['condition' => 'LP', 'quantity' => 1]); // different condition

    expect(CollectionItem::where('collection_id', $collection->id)->count())->toBe(2);
});

test('adding the first card from a brand-new set dispatches ImportSetJob to backfill the rest', function () {
    Queue::fake();

    $user = User::factory()->create();
    $this->actingAs($user);
    $collection = Collection::factory()->for($user)->create(['name' => 'Main', 'slug' => 'main']);

    // The Catalog is global — a set only needs backfilling ONCE, no
    // matter which user's addItem() call happens to trigger it.
    $provider = Mockery::mock(CardCatalogProvider::class);
    $provider->shouldReceive('findCard')->with('me05-116')->once()->andReturn(new CardDetailData(
        tcgdexId: 'me05-116', setTcgdexId: 'me05', localId: '116', name: 'Mega Darkrai ex',
        rarity: 'Special Illustration Rare', variants: [], officialImageUrl: null,
        prices: new DataCollection(PriceEntryData::class, []), raw: ['id' => 'me05-116'],
    ));
    $provider->shouldReceive('findSet')->with('me05')->once()->andReturn(new SetSummaryData(
        tcgdexId: 'me05', name: 'Pitch Black', series: null, releasedOn: null, cardCount: 84, logoUrl: null,
    ));
    $this->app->instance(CardCatalogProvider::class, $provider);

    app(CollectionService::class)->addItem($collection, 'me05-116', ['condition' => 'NM', 'quantity' => 1]);

    Queue::assertPushed(ImportSetJob::class, fn (ImportSetJob $job) => $job->setTcgdexId === 'me05');
});

test('addItem stores needs_variant_review when passed true', function () {
    Queue::fake();

    $user = User::factory()->create();
    $this->actingAs($user);
    $collection = Collection::factory()->for($user)->create(['name' => 'Main', 'slug' => 'main']);

    $provider = Mockery::mock(CardCatalogProvider::class);
    $provider->shouldReceive('findCard')->with('me05-116')->once()->andReturn(new CardDetailData(
        tcgdexId: 'me05-116', setTcgdexId: 'me05', localId: '116', name: 'Mega Darkrai ex',
        rarity: 'Special Illustration Rare', variants: [], officialImageUrl: null,
        prices: new DataCollection(PriceEntryData::class, []), raw: ['id' => 'me05-116'],
    ));
    $provider->shouldReceive('findSet')->with('me05')->once()->andReturn(new SetSummaryData(
        tcgdexId: 'me05', name: 'Pitch Black', series: null, releasedOn: null, cardCount: null, logoUrl: null,
    ));
    $this->app->instance(CardCatalogProvider::class, $provider);

    $service = app(CollectionService::class);
    $item = $service->addItem($collection, 'me05-116', [
        'condition' => 'NM',
        'quantity' => 3,
        'needs_variant_review' => true,
    ]);

    expect($item->needs_variant_review)->toBeTrue();
});

test('addItem defaults needs_variant_review to false when not passed', function () {
    Queue::fake();

    $user = User::factory()->create();
    $this->actingAs($user);
    $collection = Collection::factory()->for($user)->create(['name' => 'Main', 'slug' => 'main']);

    $provider = Mockery::mock(CardCatalogProvider::class);
    $provider->shouldReceive('findCard')->with('me05-116')->once()->andReturn(new CardDetailData(
        tcgdexId: 'me05-116', setTcgdexId: 'me05', localId: '116', name: 'Mega Darkrai ex',
        rarity: 'Special Illustration Rare', variants: [], officialImageUrl: null,
        prices: new DataCollection(PriceEntryData::class, []), raw: ['id' => 'me05-116'],
    ));
    $provider->shouldReceive('findSet')->with('me05')->once()->andReturn(new SetSummaryData(
        tcgdexId: 'me05', name: 'Pitch Black', series: null, releasedOn: null, cardCount: null, logoUrl: null,
    ));
    $this->app->instance(CardCatalogProvider::class, $provider);

    $service = app(CollectionService::class);
    $item = $service->addItem($collection, 'me05-116', ['condition' => 'NM']);

    expect($item->needs_variant_review)->toBeFalse();
});

test('adding a card from a set that is already fully imported does not dispatch ImportSetJob again', function () {
    Queue::fake();

    $user = User::factory()->create();
    $this->actingAs($user);
    $collection = Collection::factory()->for($user)->create(['name' => 'Main', 'slug' => 'main']);

    // The set already has exactly as many Card rows as tcgdex's own
    // card_count reports — nothing left to backfill.
    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black', 'card_count' => 1]);
    Card::create(['tcgdex_id' => 'me05-116', 'set_id' => $set->id, 'local_id' => '116', 'name' => 'Mega Darkrai ex']);

    $provider = Mockery::mock(CardCatalogProvider::class);
    $provider->shouldReceive('findCard')->with('me05-116')->once()->andReturn(new CardDetailData(
        tcgdexId: 'me05-116', setTcgdexId: 'me05', localId: '116', name: 'Mega Darkrai ex',
        rarity: 'Special Illustration Rare', variants: [], officialImageUrl: null,
        prices: new DataCollection(PriceEntryData::class, []), raw: ['id' => 'me05-116'],
    ));
    // findSet is never expected — syncCard() reuses the already-known
    // set (the CatalogSyncService perf fix from the final review).
    $this->app->instance(CardCatalogProvider::class, $provider);

    app(CollectionService::class)->addItem($collection, 'me05-116', ['condition' => 'NM', 'quantity' => 1]);

    Queue::assertNotPushed(ImportSetJob::class);
});

test('the database rejects a second row with an identical identity, including when the nullable columns are null', function () {
    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);
    $card = Card::create(['tcgdex_id' => 'me05-116', 'set_id' => $set->id, 'local_id' => '116', 'name' => 'Mega Darkrai ex']);
    $collection = Collection::factory()->create();

    CollectionItem::create([
        'collection_id' => $collection->id,
        'card_id' => $card->id,
        'card_tcgdex_id' => $card->tcgdex_id,
        'variant' => null,
        'condition' => 'NM',
        'grade_company' => null,
        'grade_value' => null,
        'quantity' => 1,
    ]);

    expect(fn () => CollectionItem::create([
        'collection_id' => $collection->id,
        'card_id' => $card->id,
        'card_tcgdex_id' => $card->tcgdex_id,
        'variant' => null,
        'condition' => 'NM',
        'grade_company' => null,
        'grade_value' => null,
        'quantity' => 1,
    ]))->toThrow(QueryException::class);
});

test('an empty-string identity value from a cleared form field merges with an existing null identity', function () {
    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);
    $card = Card::create(['tcgdex_id' => 'me05-116', 'set_id' => $set->id, 'local_id' => '116', 'name' => 'Mega Darkrai ex']);
    $collection = Collection::factory()->create();

    $existing = CollectionItem::create([
        'collection_id' => $collection->id,
        'card_id' => $card->id,
        'card_tcgdex_id' => $card->tcgdex_id,
        'variant' => null,
        'condition' => 'NM',
        'grade_company' => null,
        'grade_value' => null,
        'quantity' => 1,
    ]);

    // Livewire skips ConvertEmptyStringsToNull, so a cleared <select> or
    // text input arrives here as '' rather than null — without
    // normalizing it, this would miss the existing row above (its
    // whereNull('grade_company') wouldn't match '') and either create a
    // second row or, worse, hit the new unique index and throw.
    $item = app(CollectionService::class)->addItemForCard($collection, $card, [
        'variant' => '',
        'condition' => 'NM',
        'grade_company' => '',
        'grade_value' => '',
        'quantity' => 1,
    ]);

    expect($item->id)->toBe($existing->id);
    expect(CollectionItem::where('collection_id', $collection->id)->count())->toBe(1);
    expect($item->fresh()->quantity)->toBe(2);
});

test('a genuine insert race for the same identity merges into the winning row instead of erroring', function () {
    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);
    $card = Card::create(['tcgdex_id' => 'me05-116', 'set_id' => $set->id, 'local_id' => '116', 'name' => 'Mega Darkrai ex']);
    $collection = Collection::factory()->create();

    // Simulates a concurrent request's INSERT landing in the exact gap
    // between this call's own SELECT (which finds nothing) and its
    // INSERT — the DB::listen callback runs synchronously right after
    // that SELECT executes, so by the time addItemForCard() tries its
    // own INSERT, a colliding row already exists and the new unique
    // index rejects it.
    $listenerFired = false;
    DB::listen(function ($query) use (&$listenerFired, $collection, $card): void {
        if ($listenerFired || ! str_contains($query->sql, 'collection_items') || ! str_contains($query->sql, 'card_id')) {
            return;
        }

        $listenerFired = true;

        DB::table('collection_items')->insert([
            'collection_id' => $collection->id,
            'card_id' => $card->id,
            'card_tcgdex_id' => $card->tcgdex_id,
            'variant' => null,
            'condition' => 'NM',
            'grade_company' => null,
            'grade_value' => null,
            'quantity' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    });

    $item = app(CollectionService::class)->addItemForCard($collection, $card, ['condition' => 'NM', 'quantity' => 1]);

    expect(CollectionItem::where('collection_id', $collection->id)->count())->toBe(1);
    expect($item->fresh()->quantity)->toBe(2);
});
