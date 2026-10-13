<?php

declare(strict_types=1);

use App\Jobs\SyncCardPricingJob;
use App\Modules\Catalog\Models\Card;
use App\Modules\Catalog\Models\Set;
use Illuminate\Support\Facades\Queue;

test('dispatches one SyncCardPricingJob per existing card, with no HTTP calls made', function () {
    Queue::fake();

    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);
    Card::create(['tcgdex_id' => 'me05-116', 'set_id' => $set->id, 'local_id' => '116', 'name' => 'Mega Darkrai ex']);
    Card::create(['tcgdex_id' => 'me05-003', 'set_id' => $set->id, 'local_id' => '003', 'name' => 'Fomantis']);

    $this->artisan('catalog:refresh-prices')->assertSuccessful();

    Queue::assertPushed(SyncCardPricingJob::class, 2);
    Queue::assertPushed(fn (SyncCardPricingJob $job) => $job->tcgdexCardId === 'me05-116');
    Queue::assertPushed(fn (SyncCardPricingJob $job) => $job->tcgdexCardId === 'me05-003');
});

test('an empty Catalog dispatches nothing and still succeeds', function () {
    Queue::fake();

    $this->artisan('catalog:refresh-prices')->assertSuccessful();

    Queue::assertNothingPushed();
});

test('the summary separates jobs it queued from cards whose sync is already pending', function () {
    Queue::fake();

    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);
    Card::create(['tcgdex_id' => 'me05-116', 'set_id' => $set->id, 'local_id' => '116', 'name' => 'Mega Darkrai ex']);
    Card::create(['tcgdex_id' => 'me05-003', 'set_id' => $set->id, 'local_id' => '003', 'name' => 'Fomantis']);

    // A sync for one card is already waiting (its unique lock is held).
    SyncCardPricingJob::dispatch('me05-116');

    $this->artisan('catalog:refresh-prices')
        ->expectsOutput('Dispatched 1, skipped 1 (already queued).')
        ->assertSuccessful();

    Queue::assertPushed(SyncCardPricingJob::class, 2);
});

test('the summary reports no skips when nothing was pending', function () {
    Queue::fake();

    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);
    Card::create(['tcgdex_id' => 'me05-116', 'set_id' => $set->id, 'local_id' => '116', 'name' => 'Mega Darkrai ex']);

    $this->artisan('catalog:refresh-prices')
        ->expectsOutput('Dispatched 1, skipped 0 (already queued).')
        ->assertSuccessful();
});
