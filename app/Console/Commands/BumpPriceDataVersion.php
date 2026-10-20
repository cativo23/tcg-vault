<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Catalog\Services\PriceDataVersion;
use Illuminate\Console\Command;

/**
 * Moves the price-data version on by hand, for a write to
 * card_price_snapshots the app didn't make (psql, a restored dump), so the
 * cached price rows and value series stop being served.
 */
final class BumpPriceDataVersion extends Command
{
    protected $signature = 'prices:bump-version';

    protected $description = 'Invalidate cached price rows after changing card_price_snapshots outside the app.';

    public function handle(PriceDataVersion $version): int
    {
        $version->bump();
        $this->info('Price-data version bumped.');

        return self::SUCCESS;
    }
}
