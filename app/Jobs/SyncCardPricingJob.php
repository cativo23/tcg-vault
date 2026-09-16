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
     * 3 attempts with backoff — a transient tcgdex hiccup shouldn't drop
     * a card from today's refresh, but a card that's actually gone
     * (see handle()'s catch below) fails fast instead of burning all 3.
     */
    public int $tries = 3;

    public function __construct(public readonly string $tcgdexCardId) {}

    public function backoff(): array
    {
        return [10, 30, 60]; // seconds
    }

    /**
     * Shared with every other tcgdex-calling job under the 'tcgdex' named
     * limiter (AppServiceProvider) — caps total throughput to a safe rate
     * regardless of how many Horizon workers are configured to run this
     * queue in parallel. A rate-limited job is released back onto the
     * queue (not counted as a failed attempt) when the limit is hit.
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
