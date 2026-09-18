<?php

declare(strict_types=1);

use App\Jobs\SyncCardPricingJob;
use App\Modules\Catalog\Contracts\CardCatalogProvider;
use App\Modules\Catalog\Data\CardDetailData;
use App\Modules\Catalog\Data\PriceEntryData;
use App\Modules\Catalog\Data\SetSummaryData;
use App\Modules\Catalog\Exceptions\CardNotFoundException;
use App\Modules\Catalog\Exceptions\SetNotFoundException;
use App\Modules\Catalog\Models\Card;
use App\Modules\Catalog\Services\CatalogSyncService;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Support\Facades\Log;
use Spatie\LaravelData\DataCollection;

test('a rate-limit release cannot exhaust the job\'s retries before it ever actually runs', function () {
    // Laravel's RateLimited middleware releases a job back onto the
    // queue when the shared bucket is full, and that release consumes
    // one of $tries (attempts are incremented at pop time, before the
    // middleware even runs). With only $tries=3 and thousands of jobs
    // sharing one rate-limit budget, a job could be released more than 3
    // times purely from scheduling and get marked permanently failed
    // without handle() ever running — the safety control defeating
    // itself. retryUntil() (a wall-clock deadline) supersedes
    // $tries-based exhaustion in Laravel, so rate-limit releases can
    // never starve out a job that never actually failed.
    $job = new SyncCardPricingJob('me05-116');

    expect($job->retryUntil())->toBeInstanceOf(DateTimeInterface::class);
    expect($job->retryUntil())->toBeGreaterThan(now()->addMinutes(30));
});

test('the job uses a separate, smaller rate limit when running on the imports queue than on default', function () {
    $onDefault = new SyncCardPricingJob('me05-116');
    $onImports = new SyncCardPricingJob('me05-116');
    $onImports->queue = 'imports';

    $limiterName = fn (RateLimited $middleware) => (new ReflectionProperty($middleware, 'limiterName'))->getValue($middleware);

    expect($limiterName($onDefault->middleware()[0]))->toBe('tcgdex-default');
    expect($limiterName($onImports->middleware()[0]))->toBe('tcgdex-imports');
});

test('the job is rate-limited so Horizon\'s workers can never burst tcgdex faster than the configured cap', function () {
    // tcgdex fails requests under concurrent burst load even when a
    // single ad-hoc request succeeds fine, so this job must self-limit
    // its own throughput against the API rather than relying on Horizon's
    // worker count staying small.
    $middleware = (new SyncCardPricingJob('me05-116'))->middleware();

    expect($middleware)->toHaveCount(1);
    expect($middleware[0])->toBeInstanceOf(RateLimited::class);
});

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

test('the job also treats a SetNotFoundException as permanent, not just CardNotFoundException', function () {
    // Regression for the final whole-branch review's finding: the
    // original catch list only named CardNotFoundException and
    // CatalogIdentityMismatchException, so a card whose SET can't be
    // found on tcgdex (the card itself resolves fine) would have burned
    // all 3 retries with backoff before failing — pointlessly, since
    // retrying the exact same set ID would never succeed either.
    Log::spy();

    $provider = Mockery::mock(CardCatalogProvider::class);
    $provider->shouldReceive('findCard')->once()->with('me05-116')->andReturn(new CardDetailData(
        tcgdexId: 'me05-116', setTcgdexId: 'me05', localId: '116', name: 'Mega Darkrai ex',
        rarity: 'Special Illustration Rare', variants: [], officialImageUrl: null,
        prices: new DataCollection(PriceEntryData::class, []), raw: ['id' => 'me05-116'],
    ));
    $provider->shouldReceive('findSet')->once()->with('me05')->andThrow(SetNotFoundException::forTcgdexId('me05'));
    $this->app->instance(CardCatalogProvider::class, $provider);

    $syncService = app(CatalogSyncService::class);
    (new SyncCardPricingJob('me05-116'))->handle($syncService);

    Log::shouldHaveReceived('warning')->once()->withArgs(
        fn (string $message, array $context) => $message === 'SyncCardPricingJob: card sync failed permanently, not retrying'
            && $context['tcgdex_card_id'] === 'me05-116',
    );
});
