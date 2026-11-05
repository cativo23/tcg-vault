<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Support;

use App\Modules\Catalog\Models\Card;
use App\Modules\Catalog\Models\CardPriceSnapshot;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * What the last marketplace sync wrote, for the pages that date their
 * summary by it. A manual price is typed in, not synced, so it neither
 * dates a sync nor gets credited to a feed; a row without a market price
 * says nothing about one.
 */
final class MarketplaceSync
{
    /**
     * The sync rows among the given cards' loaded price rows.
     *
     * @param  Collection<int, Card>  $cards
     * @return Collection<int, CardPriceSnapshot>
     */
    public static function rows(Collection $cards): Collection
    {
        return $cards->flatMap(fn (Card $card) => $card->priceSnapshots)
            ->filter(fn (CardPriceSnapshot $s) => $s->source !== 'manual' && $s->market_minor !== null)
            ->values();
    }

    /**
     * The newest day among the given sync rows, or null when there are none.
     *
     * @param  Collection<int, CardPriceSnapshot>  $rows
     */
    public static function latestDay(Collection $rows): ?Carbon
    {
        return $rows
            ->reduce(fn (?CardPriceSnapshot $latest, CardPriceSnapshot $s) => $latest === null || $s->capturedOnKey() > $latest->capturedOnKey() ? $s : $latest)
            ?->captured_on;
    }
}
