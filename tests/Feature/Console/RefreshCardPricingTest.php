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
