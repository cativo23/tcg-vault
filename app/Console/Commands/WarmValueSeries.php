<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use App\Modules\Catalog\Services\CardPriceResolver;
use App\Modules\Catalog\Support\ChartRange;
use App\Modules\Collection\Models\Collection;
use App\Modules\Collection\Scopes\TenantScope;
use App\Modules\Collection\Services\CollectionValueSeries;
use App\Modules\Collection\Services\PublicCollection;
use App\Modules\Collection\Services\Valuation;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Fills the cache behind the Activity page's value chart, so a visitor
 * after a price sync reads it instead of paying the rebuild. For every
 * user with a public collection (suspended members excluded, as their
 * page is a 404) and every chart range it asks CollectionValueSeries for
 * exactly what an anonymous visitor's Activity page asks: a series that
 * is already cached costs the fingerprint query, a missing one is
 * computed and stored. Prices change during the daily sync and the tcgcsv
 * sync, so this runs hourly.
 *
 * One user failing is logged and the run goes on; the exit code then
 * reports the failure.
 */
final class WarmValueSeries extends Command
{
    protected $signature = 'gallery:warm-value-series';

    protected $description = 'Warm the Activity value-series cache for every public collection and chart range.';

    public function handle(): int
    {
        $series = new CollectionValueSeries(new Valuation(new CardPriceResolver));
        $users = 0;
        $computed = 0;
        $warm = 0;
        $failures = 0;

        User::query()
            ->whereNull('suspended_at')
            ->whereIn('id', Collection::withoutGlobalScope(TenantScope::class)->where('is_public', true)->select('user_id'))
            ->orderBy('id')
            ->each(function (User $user) use ($series, &$users, &$computed, &$warm, &$failures): void {
                $users++;

                try {
                    $public = PublicCollection::for($user);

                    foreach (ChartRange::cases() as $range) {
                        $series->forPublicCollection($public, $range)['cached'] ? $warm++ : $computed++;
                    }
                } catch (Throwable $e) {
                    $failures++;
                    Log::error('gallery:warm-value-series failed for a user', ['user_id' => $user->id, 'exception' => $e]);
                }
            });

        Log::info('gallery:warm-value-series finished', [
            'users' => $users,
            'ranges' => count(ChartRange::cases()),
            'computed' => $computed,
            'warm' => $warm,
            'failures' => $failures,
        ]);
        $this->info("Warmed {$users} users: {$computed} series computed, {$warm} already warm, {$failures} failed.");

        return $failures === 0 ? self::SUCCESS : self::FAILURE;
    }
}
