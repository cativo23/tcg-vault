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
use Illuminate\Support\Facades\Queue;
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

test('a quantity merge does not overwrite notes or photo_path from the original item', function () {
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
        'photo_path' => 'second-call-photo.jpg',
    ]);

    expect($second->id)->toBe($first->id);
    expect($first->fresh()->quantity)->toBe(2);
    expect($first->fresh()->notes)->toBe('Original notes');
    expect($first->fresh()->photo_path)->toBeNull();
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
