<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\SyncCardPricingJob;
use App\Modules\Catalog\Models\Card;
use Illuminate\Console\Command;
use Illuminate\Queue\Events\UniqueJobSkipped;
use Illuminate\Support\Facades\Event;

final class RefreshCardPricing extends Command
{
    protected $signature = 'catalog:refresh-prices';

    protected $description = 'Dispatch a pricing-refresh job for every card already in the Catalog.';

    public function handle(): int
    {
        // SyncCardPricingJob is unique per card: a card whose sync is still
        // queued, running or retrying is skipped, and the framework says so
        // with UniqueJobSkipped. Counting those keeps the summary honest.
        $skipped = 0;
        Event::listen(UniqueJobSkipped::class, function (UniqueJobSkipped $event) use (&$skipped): void {
            if ($event->job instanceof SyncCardPricingJob) {
                $skipped++;
            }
        });

        $count = 0;

        Card::query()->select('tcgdex_id')->chunk(200, function ($cards) use (&$count) {
            foreach ($cards as $card) {
                SyncCardPricingJob::dispatch($card->tcgdex_id);
                $count++;
            }
        });

        $this->info(sprintf('Dispatched %d, skipped %d (already queued).', $count - $skipped, $skipped));

        return self::SUCCESS;
    }
}
