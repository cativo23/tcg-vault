<?php

declare(strict_types=1);

namespace App\Modules\Collection\Observers;

use App\Modules\Collection\Models\Collection;
use App\Modules\Collection\Models\CollectionItem;
use App\Modules\Collection\Scopes\TenantScope;
use App\Modules\Collection\Services\ValueSeriesWarmup;

/**
 * Warms the owner's Activity chart when a copy in a public collection is
 * added, removed, or changed in what the chart prices: its card, variant or
 * quantity. Condition, grade, notes and photos never reach the price, so
 * editing them queues nothing. `updated` also covers increment() and
 * decrement(), which fire it but not `saved`.
 *
 * Bulk queries that skip model events (mass update, delete, insert) skip
 * this too; the hourly warm covers them.
 */
final class CollectionItemObserver
{
    private const PRICED_ATTRIBUTES = ['card_id', 'variant', 'quantity'];

    public function created(CollectionItem $item): void
    {
        $this->warmOwnerOf($item);
    }

    public function deleted(CollectionItem $item): void
    {
        $this->warmOwnerOf($item);
    }

    public function updated(CollectionItem $item): void
    {
        if ($item->wasChanged(self::PRICED_ATTRIBUTES)) {
            $this->warmOwnerOf($item);
        }
    }

    private function warmOwnerOf(CollectionItem $item): void
    {
        $collection = Collection::withoutGlobalScope(TenantScope::class)->find($item->collection_id, ['id', 'user_id', 'is_public']);

        if ($collection !== null && $collection->is_public) {
            ValueSeriesWarmup::afterChangeFor((int) $collection->user_id);
        }
    }
}
