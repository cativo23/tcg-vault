<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\User;
use App\Modules\Catalog\Services\CardPriceResolver;
use App\Modules\Catalog\Support\ChartRange;
use App\Modules\Collection\Services\CollectionValueSeries;
use App\Modules\Collection\Services\PublicCollection;
use App\Modules\Collection\Services\Valuation;
use App\Support\DiscordAlerter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Warms one user's Activity value series for every chart range (see
 * CollectionValueSeries::warm()), so a visitor reads the cache instead of
 * paying the rebuild.
 *
 * It runs on the queue, not in the 128M scheduler container, and one job
 * per user keeps a large or failing collection from holding up the rest.
 * It shares the tcgcsv supervisor's single worker rather than adding a
 * process to the Horizon container (see config/horizon.php). That worker
 * restarts only when it ends a job above its 64 MB threshold; a job on the
 * collection measured for this stays below it.
 *
 * Unique per user for less than the hourly schedule's period, so a slow run
 * is not stacked on by the next hour's dispatch, and a lost lock cannot
 * silence a user for long. One attempt: the next hour's dispatch is the retry.
 */
final class WarmValueSeriesJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    public bool $failOnTimeout = true;

    /** Seconds the per-user lock holds: under the hour between dispatches. */
    public int $uniqueFor = 3000;

    public function __construct(public readonly int $userId)
    {
        $this->onConnection('redis-long');
        $this->onQueue('tcgcsv');
    }

    public function uniqueId(): string
    {
        return (string) $this->userId;
    }

    public function handle(): void
    {
        // So the logged peak is this job's, not that of an earlier job in the same worker.
        memory_reset_peak_usage();

        $counts = $this->warm();

        if ($counts !== null) {
            Log::info('gallery:warm-value-series warmed a user', [
                'user_id' => $this->userId,
                'computed' => $counts['computed'],
                'warm' => $counts['warm'],
                'peak_mb' => round(memory_get_peak_usage() / 1048576, 1),
            ]);
        }
    }

    /**
     * Warms the user's series and says how many were computed and how many
     * were already cached; null when nobody reads this user's Activity page.
     *
     * @return ?array{computed: int, warm: int}
     */
    public function warm(): ?array
    {
        $user = User::find($this->userId);

        // A suspended member's page is a 404, so nothing reads their series.
        if ($user === null || $user->isSuspended()) {
            return null;
        }

        $public = PublicCollection::for($user);

        if ($public->isEmpty()) {
            return null;
        }

        return (new CollectionValueSeries(new Valuation(new CardPriceResolver)))->warm($public, ChartRange::cases());
    }

    /**
     * The alert names the user and the exception class only: a database
     * error's message carries SQL and card ids, and Discord caps a message
     * at 2000 characters. The full exception is logged. One alert per user
     * per day, so an hourly failure does not repeat itself.
     */
    public function failed(Throwable $e): void
    {
        Log::error('gallery:warm-value-series failed for a user', ['user_id' => $this->userId, 'exception' => $e]);

        if (Cache::add("gallery:warm-value-series:alerted:{$this->userId}:".now()->toDateString(), true, now()->addDay())) {
            app(DiscordAlerter::class)->send('⚠️ tcg-vault: the Activity chart for user '.$this->userId.' was not warmed ('.class_basename($e).'); see the log.');
        }
    }
}
