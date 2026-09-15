<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\SyncCardPricingJob;
use App\Modules\Catalog\Models\Card;
use Illuminate\Console\Command;

final class RefreshCardPricing extends Command
{
    protected $signature = 'catalog:refresh-prices';

    protected $description = 'Dispatch a pricing-refresh job for every card already in the Catalog.';

    public function handle(): int
    {
        $count = 0;

        Card::query()->select('tcgdex_id')->chunk(200, function ($cards) use (&$count) {
            foreach ($cards as $card) {
                SyncCardPricingJob::dispatch($card->tcgdex_id);
                $count++;
            }
        });

        $this->info("Dispatched {$count} pricing-refresh job(s).");

        return self::SUCCESS;
    }
}
