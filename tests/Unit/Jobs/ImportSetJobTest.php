<?php

declare(strict_types=1);

use App\Jobs\ImportSetJob;
use App\Jobs\SyncCardPricingJob;
use App\Modules\Catalog\Contracts\CardCatalogProvider;
use App\Modules\Catalog\Models\Card;
use App\Modules\Catalog\Models\Set;
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

test('the unique lock has a ceiling for a job that never finishes', function () {
    // The lock is released once handle() returns; uniqueFor() only bounds
    // how long a lost job can keep the set locked.
    $job = new ImportSetJob('me05');

    expect($job->uniqueFor())->toBeGreaterThan(3600);
});

test('cards already stored locally are not queued again', function () {
    Queue::fake();

    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black', 'card_count' => 3]);
    Card::create(['tcgdex_id' => 'me05-001', 'set_id' => $set->id, 'local_id' => '001', 'name' => 'Fomantis']);

    $provider = Mockery::mock(CardCatalogProvider::class);
    $provider->shouldReceive('listSetCardIds')->once()->with('me05')->andReturn(['me05-001', 'me05-002', 'me05-003']);

    (new ImportSetJob('me05'))->handle($provider);

    Queue::assertPushed(SyncCardPricingJob::class, 2);
    Queue::assertNotPushed(SyncCardPricingJob::class, fn (SyncCardPricingJob $job) => $job->tcgdexCardId === 'me05-001');
});

test('a complete set queues nothing', function () {
    Queue::fake();

    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black', 'card_count' => 1]);
    Card::create(['tcgdex_id' => 'me05-001', 'set_id' => $set->id, 'local_id' => '001', 'name' => 'Fomantis']);

    $provider = Mockery::mock(CardCatalogProvider::class);
    $provider->shouldReceive('listSetCardIds')->once()->with('me05')->andReturn(['me05-001']);

    (new ImportSetJob('me05'))->handle($provider);

    Queue::assertNothingPushed();
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
