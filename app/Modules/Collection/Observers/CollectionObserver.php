<?php

declare(strict_types=1);

namespace App\Modules\Collection\Observers;

use App\Modules\Collection\Models\Collection;
use App\Modules\Collection\Services\ValueSeriesWarmup;

/**
 * Warms the owner's Activity chart when a collection becomes public or
 * private, since that adds or removes its copies from the page. A collection
 * created already public has no copies yet; the copies added to it warm the
 * owner through CollectionItemObserver, so there is nothing to do on `created`.
 */
final class CollectionObserver
{
    public function updated(Collection $collection): void
    {
        if ($collection->wasChanged('is_public')) {
            app(ValueSeriesWarmup::class)->afterChangeFor((int) $collection->user_id);
        }
    }
}
