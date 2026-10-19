<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Modules\Catalog\Models\Card;
use App\Modules\Catalog\Services\DecidingPriceRows;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

/**
 * Loads every row of the recent window, as the public gallery did before it
 * loaded only the deciding rows. Parity tests and the prod-copy comparison
 * bind it in place of DecidingPriceRows and compare what the pages show.
 * Not used by the app.
 */
class FullRecentPriceRows extends DecidingPriceRows
{
    /**
     * @param  EloquentCollection<int, Card>  $cards
     * @return EloquentCollection<int, Card>
     */
    public function loadOnto(EloquentCollection $cards): EloquentCollection
    {
        return $cards->load(['priceSnapshots' => fn ($q) => $q->recent()]);
    }
}
