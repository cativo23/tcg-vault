<?php

declare(strict_types=1);

use App\Modules\Catalog\Contracts\CardCatalogProvider;
use App\Modules\Catalog\Data\CardDetailData;
use App\Modules\Catalog\Data\PriceEntryData;
use App\Modules\Catalog\Data\SetSummaryData;
use App\Modules\Catalog\Exceptions\CatalogIdentityMismatchException;
use App\Modules\Catalog\Models\Card;
use App\Modules\Catalog\Models\CardPriceSnapshot;
use App\Modules\Catalog\Models\Set;
use App\Modules\Catalog\Services\CatalogSyncService;
use Carbon\CarbonImmutable;
use Spatie\LaravelData\DataCollection;

function fakeCardDetail(): CardDetailData
{
    return new CardDetailData(
        tcgdexId: 'me05-116',
        setTcgdexId: 'me05',
        localId: '116',
        name: 'Mega Darkrai ex',
        rarity: 'Special Illustration Rare',
        variants: ['holo' => true],
        officialImageUrl: 'https://assets.tcgdex.net/en/me/me05/116/high.webp',
        prices: new DataCollection(PriceEntryData::class, [
            new PriceEntryData(
                source: 'tcgplayer', variant: 'holofoil', currency: 'USD',
                marketMinor: 19524, lowMinor: 19016, trendMinor: null,
                sourceUpdatedAt: CarbonImmutable::parse('2026-09-14T17:11:20Z'),
                raw: [],
            ),
        ]),
        raw: ['id' => 'me05-116'],
    );
}

test('syncCard creates the set, the card, and its price snapshots on first sync', function () {
    $provider = Mockery::mock(CardCatalogProvider::class);
    $provider->shouldReceive('findCard')->with('me05-116')->andReturn(fakeCardDetail());
    $provider->shouldReceive('findSet')->with('me05')->andReturn(new SetSummaryData(
        tcgdexId: 'me05', name: 'Pitch Black', series: 'Mega Evolution',
        releasedOn: null, cardCount: 84, logoUrl: 'https://assets.tcgdex.net/en/me/me05/logo.png',
    ));

    $service = new CatalogSyncService($provider);
    $card = $service->syncCard('me05-116');

    expect(Set::where('tcgdex_id', 'me05')->exists())->toBeTrue();
    expect($card->name)->toBe('Mega Darkrai ex');
    expect($card->local_id)->toBe('116');
    expect($card->priceSnapshots)->toHaveCount(1);

    $snapshot = $card->priceSnapshots->first();
    expect($snapshot->source)->toBe('tcgplayer');
    expect($snapshot->variant)->toBe('holofoil');
    expect($snapshot->market_minor)->toBe(19524);
    expect($snapshot->captured_on->toDateString())->toBe(CarbonImmutable::today()->toDateString());
});

test('syncCard throws when the provider returns a card whose ID does not match what was requested', function () {
    $provider = Mockery::mock(CardCatalogProvider::class);
    $provider->shouldReceive('findCard')->with('me05-116')->andReturn(new CardDetailData(
        tcgdexId: 'me05-999', // mismatched — provider claims a different card than requested
        setTcgdexId: 'me05',
        localId: '999',
        name: 'Some Other Card',
        rarity: null,
        variants: [],
        officialImageUrl: null,
        prices: new DataCollection(PriceEntryData::class, []),
        raw: [],
    ));

    $service = new CatalogSyncService($provider);

    expect(fn () => $service->syncCard('me05-116'))
        ->toThrow(CatalogIdentityMismatchException::class);
});

test('syncing the same card twice on the same day updates the card but does not duplicate the snapshot', function () {
    $provider = Mockery::mock(CardCatalogProvider::class);
    $provider->shouldReceive('findCard')->with('me05-116')->twice()->andReturn(fakeCardDetail());
    $provider->shouldReceive('findSet')->with('me05')->once()->andReturn(new SetSummaryData(
        tcgdexId: 'me05', name: 'Pitch Black', series: null, releasedOn: null, cardCount: null, logoUrl: null,
    ));

    $service = new CatalogSyncService($provider);
    $service->syncCard('me05-116');
    $service->syncCard('me05-116');

    expect(Card::where('tcgdex_id', 'me05-116')->count())->toBe(1);
    expect(CardPriceSnapshot::count())->toBe(1);
});

