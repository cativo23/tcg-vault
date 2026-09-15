<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Modules\Catalog\Exceptions\CardNotFoundException;
use App\Modules\Catalog\Exceptions\CatalogIdentityMismatchException;
use App\Modules\Catalog\Services\CatalogSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
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

    public function handle(CatalogSyncService $syncService): void
    {
        try {
            $syncService->syncCard($this->tcgdexCardId);
        } catch (CardNotFoundException|CatalogIdentityMismatchException $e) {
            // Not transient — retrying this exact ID won't help. Log and
            // let this ONE card's failure end here; the day's other jobs
            // are unaffected (each SyncCardPricingJob is independent).
            Log::warning('SyncCardPricingJob: card sync failed permanently, not retrying', [
                'tcgdex_card_id' => $this->tcgdexCardId,
                'reason' => $e->getMessage(),
            ]);
        }
        // Any other exception (network/HTTP failure) propagates
        // uncaught, so the queue's own retry/backoff actually applies.
    }
}
