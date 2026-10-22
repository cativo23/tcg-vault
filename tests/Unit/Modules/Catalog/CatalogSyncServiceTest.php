<?php

declare(strict_types=1);

use App\Modules\Catalog\Contracts\CardCatalogProvider;
use App\Modules\Catalog\Data\CardDetailData;
use App\Modules\Catalog\Data\PriceEntryData;
use App\Modules\Catalog\Data\SetSummaryData;
use App\Modules\Catalog\Exceptions\CatalogIdentityMismatchException;
use App\Modules\Catalog\Exceptions\SetNotFoundException;
use App\Modules\Catalog\Models\Card;
use App\Modules\Catalog\Models\CardPriceSnapshot;
use App\Modules\Catalog\Models\CardTcgplayerLink;
use App\Modules\Catalog\Models\Set;
use App\Modules\Catalog\Services\CatalogSyncService;
use App\Modules\Catalog\Services\TcgdexImageFallback;
use App\Modules\Catalog\Tcgcsv\TcgcsvFallbackPrices;
use App\Modules\Catalog\Tcgcsv\TcgcsvPriceRow;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Http;
use Spatie\LaravelData\DataCollection;
use Tests\Support\ShownPrices;

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

test('syncCard persists the sets official abbreviation when tcgdex has one', function () {
    $provider = Mockery::mock(CardCatalogProvider::class);
    $provider->shouldReceive('findCard')->with('me05-116')->andReturn(fakeCardDetail());
    $provider->shouldReceive('findSet')->with('me05')->andReturn(new SetSummaryData(
        tcgdexId: 'me05', name: 'Pitch Black', series: 'Mega Evolution',
        releasedOn: null, cardCount: 84, logoUrl: 'https://assets.tcgdex.net/en/me/me05/logo.png',
        abbreviation: 'PBL',
    ));

    $service = new CatalogSyncService($provider);
    $service->syncCard('me05-116');

    expect(Set::where('tcgdex_id', 'me05')->first()->abbreviation)->toBe('PBL');
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

test('re-syncing an already-known card from a FRESH service instance never calls findSet', function () {
    // Regression for the final whole-branch review's finding: each
    // SyncCardPricingJob (Phase 4's daily refresh) resolves its own
    // fresh CatalogSyncService instance, so the existing $syncedSets
    // in-memory memoization (proven above, within ONE instance) never
    // applies across jobs — every refreshed card was re-fetching its
    // set from tcgdex, doubling the daily HTTP-call count the design
    // spec explicitly budgeted for ("refreshing N cards is N HTTP
    // calls") to 2N. A card that's already in the Catalog has a known,
    // valid set_id — findSet() must not be called for it at all,
    // regardless of which service instance handles the refresh.
    $firstInstance = new CatalogSyncService(
        tap(Mockery::mock(CardCatalogProvider::class), function ($provider) {
            $provider->shouldReceive('findCard')->once()->with('me05-116')->andReturn(fakeCardDetail());
            $provider->shouldReceive('findSet')->once()->with('me05')->andReturn(new SetSummaryData(
                tcgdexId: 'me05', name: 'Pitch Black', series: null, releasedOn: null, cardCount: null, logoUrl: null,
            ));
        }),
    );
    $firstInstance->syncCard('me05-116');

    $refreshProvider = Mockery::mock(CardCatalogProvider::class);
    $refreshProvider->shouldReceive('findCard')->once()->with('me05-116')->andReturn(fakeCardDetail());
    $refreshProvider->shouldNotReceive('findSet');

    $freshInstance = new CatalogSyncService($refreshProvider);
    $card = $freshInstance->syncCard('me05-116');

    expect($card->set_id)->toBe(Set::where('tcgdex_id', 'me05')->sole()->id);
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

    expect(fn () => $service->syncCard('me05-116'))->toThrow(QueryException::class);

    // The Set must survive the failed card transaction intact.
    expect(Set::where('tcgdex_id', 'me05')->exists())->toBeTrue();
    expect(Card::where('tcgdex_id', 'me05-116')->exists())->toBeFalse();

    // A later card in the same set must sync cleanly against the
    // genuinely-persisted (not stale) memoized Set — no FK violation.
    $card = $service->syncCard('me05-117');

    expect($card->tcgdex_id)->toBe('me05-117');
    expect($card->set_id)->toBe(Set::where('tcgdex_id', 'me05')->sole()->id);
});

function realImageFallback(): TcgdexImageFallback
{
    return new TcgdexImageFallback('https://api.tcgdex.net/v2/en');
}

/** A provider that returns fakeCardDetail() for me05-116, with the given image. */
function providerReturningImage(?string $image): CardCatalogProvider
{
    $detail = fakeCardDetail();
    $detail->officialImageUrl = $image;
    $provider = Mockery::mock(CardCatalogProvider::class);
    $provider->shouldReceive('findCard')->with('me05-116')->andReturn($detail);
    $provider->shouldReceive('findSet')->andReturn(new SetSummaryData(
        tcgdexId: 'me05', name: 'Pitch Black', series: null, releasedOn: null, cardCount: null, logoUrl: null,
    ));

    return $provider;
}

test('a card tcgdex sends without an image gets one from the asset server when it exists', function () {
    Http::fake([
        'api.tcgdex.net/v2/en/sets/me05' => Http::response(['id' => 'me05', 'serie' => ['id' => 'me']]),
        'assets.tcgdex.net/en/me/me05/116/high.webp' => Http::response('', 200),
    ]);

    $card = (new CatalogSyncService(providerReturningImage(null), realImageFallback()))->syncCard('me05-116');

    expect($card->official_image_url)->toBe('https://assets.tcgdex.net/en/me/me05/116/high.webp');
});

test('a stored image is kept, with no asset check, when tcgdex sends none', function () {
    Http::fake(['*' => Http::response('', 503)]);
    (new CatalogSyncService(providerReturningImage('https://assets.tcgdex.net/en/me/me05/116/high.webp'), realImageFallback()))->syncCard('me05-116');

    $card = (new CatalogSyncService(providerReturningImage(null), realImageFallback()))->syncCard('me05-116');

    expect($card->official_image_url)->toBe('https://assets.tcgdex.net/en/me/me05/116/high.webp');
    Http::assertNothingSent();
});

test('an image tcgdex does send is used without any extra request', function () {
    Http::fake();

    $card = (new CatalogSyncService(providerReturningImage('https://assets.tcgdex.net/en/me/me05/116/high.webp'), realImageFallback()))->syncCard('me05-116');

    expect($card->official_image_url)->toBe('https://assets.tcgdex.net/en/me/me05/116/high.webp');
    Http::assertNothingSent();
});

test('a tcgdex sync marks the prices it writes as from tcgdex, even over a row another origin wrote', function () {
    $provider = Mockery::mock(CardCatalogProvider::class);
    $provider->shouldReceive('findCard')->with('me05-116')->twice()->andReturn(fakeCardDetail());
    $provider->shouldReceive('findSet')->with('me05')->once()->andReturn(new SetSummaryData(
        tcgdexId: 'me05', name: 'Pitch Black', series: null, releasedOn: null, cardCount: null, logoUrl: null,
    ));
    $service = new CatalogSyncService($provider);
    $service->syncCard('me05-116');
    CardPriceSnapshot::query()->update(['origin' => 'tcgcsv']);

    $service->syncCard('me05-116');

    expect(CardPriceSnapshot::sole()->origin)->toBe('tcgdex');
});

test('ensureSet creates a missing set from one findSet call', function () {
    $provider = Mockery::mock(CardCatalogProvider::class);
    $provider->shouldReceive('findSet')->once()->with('me05')->andReturn(new SetSummaryData(
        tcgdexId: 'me05', name: 'Pitch Black', series: 'Mega Evolution',
        releasedOn: null, cardCount: 84, logoUrl: 'https://assets.tcgdex.net/en/me/me05/logo.png',
    ));

    $set = (new CatalogSyncService($provider))->ensureSet('me05');

    expect($set->tcgdex_id)->toBe('me05')
        ->and($set->name)->toBe('Pitch Black')
        ->and($set->card_count)->toBe(84)
        ->and(Set::where('tcgdex_id', 'me05')->count())->toBe(1);
});

test('ensureSet returns an existing set without asking tcgdex', function () {
    $existing = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black', 'card_count' => 84]);
    $provider = Mockery::mock(CardCatalogProvider::class);
    $provider->shouldNotReceive('findSet');

    $set = (new CatalogSyncService($provider))->ensureSet('me05');

    expect($set->is($existing))->toBeTrue();
});

test('ensureSet leaves nothing behind when tcgdex does not know the set', function () {
    $provider = Mockery::mock(CardCatalogProvider::class);
    $provider->shouldReceive('findSet')->once()->andThrow(SetNotFoundException::forTcgdexId('nope'));

    expect(fn () => (new CatalogSyncService($provider))->ensureSet('nope'))
        ->toThrow(SetNotFoundException::class);
    expect(Set::count())->toBe(0);
});

test('ensureSet refuses a set tcgdex answers for under a different id', function () {
    $provider = Mockery::mock(CardCatalogProvider::class);
    $provider->shouldReceive('findSet')->once()->andReturn(new SetSummaryData(
        tcgdexId: 'sv08', name: 'Surging Sparks', series: null, releasedOn: null, cardCount: 191, logoUrl: null,
    ));

    expect(fn () => (new CatalogSyncService($provider))->ensureSet('me05'))
        ->toThrow(CatalogIdentityMismatchException::class);
    expect(Set::count())->toBe(0);
});

test('a re-synced tcgdex price is the one the next gallery render shows', function () {
    $priced = fn (int $minor) => new CardDetailData(
        tcgdexId: 'me05-116', setTcgdexId: 'me05', localId: '116', name: 'Mega Darkrai ex', rarity: null, variants: ['holo' => true],
        officialImageUrl: 'https://assets.tcgdex.net/en/me/me05/116/high.webp', raw: ['id' => 'me05-116'],
        prices: new DataCollection(PriceEntryData::class, [
            new PriceEntryData(source: 'tcgplayer', variant: 'holofoil', currency: 'USD', marketMinor: $minor, lowMinor: null, trendMinor: null, sourceUpdatedAt: null, raw: []),
        ]),
    );
    $provider = Mockery::mock(CardCatalogProvider::class);
    $provider->shouldReceive('findCard')->with('me05-116')->andReturn($priced(19524), $priced(20100));
    $provider->shouldReceive('findSet')->with('me05')->andReturn(new SetSummaryData(
        tcgdexId: 'me05', name: 'Pitch Black', series: null, releasedOn: null, cardCount: null, logoUrl: null,
    ));
    $service = new CatalogSyncService($provider);
    $username = ShownPrices::ownPublicly($service->syncCard('me05-116'), 'holofoil');
    $before = ShownPrices::totals($username);

    $service->syncCard('me05-116');

    expect($before)->toBe(['USD' => 19524])
        ->and(ShownPrices::totals($username))->toBe(['USD' => 20100]);
});

const SYNC_FALLBACK_BUILD = '2026-10-21T20:05:22+00:00';
const SYNC_FALLBACK_NOW = '2026-10-22 00:40';

function linkedDarkrai(): Card
{
    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);
    $card = Card::create(['tcgdex_id' => 'me05-116', 'set_id' => $set->id, 'local_id' => '116', 'name' => 'Mega Darkrai ex']);
    CardTcgplayerLink::create(['card_id' => $card->id, 'variant' => 'holofoil', 'product_id' => 704873, 'sub_type' => 'Holofoil', 'group_id' => 24688, 'method' => 'tcgdex-price']);

    return $card;
}

/** The evening run's kept price for the card, as the store holds it. */
function keptFor(Card $card, int $market = 14207, int $productId = 704873): TcgcsvFallbackPrices
{
    config(['tcgcsv.mode' => 'fill']);
    $store = new TcgcsvFallbackPrices;
    $store->putMany([[$card->id, 'holofoil', new TcgcsvPriceRow(24688, $productId, 'Holofoil', $market, 13649)]], SYNC_FALLBACK_BUILD);

    return $store;
}

/** @param  list<PriceEntryData>  $prices */
function syncDarkrai(array $prices, ?TcgcsvFallbackPrices $store): Card
{
    $detail = new CardDetailData(
        tcgdexId: 'me05-116', setTcgdexId: 'me05', localId: '116', name: 'Mega Darkrai ex', rarity: null, variants: ['holo' => true],
        officialImageUrl: 'https://assets.tcgdex.net/en/me/me05/116/high.webp', raw: ['id' => 'me05-116'],
        prices: new DataCollection(PriceEntryData::class, $prices),
    );
    $provider = Mockery::mock(CardCatalogProvider::class);
    $provider->shouldReceive('findCard')->with('me05-116')->andReturn($detail);

    return (new CatalogSyncService($provider, null, $store))->syncCard('me05-116');
}

function tcgplayerEntry(?int $market, ?string $updated, string $variant = 'holofoil'): PriceEntryData
{
    return new PriceEntryData(
        source: 'tcgplayer', variant: $variant, currency: 'USD', marketMinor: $market, lowMinor: null, trendMinor: null,
        sourceUpdatedAt: $updated === null ? null : CarbonImmutable::parse($updated), raw: [],
    );
}

function cardmarketEntry(int $market): PriceEntryData
{
    return new PriceEntryData(source: 'cardmarket', variant: 'default', currency: 'EUR', marketMinor: $market, lowMinor: null, trendMinor: null, sourceUpdatedAt: CarbonImmutable::parse('2026-10-21T13:13:25Z'), raw: []);
}

function todaysTcgplayer(Card $card): ?CardPriceSnapshot
{
    return CardPriceSnapshot::where('card_id', $card->id)->where('source', 'tcgplayer')->where('variant', 'holofoil')
        ->where('captured_on', CarbonImmutable::today()->toDateString())->first();
}

test('a TCGplayer price tcgdex last updated over three days ago is replaced by tcgcsv\'s in the same sync', function () {
    $this->travelTo(CarbonImmutable::parse(SYNC_FALLBACK_NOW, 'UTC'));
    $card = linkedDarkrai();

    syncDarkrai([tcgplayerEntry(636, '2026-10-15T15:01:26Z')], keptFor($card));

    $row = todaysTcgplayer($card);
    expect([$row->origin, $row->currency, $row->market_minor, $row->low_minor])->toBe(['tcgcsv', 'USD', 14207, 13649]);
    expect($row->source_updated_at->toIso8601String())->toBe(SYNC_FALLBACK_BUILD);
});

test('a linked print tcgdex sends no TCGplayer price for gets tcgcsv\'s in the same sync, and cardmarket\'s row is still written', function () {
    $this->travelTo(CarbonImmutable::parse(SYNC_FALLBACK_NOW, 'UTC'));
    $card = linkedDarkrai();

    syncDarkrai([cardmarketEntry(21322)], keptFor($card));

    expect(todaysTcgplayer($card)->only(['origin', 'market_minor']))->toBe(['origin' => 'tcgcsv', 'market_minor' => 14207]);
    expect(CardPriceSnapshot::where('card_id', $card->id)->where('source', 'cardmarket')->sole()->origin)->toBe('tcgdex');
});

test('a TCGplayer price with no market price counts as missing and is replaced', function () {
    $this->travelTo(CarbonImmutable::parse(SYNC_FALLBACK_NOW, 'UTC'));
    $card = linkedDarkrai();

    syncDarkrai([tcgplayerEntry(0, '2026-10-21T22:00:00Z')], keptFor($card));

    expect(todaysTcgplayer($card)->only(['origin', 'market_minor']))->toBe(['origin' => 'tcgcsv', 'market_minor' => 14207]);
});

test('a TCGplayer price updated within three days is kept even with a tcgcsv price stored', function () {
    $this->travelTo(CarbonImmutable::parse(SYNC_FALLBACK_NOW, 'UTC'));
    $card = linkedDarkrai();

    syncDarkrai([tcgplayerEntry(13623, '2026-10-20T08:00:00Z')], keptFor($card));

    expect(todaysTcgplayer($card)->only(['origin', 'market_minor']))->toBe(['origin' => 'tcgdex', 'market_minor' => 13623]);
});

test('a TCGplayer price with no update date is kept', function () {
    $this->travelTo(CarbonImmutable::parse(SYNC_FALLBACK_NOW, 'UTC'));
    $card = linkedDarkrai();

    syncDarkrai([tcgplayerEntry(13623, null)], keptFor($card));

    expect(todaysTcgplayer($card)->only(['origin', 'market_minor']))->toBe(['origin' => 'tcgdex', 'market_minor' => 13623]);
});

test('with no tcgcsv price stored a stale TCGplayer price is written as before', function () {
    $this->travelTo(CarbonImmutable::parse(SYNC_FALLBACK_NOW, 'UTC'));
    $card = linkedDarkrai();

    syncDarkrai([tcgplayerEntry(636, '2026-10-15T15:01:26Z')], new TcgcsvFallbackPrices);

    expect(todaysTcgplayer($card)->only(['origin', 'market_minor']))->toBe(['origin' => 'tcgdex', 'market_minor' => 636]);
});

test('a stored tcgcsv price for another product than the link now points at is ignored', function () {
    $this->travelTo(CarbonImmutable::parse(SYNC_FALLBACK_NOW, 'UTC'));
    $card = linkedDarkrai();

    syncDarkrai([tcgplayerEntry(636, '2026-10-15T15:01:26Z')], keptFor($card, productId: 999999));

    expect(todaysTcgplayer($card)->only(['origin', 'market_minor']))->toBe(['origin' => 'tcgdex', 'market_minor' => 636]);
});

test('in shadow mode a stale TCGplayer price is written as before', function () {
    $this->travelTo(CarbonImmutable::parse(SYNC_FALLBACK_NOW, 'UTC'));
    $card = linkedDarkrai();
    $store = keptFor($card);
    config(['tcgcsv.mode' => 'shadow']);

    syncDarkrai([tcgplayerEntry(636, '2026-10-15T15:01:26Z')], $store);

    expect(todaysTcgplayer($card)->only(['origin', 'market_minor']))->toBe(['origin' => 'tcgdex', 'market_minor' => 636]);
});

test('the service the container builds falls back too, since every caller resolves it that way', function () {
    $this->travelTo(CarbonImmutable::parse(SYNC_FALLBACK_NOW, 'UTC'));
    $card = linkedDarkrai();
    keptFor($card);
    $provider = Mockery::mock(CardCatalogProvider::class);
    $provider->shouldReceive('findCard')->with('me05-116')->andReturn(new CardDetailData(
        tcgdexId: 'me05-116', setTcgdexId: 'me05', localId: '116', name: 'Mega Darkrai ex', rarity: null, variants: ['holo' => true],
        officialImageUrl: 'https://assets.tcgdex.net/en/me/me05/116/high.webp', raw: ['id' => 'me05-116'],
        prices: new DataCollection(PriceEntryData::class, [tcgplayerEntry(636, '2026-10-15T15:01:26Z')]),
    ));
    app()->instance(CardCatalogProvider::class, $provider);

    app(CatalogSyncService::class)->syncCard('me05-116');

    expect(todaysTcgplayer($card)->only(['origin', 'market_minor']))->toBe(['origin' => 'tcgcsv', 'market_minor' => 14207]);
});

test('after a sync that falls back, the collection shows tcgcsv\'s USD price instead of cardmarket\'s EUR', function () {
    $this->travelTo(CarbonImmutable::parse(SYNC_FALLBACK_NOW, 'UTC'));
    $card = linkedDarkrai();
    $synced = syncDarkrai([cardmarketEntry(21322)], keptFor($card));
    $username = ShownPrices::ownPublicly($synced, 'holofoil');

    expect(ShownPrices::totals($username))->toBe(['USD' => 14207]);
});
