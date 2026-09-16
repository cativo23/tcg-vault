<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Modules\Catalog\Contracts\CardCatalogProvider;
use App\Modules\Catalog\Exceptions\CardNotFoundException;
use App\Modules\Catalog\Exceptions\CatalogIdentityMismatchException;
use App\Modules\Catalog\Exceptions\InvalidTcgdexIdException;
use App\Modules\Catalog\Exceptions\MalformedCatalogResponseException;
use App\Modules\Catalog\Exceptions\SetNotFoundException;
use App\Modules\Catalog\Services\CatalogSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Backfills every remaining card in a set the first time ANY user adds a
 * card from it — the Catalog is global (never tenant-scoped), so this
 * runs once per set total, not once per user. Without it, a set-detail
 * screen can only ever show the handful of cards someone happened to add
 * directly, not the whole set — there is no other path that imports a
 * set's other cards.
 *
 * Dispatched from CollectionService::addItem() whenever the just-synced
 * card's set isn't fully imported yet (cards()->count() < card_count).
 * Idempotent: syncCard() is updateOrCreate-based, so re-running this for
 * a set that's already complete (or partially re-run after a prior
 * failure) just re-affirms/refreshes existing rows, never duplicates.
 */
final class ImportSetJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * A whole set can be 200+ cards — 3 identical attempts of the FULL
     * set would triple the HTTP-call cost of one already-large job.
     * Per-card failures inside handle() are caught and skipped (see
     * below), so a retry here is only for something that broke the
     * entire run (e.g. a transient failure between cards), not for a
     * single bad card id.
     */
    public int $tries = 2;

    /**
     * A 200+ card set at realistic per-card HTTP latency needs more than
     * Horizon's 60s default — without this the job is killed mid-import
     * and never completes a large set.
     */
    public int $timeout = 600;

    public function backoff(): array
    {
        return [30]; // seconds
    }

    public function __construct(public readonly string $setTcgdexId) {}

    /**
     * Redis-backed uniqueness: while an import for this set is still
     * queued/running, a duplicate dispatch (e.g. two users adding cards
     * from the same not-yet-imported set close together) is a no-op
     * instead of a second full run of the same set.
     */
    public function uniqueId(): string
    {
        return $this->setTcgdexId;
    }

    /**
     * Paces the internal per-card loop below — unlike SyncCardPricingJob
     * (which is one dispatch per card and shares the 'tcgdex' named rate
     * limiter across separate job executions), this job makes its whole
     * back-to-back run of calls INSIDE ONE execution, so a job-dispatch
     * rate limiter never touches it. Found live 2026-09-16: bursting
     * tcgdex without pacing (even from a single worker) reliably drove
     * "Could not resolve host" failures under load; a small delay between
     * calls is what made a 1986-card manual resync succeed afterward.
     * Skipped in tests so the suite doesn't pay real wall-clock time for it.
     */
    private const DELAY_BETWEEN_CARDS_MICROSECONDS = 150_000;

    public function handle(CardCatalogProvider $provider, CatalogSyncService $syncService): void
    {
        $cardIds = $provider->listSetCardIds($this->setTcgdexId);

        foreach ($cardIds as $cardId) {
            try {
                $syncService->syncCard($cardId);
            } catch (CardNotFoundException|SetNotFoundException|InvalidTcgdexIdException|MalformedCatalogResponseException|CatalogIdentityMismatchException $e) {
                // One bad card in an otherwise-good set shouldn't sink
                // the whole import — log it and keep going, same
                // catch-and-continue philosophy as catalog:import-set.
                Log::warning('ImportSetJob: card sync failed permanently, skipping', [
                    'set_tcgdex_id' => $this->setTcgdexId,
                    'tcgdex_card_id' => $cardId,
                    'reason' => $e->getMessage(),
                ]);
            }

            if (! app()->runningUnitTests()) {
                usleep(self::DELAY_BETWEEN_CARDS_MICROSECONDS);
            }
        }
    }
}
