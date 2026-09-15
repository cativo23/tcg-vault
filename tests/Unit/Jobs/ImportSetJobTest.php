<?php

declare(strict_types=1);

use App\Jobs\ImportSetJob;
use App\Modules\Catalog\Contracts\CardCatalogProvider;
use App\Modules\Catalog\Data\CardDetailData;
use App\Modules\Catalog\Data\PriceEntryData;
use App\Modules\Catalog\Data\SetSummaryData;
use App\Modules\Catalog\Exceptions\CardNotFoundException;
use App\Modules\Catalog\Models\Card;
use App\Modules\Catalog\Services\CatalogSyncService;
use Illuminate\Support\Facades\Log;
use Spatie\LaravelData\DataCollection;

// Same CardCatalogProvider-mocking pattern as SyncCardPricingJobTest —
// CatalogSyncService is `final`, this environment has no uopz/runkit.

function fakeSetCardDetail(string $tcgdexId, string $localId, string $name): CardDetailData
{
    return new CardDetailData(
        tcgdexId: $tcgdexId, setTcgdexId: 'me05', localId: $localId, name: $name,
        rarity: 'Common', variants: [], officialImageUrl: null,
        prices: new DataCollection(PriceEntryData::class, []), raw: ['id' => $tcgdexId],
    );
}

test('the job syncs every card id the provider lists for the set', function () {
    $provider = Mockery::mock(CardCatalogProvider::class);
    $provider->shouldReceive('listSetCardIds')->once()->with('me05')->andReturn(['me05-001', 'me05-002', 'me05-003']);
    $provider->shouldReceive('findCard')->with('me05-001')->once()->andReturn(fakeSetCardDetail('me05-001', '001', 'Card One'));
    $provider->shouldReceive('findCard')->with('me05-002')->once()->andReturn(fakeSetCardDetail('me05-002', '002', 'Card Two'));
    $provider->shouldReceive('findCard')->with('me05-003')->once()->andReturn(fakeSetCardDetail('me05-003', '003', 'Card Three'));
    $provider->shouldReceive('findSet')->once()->with('me05')->andReturn(new SetSummaryData(
        tcgdexId: 'me05', name: 'Pitch Black', series: null, releasedOn: null, cardCount: 3, logoUrl: null,
    ));
    $this->app->instance(CardCatalogProvider::class, $provider);

    $syncService = app(CatalogSyncService::class);
    (new ImportSetJob('me05'))->handle($provider, $syncService);

    expect(Card::count())->toBe(3);
    expect(Card::where('tcgdex_id', 'me05-001')->exists())->toBeTrue();
    expect(Card::where('tcgdex_id', 'me05-002')->exists())->toBeTrue();
    expect(Card::where('tcgdex_id', 'me05-003')->exists())->toBeTrue();
});

test('one bad card in the set is logged and skipped, the rest of the set still imports', function () {
    Log::spy();

    $provider = Mockery::mock(CardCatalogProvider::class);
    $provider->shouldReceive('listSetCardIds')->once()->with('me05')->andReturn(['me05-001', 'me05-gone', 'me05-003']);
    $provider->shouldReceive('findCard')->with('me05-001')->once()->andReturn(fakeSetCardDetail('me05-001', '001', 'Card One'));
    $provider->shouldReceive('findCard')->with('me05-gone')->once()->andThrow(new CardNotFoundException('gone'));
    $provider->shouldReceive('findCard')->with('me05-003')->once()->andReturn(fakeSetCardDetail('me05-003', '003', 'Card Three'));
    $provider->shouldReceive('findSet')->once()->with('me05')->andReturn(new SetSummaryData(
        tcgdexId: 'me05', name: 'Pitch Black', series: null, releasedOn: null, cardCount: 3, logoUrl: null,
    ));
    $this->app->instance(CardCatalogProvider::class, $provider);

    $syncService = app(CatalogSyncService::class);
    (new ImportSetJob('me05'))->handle($provider, $syncService);

    expect(Card::where('tcgdex_id', 'me05-001')->exists())->toBeTrue();
    expect(Card::where('tcgdex_id', 'me05-003')->exists())->toBeTrue();
    expect(Card::where('tcgdex_id', 'me05-gone')->exists())->toBeFalse();
    Log::shouldHaveReceived('warning')->once()->withArgs(
        fn (string $message, array $context) => $message === 'ImportSetJob: card sync failed permanently, skipping'
            && $context['set_tcgdex_id'] === 'me05'
            && $context['tcgdex_card_id'] === 'me05-gone',
    );
});
