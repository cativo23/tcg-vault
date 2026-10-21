<?php

declare(strict_types=1);

namespace App\Modules\Collection\Observers;

use App\Modules\Collection\Models\CollectionItem;
use App\Modules\Collection\Services\ValueSeriesWarmup;

/**
 * Warms the owner's Activity chart when a copy in a public collection is
 * added, removed, or changed in what the chart prices: its card, variant,
 * quantity or collection. Condition, grade, notes and photos never reach the
 * price, so editing them queues nothing. `updated` also covers increment()
 * and decrement(), which fire it but not `saved`.
 *
 * Bulk queries that skip model events (mass update, delete, insert) skip
 * this too; the hourly warm covers them.
 */
final class CollectionItemObserver
{
    /** What the chart reads from a copy, plus which collection (and so which public side) it sits in. */
    private const PRICED_ATTRIBUTES = ['card_id', 'variant', 'quantity', 'collection_id'];

    public function created(CollectionItem $item): void
    {
        $this->warmOwnerOf($item->collection_id);
    }

    public function deleted(CollectionItem $item): void
    {
        $this->warmOwnerOf($item->collection_id);
    }

    public function updated(CollectionItem $item): void
    {
        if (! $item->wasChanged(self::PRICED_ATTRIBUTES)) {
            return;
        }

        $this->warmOwnerOf($item->collection_id);

        // A copy moved out of a public collection leaves that page's holdings too.
        if ($item->wasChanged('collection_id')) {
            $this->warmOwnerOf((int) $item->getOriginal('collection_id'));
        }
    }

    private function warmOwnerOf(int $collectionId): void
    {
        app(ValueSeriesWarmup::class)->afterChangeInCollection($collectionId);
    }
}
