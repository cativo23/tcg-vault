<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Modules\Catalog\Exceptions\CardNotFoundException;
use App\Modules\Catalog\Exceptions\CatalogIdentityMismatchException;
use App\Modules\Catalog\Exceptions\InvalidTcgdexIdException;
use App\Modules\Catalog\Exceptions\MalformedCatalogResponseException;
use App\Modules\Catalog\Exceptions\SetNotFoundException;
use App\Modules\Catalog\Services\CatalogSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

final class SyncCardPricingJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * $tries is effectively superseded by retryUntil() below (Laravel
     * checks retryUntil() first when both are defined) — kept only as
     * documentation of intent for a genuine transient tcgdex failure. Do
     * NOT rely on this number alone: see retryUntil()'s docblock for why.
     */
    public int $tries = 3;

    public function __construct(public readonly string $tcgdexCardId) {}

    /** @return list<int> */
    public function backoff(): array
    {
        return [10, 30, 60]; // seconds
    }

    /**
     * Laravel increments a job's attempt count the instant a worker pops
     * it off the queue, before any middleware runs — so every release by
     * RateLimited('tcgdex') because the shared bucket is full consumes one
     * of $tries=3 even though handle() never ran. With thousands of jobs
     * sharing a 3/sec budget, a job could plausibly be released 3+ times
     * purely from scheduling and get marked permanently failed without
     * ever making a real HTTP attempt — the rate limiter would silently
     * defeat the job's own retry budget. retryUntil() is a wall-clock
     * deadline that supersedes $tries-based exhaustion, so throttling
     * delay alone can never burn through this job's genuine retry
     * allowance. The deadline is fixed at dispatch time, so the whole
     * nightly catalog:refresh-prices fan-out shares one expiry — real
     * worker throughput has been observed well under the rate-limit
     * ceiling, leaving a remainder of jobs unprocessed when a 1-hour
     * deadline expired. 3 hours is roughly 4x the rate-limit floor for
     * the current catalog size, leaving room for that gap and for
     * catalog growth.
     */
    public function retryUntil(): \DateTimeInterface
    {
        return now()->addHours(3);
    }

    /**
     * Uses a separate, smaller budget on the 'imports' queue than on
     * 'default' (AppServiceProvider) so a large set-import backlog can
     * never exhaust the whole shared rate limit before a 'default' job
     * (the scheduled refresh, or another user's own request) gets a turn.
     *
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [new RateLimited($this->queue === 'imports' ? 'tcgdex-imports' : 'tcgdex-default')];
    }

    public function handle(CatalogSyncService $syncService): void
    {
        try {
            $syncService->syncCard($this->tcgdexCardId);
        } catch (CardNotFoundException|SetNotFoundException|InvalidTcgdexIdException|MalformedCatalogResponseException|CatalogIdentityMismatchException $e) {
            // Not transient — every one of these means something about
            // this specific ID or tcgdex's response shape is wrong, not
            // that the request merely failed to go through. Retrying the
            // exact same ID 3 times would never fix a card that's gone,
            // an ID that never had a valid shape, a set mismatch, or a
            // response tcgdex sends the same way every time. Log and let
            // this ONE card's failure end here; the day's other jobs are
            // unaffected (each SyncCardPricingJob is independent). Any
            // OTHER exception (e.g. a `RequestException` from a network
            // blip or a 5xx) propagates uncaught, so the queue's own
            // retry/backoff actually applies.
            Log::warning('SyncCardPricingJob: card sync failed permanently, not retrying', [
                'tcgdex_card_id' => $this->tcgdexCardId,
                'reason' => $e->getMessage(),
            ]);
        }
    }
}
