<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Services;

use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * One integer in the cache store that changes only when someone says past
 * price history was corrected by hand (`php artisan prices:bump-version`,
 * after a psql fix or a restored dump). Ordinary price writes never touch
 * it: they stamp today, and a cache keyed by day already ignores today's
 * rows for the days it holds. A cache of values computed from past days
 * carries the epoch it was built under and is used only while it is the
 * same.
 *
 * Same shape as PriceDataVersion: seeded on first read, and a value the
 * cache has lost starts again at a random point rather than at 1 so it
 * never comes back to one an older entry still carries.
 */
final class PriceHistoryEpoch
{
    public const KEY = 'price-history-epoch';

    /** Long enough to never matter; an epoch that expires starts fresh like a lost one. */
    private const TTL_SECONDS = 5 * 365 * 86400;

    public function current(): int
    {
        $epoch = Cache::get(self::KEY);

        if ($epoch === null) {
            Cache::add(self::KEY, self::seed(), self::TTL_SECONDS);
            $epoch = Cache::get(self::KEY);
        }

        return (int) $epoch;
    }

    public function bump(): void
    {
        try {
            // Seed a lost key first, so the increment never creates it at 1
            // for a reader to see in between. A key that exists is left alone.
            Cache::add(self::KEY, self::seed(), self::TTL_SECONDS);

            $epoch = Cache::increment(self::KEY);

            // The key vanished between the two calls: start fresh.
            if ($epoch === false || (int) $epoch <= 1) {
                Cache::put(self::KEY, self::seed(), self::TTL_SECONDS);
            }
        } catch (Throwable $e) {
            // Dropping the key makes the next read reseed at a random point,
            // which invalidates every entry built under the old epoch.
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
