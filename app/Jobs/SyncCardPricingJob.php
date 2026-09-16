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

    public function backoff(): array
    {
        return [10, 30, 60]; // seconds
    }

    /**
     * Found by an automated security review (2026-09-16): Laravel
     * increments a job's attempt count the instant a worker POPS it off
     * the queue — before any middleware runs — so every time
     * RateLimited('tcgdex') releases this job back onto the queue
     * because the shared bucket is full, that release consumes one of
     * $tries=3 even though handle() never ran (confirmed against
     * Illuminate\Queue's Redis driver source). With ~1986+ jobs sharing
     * a 3/sec budget, a job could plausibly be released 3+ times purely
     * from scheduling bad luck and get marked permanently failed
     * without ever making one real HTTP attempt — the rate limiter
     * (a safety control) silently defeating the job's own retry budget.
     * retryUntil() is a wall-clock deadline that supersedes $tries-based
     * exhaustion, so throttling delay alone can never burn through this
     * job's genuine retry allowance. 1 hour comfortably covers the
     * worst case (~1986 jobs / 3 per second ≈ 11 minutes to drain).
     */
    public function retryUntil(): \DateTimeInterface
    {
        return now()->addHour();
    }

    /**
     * Shared with every other tcgdex-calling job under the 'tcgdex' named
     * limiter (AppServiceProvider) — caps total throughput to a safe rate
     * regardless of how many Horizon workers are configured to run this
     * queue in parallel.
     *
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [new RateLimited('tcgdex')];
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
