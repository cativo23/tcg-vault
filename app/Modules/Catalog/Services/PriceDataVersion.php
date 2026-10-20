<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Services;

use Illuminate\Support\Facades\Cache;

/**
 * One integer in the cache store that changes whenever card_price_snapshots
 * changes (see BumpPriceDataVersion, which bumps it on every write that
 * reaches the database through the app). A cache of price rows, or of
 * anything computed from them, carries the version it was computed under
 * and is used only while the version is still the same.
 *
 * The version is read BEFORE the rows it guards, so an entry can only be
 * stored under a version that was current when its rows were read; a write
 * that commits afterwards bumps past it.
 *
 * A version the cache has lost (flushed, evicted) starts again at a random
 * point rather than at 1, so it never comes back to a value an older entry
 * still carries.
 *
 * Writes made outside the app (psql, a restored dump) don't bump it: run
 * `php artisan prices:bump-version` after one.
 */
final class PriceDataVersion
{
    public const KEY = 'price-data-version';

    /** Long enough to never matter; a version that expires starts fresh like a lost one. */
    private const TTL_SECONDS = 5 * 365 * 86400;

    public function current(): int
    {
        $version = Cache::get(self::KEY);

        if ($version === null) {
            Cache::add(self::KEY, self::seed(), self::TTL_SECONDS);
            $version = Cache::get(self::KEY);
        }

        return (int) $version;
    }

    public function bump(): void
    {
        // Incrementing a missing key yields 1: the version was lost, so it
        // starts fresh instead.
        $version = Cache::increment(self::KEY);

        if ($version === false || (int) $version <= 1) {
            Cache::put(self::KEY, self::seed(), self::TTL_SECONDS);
        }
    }

    private static function seed(): int
    {
        return random_int(2, intdiv(PHP_INT_MAX, 2));
    }
}
