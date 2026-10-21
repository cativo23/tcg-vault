<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Catalog\Services\PriceDataVersion;
use App\Modules\Catalog\Services\PriceHistoryEpoch;
use Illuminate\Console\Command;

/**
 * Moves the price-data version on by hand, for a write to
 * card_price_snapshots the app didn't make (psql, a restored dump), so the
 * cached price rows stop being served and the cached value series, which
 * trust past days, are rebuilt. Exits with a failure when either the version
 * behind the price rows or the epoch behind the value series could not be
 * moved.
 */
final class BumpPriceDataVersion extends Command
{
    protected $signature = 'prices:bump-version';

    protected $description = 'Invalidate cached price rows and value series after changing card_price_snapshots outside the app.';

    public function handle(PriceDataVersion $version, PriceHistoryEpoch $epoch): int
    {
        // Both are attempted before judging either, so one failing never
        // leaves the other unmoved.
        $versionMoved = $version->bump();
        $epochMoved = $epoch->bump();

        if (! $versionMoved || ! $epochMoved) {
            $stale = array_filter([
                $versionMoved ? null : 'cached price rows',
                $epochMoved ? null : 'cached value series',
            ]);

            $this->error('The cache is not accepting writes, so these are still being served: '.implode(' and ', $stale).'. Fix the cache and run this again.');

            return self::FAILURE;
        }

        $this->info('Price-data version and history epoch bumped.');

        return self::SUCCESS;
    }
}
