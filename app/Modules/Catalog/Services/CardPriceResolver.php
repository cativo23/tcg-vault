<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Services;

use App\Modules\Catalog\Models\Card;
use App\Modules\Catalog\Models\CardPriceSnapshot;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

final class CardPriceResolver
{
    public function resolve(Card $card): ?CardPriceSnapshot
    {
        // Read the relation as a PROPERTY, not a method call. A property
        // access reuses an already-eager-loaded collection (zero extra
        // queries); a method call (`$card->priceSnapshots()->get()`)
        // always re-queries the DB regardless of what the caller already
        // loaded. Gallery screens call resolve() once per card in a set
        // (up to a few hundred), so this is the difference between one
        // query total (with `Card::with('priceSnapshots')`) and one query
        // PER card. Sorting happens here in PHP instead of via
        // `orderByDesc()` in SQL, since the property access can't carry
        // query constraints.
        return $this->resolveFrom($card->priceSnapshots);
    }

    /**
     * Same priority-order resolution as resolve(), but only considering
     * snapshots captured on or before $asOf — lets a caller ask "what
     * was the price as of THIS date," not just "the latest."
     */
    public function resolveAsOf(Card $card, CarbonInterface $asOf): ?CardPriceSnapshot
    {
        $eligible = $card->priceSnapshots->filter(
            fn (CardPriceSnapshot $s) => $s->captured_on->lte($asOf),
        );

        return $this->resolveFrom($eligible);
    }

    /**
     * The distinct dates this card has ANY snapshot for, most recent
     * first. A card synced only once has exactly one date (no prior day
     * to compare against for a delta); a card synced on 2+ different
     * days has one entry per day regardless of how many source/variant
     * rows exist on each day.
     *
     * @return Collection<int, \Carbon\CarbonImmutable>
     */
    public function distinctSnapshotDates(Card $card): Collection
    {
        return $card->priceSnapshots
            ->pluck('captured_on')
            ->unique(fn ($date) => $date->toDateString())
            ->sortByDesc(fn ($date) => $date->toDateString())
            ->values();
    }

    /**
     * @param  Collection<int, CardPriceSnapshot>  $snapshots
     */
    private function resolveFrom(Collection $snapshots): ?CardPriceSnapshot
    {
        $snapshots = $snapshots->sortByDesc('captured_on')->values();

        if ($snapshots->isEmpty()) {
            return null;
        }

        $tcgplayerPreferred = $snapshots->first(
            fn (CardPriceSnapshot $s) => $s->source === 'tcgplayer' && in_array($s->variant, ['normal', 'holofoil'], true),
        );

        if ($tcgplayerPreferred) {
            return $tcgplayerPreferred;
        }

        $cardmarketDefault = $snapshots->first(
            fn (CardPriceSnapshot $s) => $s->source === 'cardmarket' && $s->variant === 'default',
        );

        if ($cardmarketDefault) {
            return $cardmarketDefault;
        }

        return $snapshots->first();
    }
}
