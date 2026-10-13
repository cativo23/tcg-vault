<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Modules\Catalog\Contracts\CardCatalogProvider;
use App\Modules\Catalog\Models\Card;
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
 * Dispatched from CollectionService::syncCardAndQueueImport() — called by
 * both addItem() and AddCollectionItem::save() (which syncs once per
 * submission rather than once per row) — whenever the just-synced card's
 * set isn't fully imported yet (cards()->count() < card_count).
 * Idempotent: only cards missing locally are queued, and
 * SyncCardPricingJob is updateOrCreate-based, so re-running this for a
 * set that's already complete queues nothing and never duplicates rows.
 *
 * This job does ONE cheap listing call and dispatches one
 * SyncCardPricingJob per missing card rather than making all per-card tcgdex
 * calls itself — the real per-card work (HTTP call, error handling,
 * shared rate limit) lives in SyncCardPricingJob so a single import
 * never holds a Horizon worker hostage for minutes on an ordinary,
 * unprivileged user action, and so per-card throughput stays governed
 * by the shared 'tcgdex' rate limiter instead of its own separate pacing.
 */
final class ImportSetJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** @return list<int> */
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

    /**
     * A ceiling on the uniqueness lock, not its length: the lock is
     * released as soon as handle() finishes (one listing call and the
     * fan-out dispatches), well before the per-card jobs it queues have
     * run. This only bounds how long a job that never finishes, for
     * example a lost worker, can keep the set locked.
     */
    public function uniqueFor(): int
    {
        return 6 * 3600;
    }

    public function handle(CardCatalogProvider $provider): void
    {
        // Only cards not stored locally yet are queued, so re-running the
        // import for a partly loaded set costs one sync per missing card
        // instead of one per card in the set.
        $listed = $provider->listSetCardIds($this->setTcgdexId);
        $stored = Card::query()->whereIn('tcgdex_id', $listed)->pluck('tcgdex_id')->all();
        $cardIds = array_values(array_diff($listed, $stored));

        foreach ($cardIds as $cardId) {
            // Deliberately NOT 'default' — see config/horizon.php's
            // queue-priority comment. This fan-out (up to hundreds of jobs
            // at once, from one unprivileged user action) must never
            // compete ahead of the scheduled daily refresh or another
            // user's own pending work for the shared 'tcgdex' budget.
            SyncCardPricingJob::dispatch($cardId)->onQueue('imports');
        }
    }
}
