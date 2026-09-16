<?php

declare(strict_types=1);

use App\Jobs\ImportSetJob;
use App\Jobs\SyncCardPricingJob;
use App\Modules\Catalog\Contracts\CardCatalogProvider;
use Illuminate\Support\Facades\Queue;

// Found by an automated security review of the commit that introduced
// the shared 'tcgdex' rate limiter (2026-09-16): the OLD design ran the
// whole per-set loop (200+ live tcgdex calls) INSIDE ONE job execution,
// holding a Horizon worker hostage for minutes on an ordinary,
// unprivileged user action (adding a card from a not-yet-imported set) —
// with only 2 workers in production, two users triggering two different
// imports could starve the `default` queue completely. It ALSO paced
// itself with its own usleep() instead of the shared 'tcgdex' limiter,
// so it could burst tcgdex well past the budget SyncCardPricingJob
// respects. Fix: ImportSetJob does ONE cheap listSetCardIds() call and
// dispatches one SyncCardPricingJob per card — the real per-card sync
// work (and its error handling, and its shared rate limit) all live in
// SyncCardPricingJob, already tested on its own.

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
