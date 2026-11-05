<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Modules\Catalog\Models\Card;
use App\Modules\Catalog\Models\CardPriceSnapshot;
use App\Modules\Catalog\Services\DecidingPriceRows;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\DB;

/**
 * Loads every row of the recent window, as the public gallery did before it
 * loaded only the deciding rows, and gives each row its print's week
 * baseline from the read model the way DecidingPriceRows' join does (within
 * the window, null when there is none). Parity tests and the prod-copy
 * comparison bind it in place of DecidingPriceRows and compare what the
 * pages show. Not used by the app.
 */
class FullRecentPriceRows extends DecidingPriceRows
{
    /**
     * @param  EloquentCollection<int, Card>  $cards
     * @return EloquentCollection<int, Card>
     */
    public function loadOnto(EloquentCollection $cards): EloquentCollection
    {
        $cards->load(['priceSnapshots' => fn ($q) => $q->recent()]);

        $baselines = DB::table('card_price_week_baselines')
            ->whereIn('card_id', $cards->modelKeys())
            ->where('baseline_on', '>=', CardPriceSnapshot::recentFrom()->toDateString())
            ->get()
            ->keyBy(fn (object $b) => "{$b->card_id}|{$b->source}|{$b->variant}|{$b->currency}");

        foreach ($cards as $card) {
            foreach ($card->priceSnapshots as $snapshot) {
                $a = $snapshot->getAttributes();
                $b = $baselines->get("{$a['card_id']}|{$a['source']}|{$a['variant']}|{$a['currency']}");
                $snapshot->setRawAttributes([
                    ...$a,
                    'week_anchor_on' => $b?->anchor_on,
                    'week_baseline_id' => $b?->baseline_id,
                    'week_baseline_on' => $b?->baseline_on,
                    'week_baseline_origin' => $b?->baseline_origin,
                    'week_baseline_minor' => $b?->baseline_minor,
                ], true);
            }
        }

        return $cards;
    }
}
