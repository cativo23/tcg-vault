<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Services;

use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * One integer in the cache store that changes only when someone says past
 * price history was corrected by hand: any write to a row dated before today,
 * from psql or from the app (a data migration, tinker, a backfill, a restored
 * dump), is followed by `php artisan prices:bump-version`. The PriceDataVersion
 * listener does not move the epoch, so nothing else will. Ordinary price
 * writes never touch it: they stamp today, and a cache keyed by day already
 * ignores today's rows for the days it holds. A cache of values computed from past days
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

    /**
     * Moves the epoch on, and says whether every entry built under the old
     * one is now unreachable: true when the epoch moved or its key was
     * dropped (the next read reseeds at a random point), false when the
     * cache refused both. Never throws.
     */
    public function bump(): bool
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

            return true;
        } catch (Throwable $e) {
            // Dropping the key makes the next read reseed at a random point,
            // which invalidates every entry built under the old epoch.
            report($e);

            try {
                Cache::forget(self::KEY);

                return true;
            } catch (Throwable $forgetFailure) {
                report($forgetFailure);

                return false;
            }
        }
    }

    private static function seed(): int
    {
        return random_int(2, intdiv(PHP_INT_MAX, 2));
    }
}
