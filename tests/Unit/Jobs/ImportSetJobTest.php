<?php

declare(strict_types=1);

use App\Jobs\ImportSetJob;
use App\Jobs\SyncCardPricingJob;
use App\Modules\Catalog\Contracts\CardCatalogProvider;
use Illuminate\Support\Facades\Queue;

// ImportSetJob does ONE cheap listSetCardIds() call and dispatches one
// SyncCardPricingJob per card, rather than running the whole per-set loop
// of live tcgdex calls inside one job execution — the real per-card sync
// work (and its error handling, and its shared rate limit) all live in
// SyncCardPricingJob, already tested on its own. This keeps a single
// import from holding a Horizon worker hostage on an ordinary,
// unprivileged user action, and keeps per-card throughput governed by
// the shared 'tcgdex' rate limiter instead of separate ad-hoc pacing.

test('the job dispatches one SyncCardPricingJob per card id the provider lists for the set', function () {
    Queue::fake();

    $provider = Mockery::mock(CardCatalogProvider::class);
    $provider->shouldReceive('listSetCardIds')->once()->with('me05')->andReturn(['me05-001', 'me05-002', 'me05-003']);

    (new ImportSetJob('me05'))->handle($provider);

    Queue::assertPushed(SyncCardPricingJob::class, 3);
    Queue::assertPushed(fn (SyncCardPricingJob $job) => $job->tcgdexCardId === 'me05-001');
    Queue::assertPushed(fn (SyncCardPricingJob $job) => $job->tcgdexCardId === 'me05-002');
    Queue::assertPushed(fn (SyncCardPricingJob $job) => $job->tcgdexCardId === 'me05-003');
});

test('the unique lock outlives the (now near-instant) handle() call, so a near-simultaneous duplicate trigger is still a no-op', function () {
    // handle() only makes one cheap listing call now, so the default
    // Laravel uniqueness window (which ends as soon as handle() returns)
    // would protect almost nothing — two users adding a card from the
    // same unimported set moments apart could each dispatch their own
    // full wave of per-card jobs. uniqueFor() keeps the lock alive well
    // past that.
    $job = new ImportSetJob('me05');

    expect($job->uniqueFor())->toBeGreaterThan(3600);
});

test('the per-card jobs it dispatches go on the lower-priority "imports" queue, never "default"', function () {
    // Dispatching up to hundreds of SyncCardPricingJobs at once from an
    // ordinary, unprivileged user action (adding a card from a
    // not-yet-imported set) must never crowd out the scheduled daily
    // refresh or another user's own work behind the same rate-limit
    // budget. Horizon's 'default' queue is checked before 'imports'
    // (config/horizon.php), so these dispatches must never land on
    // 'default'.
    Queue::fake();

    $provider = Mockery::mock(CardCatalogProvider::class);
    $provider->shouldReceive('listSetCardIds')->once()->with('me05')->andReturn(['me05-001', 'me05-002']);

    (new ImportSetJob('me05'))->handle($provider);

    Queue::assertPushedOn('imports', SyncCardPricingJob::class);
    Queue::assertPushed(fn (SyncCardPricingJob $job) => $job->queue !== 'default');
});

test('the job never makes a per-card tcgdex call itself — only the one listing call', function () {
    // The whole point of the fix: no HTTP call other than listSetCardIds()
    // happens inside THIS job, so it can never hold a worker for the
    // duration of a 200+ card set.
    Queue::fake();

    $provider = Mockery::mock(CardCatalogProvider::class);
    $provider->shouldReceive('listSetCardIds')->once()->with('me05')->andReturn(['me05-001', 'me05-002']);
    $provider->shouldNotReceive('findCard');

    (new ImportSetJob('me05'))->handle($provider);

    Queue::assertPushed(SyncCardPricingJob::class, 2);
});
