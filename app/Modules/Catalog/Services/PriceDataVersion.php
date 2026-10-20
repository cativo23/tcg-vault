<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Services;

use Illuminate\Support\Facades\Cache;
use Throwable;

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
        try {
            // Seed a lost key first, so the increment never creates it at 1
            // for a reader to see in between. A key that exists is left alone.
            Cache::add(self::KEY, self::seed(), self::TTL_SECONDS);

            $version = Cache::increment(self::KEY);

            // The key vanished between the two calls: start fresh.
            if ($version === false || (int) $version <= 1) {
                Cache::put(self::KEY, self::seed(), self::TTL_SECONDS);
            }
        } catch (Throwable $e) {
            // The write that called this is already committed, so the caller
            // must not fail over the cache. Dropping the key makes the next
            // read reseed at a random point, which invalidates every entry.
            report($e);

            try {
                Cache::forget(self::KEY);
            } catch (Throwable $forgetFailure) {
                report($forgetFailure);
            }
        }
    }

    private static function seed(): int
    {
        return random_int(2, intdiv(PHP_INT_MAX, 2));
    }
}