test('syncCard only fetches the set once across two cards in the same set', function () {
    $secondCardDetail = new CardDetailData(
        tcgdexId: 'me05-117',
        setTcgdexId: 'me05',
        localId: '117',
        name: 'Some Other Card',
        rarity: null,
        variants: [],
        officialImageUrl: null,
        prices: new DataCollection(PriceEntryData::class, []),
        raw: [],
    );

    $provider = Mockery::mock(CardCatalogProvider::class);
    $provider->shouldReceive('findCard')->with('me05-116')->once()->andReturn(fakeCardDetail());
    $provider->shouldReceive('findCard')->with('me05-117')->once()->andReturn($secondCardDetail);
    $provider->shouldReceive('findSet')->with('me05')->once()->andReturn(new SetSummaryData(
        tcgdexId: 'me05', name: 'Pitch Black', series: null, releasedOn: null, cardCount: null, logoUrl: null,
    ));

    $service = new CatalogSyncService($provider);
    $service->syncCard('me05-116');
    $service->syncCard('me05-117');

    expect(Set::where('tcgdex_id', 'me05')->count())->toBe(1);
});

test('a rolled back card sync does not poison the memoized set for a later card in the same set', function () {
    // First card has a price entry that violates the `currency` column's
    // char(3) constraint, so its DB::transaction() rolls back AFTER the
    // Set has already been synced by syncSet(). On the old (buggy)
    // implementation, syncSet() ran inside that same transaction, so the
    // rollback would also undo the Set row while the in-memory
    // $syncedSets cache kept pointing at it — corrupting every subsequent
    // syncCard() call for that set with a foreign-key violation.
    $poisonedCardDetail = new CardDetailData(
        tcgdexId: 'me05-116',
        setTcgdexId: 'me05',
        localId: '116',
        name: 'Mega Darkrai ex',
        rarity: 'Special Illustration Rare',
        variants: ['holo' => true],
        officialImageUrl: null,
        prices: new DataCollection(PriceEntryData::class, [
            new PriceEntryData(
                source: 'tcgplayer', variant: 'holofoil', currency: 'TOO-LONG-FOR-CHAR3',
                marketMinor: 19524, lowMinor: 19016, trendMinor: null,
                sourceUpdatedAt: null, raw: [],
            ),
        ]),
        raw: [],
    );

    $healthyCardDetail = new CardDetailData(
        tcgdexId: 'me05-117',
        setTcgdexId: 'me05',
        localId: '117',
        name: 'Some Other Card',
        rarity: null,
        variants: [],
        officialImageUrl: null,
        prices: new DataCollection(PriceEntryData::class, []),
        raw: [],
    );

    $provider = Mockery::mock(CardCatalogProvider::class);
    $provider->shouldReceive('findCard')->with('me05-116')->once()->andReturn($poisonedCardDetail);
    $provider->shouldReceive('findCard')->with('me05-117')->once()->andReturn($healthyCardDetail);
    $provider->shouldReceive('findSet')->with('me05')->once()->andReturn(new SetSummaryData(
        tcgdexId: 'me05', name: 'Pitch Black', series: null, releasedOn: null, cardCount: null, logoUrl: null,
    ));

    $service = new CatalogSyncService($provider);

    expect(fn () => $service->syncCard('me05-116'))->toThrow(\Illuminate\Database\QueryException::class);

    // The Set must survive the failed card transaction intact.
    expect(Set::where('tcgdex_id', 'me05')->exists())->toBeTrue();
    expect(Card::where('tcgdex_id', 'me05-116')->exists())->toBeFalse();

    // A later card in the same set must sync cleanly against the
    // genuinely-persisted (not stale) memoized Set — no FK violation.
    $card = $service->syncCard('me05-117');

    expect($card->tcgdex_id)->toBe('me05-117');
    expect($card->set_id)->toBe(Set::where('tcgdex_id', 'me05')->sole()->id);
});
