<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Modules\Catalog\Contracts\CardCatalogProvider;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

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
 * Idempotent: SyncCardPricingJob is updateOrCreate-based, so re-running
 * this for a set that's already complete just re-affirms/refreshes
 * existing rows, never duplicates.
 *
 * Found by an automated security review (2026-09-16), same day the
 * shared 'tcgdex' rate limiter shipped: the OLD design made all 200+
 * per-card tcgdex calls INSIDE ONE job execution, holding a Horizon
 * worker hostage for minutes on an ordinary, unprivileged user action
 * (production has only 2 workers total — two users each triggering an
 * import for a different not-yet-imported set could starve the whole
 * `default` queue). It also paced itself with its own usleep() instead
 * of the shared 'tcgdex' limiter, so it could burst tcgdex well past
 * the budget SyncCardPricingJob respects. Fixed by making this job do
 * ONE cheap listing call and dispatch one SyncCardPricingJob per card —
 * the real per-card work (HTTP call, error handling, shared rate limit)
 * all live there, already tested on their own.
 */
final class ImportSetJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function backoff(): array
    {
        return [10, 30, 60]; // seconds
    }

    public function __construct(public readonly string $setTcgdexId) {}

    /**
     * Redis-backed uniqueness: while an import for this set is still
     * queued/running, a duplicate dispatch (e.g. two users adding cards
     * from the same not-yet-imported set close together) is a no-op
     * instead of a second listing call + a second wave of duplicate
     * per-card dispatches.
     */
    public function uniqueId(): string
    {
        return $this->setTcgdexId;
    }

    public function handle(CardCatalogProvider $provider): void
    {
        $cardIds = $provider->listSetCardIds($this->setTcgdexId);

        foreach ($cardIds as $cardId) {
            SyncCardPricingJob::dispatch($cardId);
        }
    }
}
