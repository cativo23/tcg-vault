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
     * The comparison point for a price delta against $latest: the most
     * recent snapshot strictly BEFORE $latest's captured_on, matching
     * $latest's exact source/variant/currency, with a real (non-null)
     * market_minor. Two things this deliberately guards against:
     *
     * 1. Self-comparison — resolveAsOf() reapplies the whole priority
     *    chain per date, so asking "what's the price as of today" and
     *    "as of yesterday" can independently land on the SAME row (e.g.
     *    today only has a cardmarket snapshot, yesterday had tcgplayer —
     *    both calls fall back to yesterday's tcgplayer row). Requiring
     *    captured_on strictly before $latest's makes that impossible.
     * 2. Mixed-basis deltas — subtracting across a different source,
     *    variant, or currency (or a null market_minor tcgdex sometimes
     *    omits) produces a number that looks like a real price move but
     *    isn't one. No eligible predecessor means no delta, not zero.
     */
    public function previousComparable(Card $card, CardPriceSnapshot $latest): ?CardPriceSnapshot
    {
        return $card->priceSnapshots
            ->filter(fn (CardPriceSnapshot $s) => $s->captured_on->lt($latest->captured_on)
                && $s->source === $latest->source
                && $s->variant === $latest->variant
                && $s->currency === $latest->currency
                && $s->market_minor !== null)
            ->sortByDesc('captured_on')
            ->first();
    }

    /**
     * The price movement between this card's two most recent snapshot
     * days, or null when there is nothing honest to compare: fewer than
     * two days, or the two days resolve to different sources/variants/
     * currencies (each day walks the priority chain independently, so
     * "latest" can be tcgplayer/USD while "previous" only had a
     * cardmarket/EUR row).
     */
    public function resolveDelta(Card $card): ?PriceDelta
    {
        $dates = $this->distinctSnapshotDates($card);

        if ($dates->count() < 2) {
            return null;
        }

        $latest = $this->resolveAsOf($card, $dates[0]);
        $previous = $this->resolveAsOf($card, $dates[1]);

        if ($latest === null || $previous === null) {
            return null;
        }

        if ($latest->source !== $previous->source
            || $latest->variant !== $previous->variant
            || $latest->currency !== $previous->currency
            || $latest->market_minor === null
            || $previous->market_minor === null) {
            return null;
        }

        return new PriceDelta($latest, $previous, $latest->market_minor - $previous->market_minor);
    }

    /**
     * The full daily series for the source/variant that resolve() picks
     * today, oldest first — the input for a price-history sparkline.
     * Empty when the card has never been priced.
     *
     * @return Collection<int, CardPriceSnapshot>
     */
    public function history(Card $card): Collection
    {
        $resolved = $this->resolve($card);

        if ($resolved === null) {
            return collect();
        }

        return $card->priceSnapshots
            ->filter(fn (CardPriceSnapshot $s) => $s->source === $resolved->source
                && $s->variant === $resolved->variant
                && $s->market_minor !== null)
            ->sortBy(fn (CardPriceSnapshot $s) => $s->captured_on->toDateString())
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
