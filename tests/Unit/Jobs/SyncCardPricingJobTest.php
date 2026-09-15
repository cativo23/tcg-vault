<?php

declare(strict_types=1);

use App\Jobs\SyncCardPricingJob;
use App\Modules\Catalog\Contracts\CardCatalogProvider;
use App\Modules\Catalog\Data\CardDetailData;
use App\Modules\Catalog\Data\PriceEntryData;
use App\Modules\Catalog\Data\SetSummaryData;
use App\Modules\Catalog\Exceptions\CardNotFoundException;
use App\Modules\Catalog\Models\Card;
use App\Modules\Catalog\Services\CatalogSyncService;
use Illuminate\Support\Facades\Log;
use Spatie\LaravelData\DataCollection;

// CatalogSyncService is `final` and this environment has no uopz/runkit
// extension, so Mockery cannot mock it directly. Instead we mock the
// CardCatalogProvider it depends on (same pattern as
// tests/Unit/Modules/Collection/CollectionServiceTest.php) and let a REAL
// CatalogSyncService run against that fake provider.

test('the job calls CatalogSyncService::syncCard with the given tcgdex id', function () {
    $provider = Mockery::mock(CardCatalogProvider::class);
    $provider->shouldReceive('findCard')->once()->with('me05-116')->andReturn(new CardDetailData(
        tcgdexId: 'me05-116', setTcgdexId: 'me05', localId: '116', name: 'Mega Darkrai ex',
        rarity: 'Special Illustration Rare', variants: [], officialImageUrl: null,
        prices: new DataCollection(PriceEntryData::class, []), raw: ['id' => 'me05-116'],
    ));
    $provider->shouldReceive('findSet')->once()->with('me05')->andReturn(new SetSummaryData(
        tcgdexId: 'me05', name: 'Pitch Black', series: null, releasedOn: null, cardCount: null, logoUrl: null,
    ));
    $this->app->instance(CardCatalogProvider::class, $provider);

    $syncService = app(CatalogSyncService::class);
    (new SyncCardPricingJob('me05-116'))->handle($syncService);

    expect(Card::where('tcgdex_id', 'me05-116')->exists())->toBeTrue();
});

test('the job does not rethrow when the card sync fails — it logs and lets the queue retry mechanism handle it', function () {
    Log::spy();

    $provider = Mockery::mock(CardCatalogProvider::class);
    $provider->shouldReceive('findCard')
        ->with('me05-116')
        ->once()
        ->andThrow(new CardNotFoundException('gone'));
    $this->app->instance(CardCatalogProvider::class, $provider);

    // A genuinely-gone card should fail immediately (not retry 3 times
    // uselessly against an ID that will never resolve) — handle() must
    // catch CardNotFoundException/CatalogIdentityMismatchException
    // specifically and just log, while letting any OTHER exception
    // (a transient HTTP failure) propagate so the queue's own retry
    // mechanism (`$tries`) kicks in.
    $syncService = app(CatalogSyncService::class);
    (new SyncCardPricingJob('me05-116'))->handle($syncService);

    // If handle() let the CardNotFoundException propagate instead of
    // catching it, PHPUnit would fail this test right here — no separate
    // ->throwsNoExceptions() needed (and it conflicts with Pest treating
    // real assertions below as unexpected on a "no exceptions" test).
    expect(Card::where('tcgdex_id', 'me05-116')->exists())->toBeFalse();
    Log::shouldHaveReceived('warning')->once()->withArgs(
        fn (string $message, array $context) => $message === 'SyncCardPricingJob: card sync failed permanently, not retrying'
            && $context['tcgdex_card_id'] === 'me05-116',
    );
});
