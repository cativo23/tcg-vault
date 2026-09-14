<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Catalog\Contracts\CardCatalogProvider;
use App\Modules\Catalog\Data\CardDetailData;
use App\Modules\Catalog\Data\PriceEntryData;
use App\Modules\Collection\Models\Collection;
use App\Modules\Collection\Services\CollectionService;
use Carbon\CarbonImmutable;
use Spatie\LaravelData\DataCollection;

test('addItem syncs the card into the Catalog and creates a CollectionItem', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    $collection = Collection::factory()->for($user)->create(['name' => 'Main', 'slug' => 'main']);

    $provider = Mockery::mock(CardCatalogProvider::class);
    $provider->shouldReceive('findCard')->with('me05-116')->once()->andReturn(new CardDetailData(
        tcgdexId: 'me05-116', setTcgdexId: 'me05', localId: '116', name: 'Mega Darkrai ex',
        rarity: 'Special Illustration Rare', variants: [], officialImageUrl: null,
        prices: new DataCollection(PriceEntryData::class, []), raw: ['id' => 'me05-116'],
    ));
    $provider->shouldReceive('findSet')->with('me05')->once()->andReturn(new \App\Modules\Catalog\Data\SetSummaryData(
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
    $user = User::factory()->create();
    $this->actingAs($user);
    $collection = Collection::factory()->for($user)->create(['name' => 'Main', 'slug' => 'main']);

    $provider = Mockery::mock(CardCatalogProvider::class);
    $provider->shouldReceive('findCard')->andReturn(new CardDetailData(
        tcgdexId: 'me05-007', setTcgdexId: 'me05', localId: '007', name: 'Heatran',
        rarity: 'Rare Holo', variants: [], officialImageUrl: null,
        prices: new DataCollection(PriceEntryData::class, []), raw: [],
    ));
    $provider->shouldReceive('findSet')->andReturn(new \App\Modules\Catalog\Data\SetSummaryData(
        tcgdexId: 'me05', name: 'Pitch Black', series: null, releasedOn: null, cardCount: null, logoUrl: null,
    ));
    $this->app->instance(CardCatalogProvider::class, $provider);

    $service = app(CollectionService::class);
    $item = $service->addItem($collection, 'me05-007', ['condition' => 'LP']);

    expect($item->quantity)->toBe(1);
});
