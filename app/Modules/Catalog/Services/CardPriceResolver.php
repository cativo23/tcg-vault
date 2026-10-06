<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Services;

use App\Modules\Catalog\Models\Card;
use App\Modules\Catalog\Models\CardPriceSnapshot;
use App\Modules\Catalog\Support\CardVariants;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

final class CardPriceResolver
{
    /** How far behind the newest price a higher-priority one may be and still win. */
    public const STALE_AFTER_DAYS = 3;

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
     * The price for the SPECIFIC variant a collector's copy actually is
     * (normal / holofoil / reverse-holofoil), not resolve()'s card-level
     * priority chain — which prefers tcgplayer normal/holofoil first and
     * would never even consider a reverse-holofoil row, no matter how
     * much more it's worth. A $variant of null (item not yet assigned
     * one — `needs_variant_review`) falls back to resolve() since there
     * is nothing more specific to match against.
     */
    public function resolveForVariant(Card $card, ?string $variant): ?CardPriceSnapshot
    {
        return $this->variantFrom($card->priceSnapshots, $variant);
    }

    /**
     * resolveForVariant() restricted to snapshots captured on or before
     * $asOf — what a specific copy was worth on a given day, which is
     * what a collection-value-over-time series needs. resolveAsOf() is
     * the card-level equivalent and cannot answer this: its chain never
     * considers a reverse-holofoil row, so charting with it prices every
     * copy as the normal print.
     */
    public function resolveForVariantAsOf(Card $card, ?string $variant, CarbonInterface $asOf): ?CardPriceSnapshot
    {
        $asOfKey = $asOf->toDateString();

        return $this->variantFrom(
            $card->priceSnapshots->filter(fn (CardPriceSnapshot $s) => $s->capturedOnKey() <= $asOfKey),
            $variant,
        );
    }

    /**
     * resolveForVariantAsOf() for every day in $dayKeys at once, in one
     * pass over the card's history instead of one pass per day — what a
     * value-over-time series needs. Each step of the priority chain is
     * "the newest row of this subset on or before the day", so the chain
     * is resolved per subset and the first current hit wins (see
     * firstCurrent()), exactly as variantFrom() and resolveFrom() pick it.
     *
     * @param  Collection<int, string>  $dayKeys  'Y-m-d' days
     * @return array<string, CardPriceSnapshot|null> keyed by day
     */
    public function resolveForVariantOnDays(Card $card, ?string $variant, Collection $dayKeys): array
    {
        $chain = $this->chainFor($card->priceSnapshots, $variant);
        $byStep = array_map(fn (Collection $subset) => $this->newestOnOrBefore($subset, $dayKeys), $chain);
        $synced = $this->newestOnOrBefore($this->marketPriced($card->priceSnapshots), $dayKeys);

        $resolved = [];
        foreach ($dayKeys as $day) {
            $resolved[$day] = $this->firstCurrent(
                $this->borrowedOnlyIfOwnPriced(array_map(fn (array $step) => $step[$day], $byStep), $variant),
                $synced[$day]?->capturedOnKey(),
            );
        }

        return $resolved;
    }

    /**
     * For each day, the newest of $snapshots captured on or before it.
     * Same pick as filtering to the day and taking the first after a
     * newest-first sortByDesc(): the sort is stable, so a tie on the day
     * goes to whichever row comes first in $snapshots.
     *
     * @param  Collection<int, CardPriceSnapshot>  $snapshots
     * @param  Collection<int, string>  $dayKeys
     * @return array<string, CardPriceSnapshot|null>
     */
    private function newestOnOrBefore(Collection $snapshots, Collection $dayKeys): array
    {
        $newestFirst = $snapshots->sortByDesc(fn (CardPriceSnapshot $s) => $s->capturedOnKey())->values()->all();
        $count = count($newestFirst);
        $i = 0;
        $picked = [];

        foreach ($dayKeys->sortDesc() as $day) {
            while ($i < $count && $newestFirst[$i]->capturedOnKey() > $day) {
                $i++;
            }
            $picked[$day] = $newestFirst[$i] ?? null;
        }

        return $picked;
    }

    /**
     * @param  Collection<int, CardPriceSnapshot>  $snapshots
     */
    private function variantFrom(Collection $snapshots, ?string $variant): ?CardPriceSnapshot
    {
        return $this->firstCurrent(
            $this->borrowedOnlyIfOwnPriced(array_map(
                fn (Collection $step) => $step->sortByDesc(fn (CardPriceSnapshot $s) => $s->capturedOnKey())->first(),
                $this->chainFor($snapshots, $variant),
            ), $variant),
            $this->marketPriced($snapshots)->max(fn (CardPriceSnapshot $s) => $s->capturedOnKey()),
        );
    }

    /**
     * The priority chain a price is picked from, best first.
     *
     * Card-level (no variant): tcgplayer normal/holofoil, then
     * cardmarket's 'default' row, then any base-print row. A special
     * print (a Master Ball reverse, a stamped promo) is a separate
     * product; pricing the card as a whole from one would show a plain
     * copy at a price it doesn't fetch.
     *
     * For a variant: that exact variant on tcgplayer, then on any
     * marketplace, then a manual price for it (entered for a print tcgdex
     * doesn't price — current market data still beats it), then, for a
     * normal or holo print whose own price froze, the card-wide
     * 'default' row.
     * Never another print's row otherwise: a variant with no price of its
     * own resolves to nothing rather than to a different print's value.
     *
     * @param  Collection<int, CardPriceSnapshot>  $snapshots
     * @return array<int, Collection<int, CardPriceSnapshot>>
     */
    private function chainFor(Collection $snapshots, ?string $variant): array
    {
        if ($variant === null) {
            $base = $snapshots->reject(fn (CardPriceSnapshot $s) => CardVariants::isSpecial($s->variant));

            return [
                $base->filter(fn (CardPriceSnapshot $s) => $s->source === 'tcgplayer' && in_array($s->variant, ['normal', 'holofoil'], true)),
                $base->filter(fn (CardPriceSnapshot $s) => $s->source === 'cardmarket' && $s->variant === 'default'),
                $base,
            ];
        }

        $matching = $snapshots->filter(fn (CardPriceSnapshot $s) => $s->variant === $variant);
        $market = $matching->reject(fn (CardPriceSnapshot $s) => $s->source === 'manual');

        $chain = [
            $market->filter(fn (CardPriceSnapshot $s) => $s->source === 'tcgplayer'),
            $market,
            $matching->filter(fn (CardPriceSnapshot $s) => $s->source === 'manual'),
        ];

        // A base print whose own market price froze (a set tcgdex stopped
        // pricing on TCGplayer, while cardmarket files the card as
        // 'default') may fall back to the card-wide row; borrowedOnlyIfOwnPriced()
        // drops that pick unless the print has a priced row of its own.
        // Only the prints 'default' can stand for — cardmarket's primary
        // listing is the normal print, or the holo on a holo-only card;
        // never a reverse.
        if (in_array($variant, ['normal', 'holofoil'], true)) {
            $chain[] = $snapshots->filter(fn (CardPriceSnapshot $s) => $s->variant === 'default');
        }

        return $chain;
    }

    /**
     * Drops a variant chain's card-wide 'default' pick (its fourth step)
     * unless the variant's own marketplace pick (second step) exists and
     * is priced — a print with no price of its own must not borrow
     * another's. Decided on the picks, not the whole history, so a series
     * day and a single as-of day agree.
     *
     * @param  array<int, CardPriceSnapshot|null>  $picks
     * @return array<int, CardPriceSnapshot|null>
     */
    private function borrowedOnlyIfOwnPriced(array $picks, ?string $variant): array
    {
        if ($variant !== null && isset($picks[3]) && ($picks[1] === null || $picks[1]->market_minor === null)) {
            $picks[3] = null;
        }

        return $picks;
    }

    /**
     * Whether a marketplace still prices this exact print, judged the way
     * a pick is made: the newest tcgplayer row and the newest marketplace
     * row for the variant (priced or not, as chainFor() picks them), and
     * whether either is priced and within STALE_AFTER_DAYS of the card's
     * latest sync. A frozen or unpriced row doesn't count, so a manual
     * price can stand in for it. Callers should pass a card whose
     * snapshots are loaded through the same recent() window the listings
     * use, or a row no screen shows any more would still block.
     */
    public function hasCurrentMarketPrice(Card $card, string $variant): bool
    {
        $snapshots = $card->priceSnapshots;
        $cutoff = $this->currentFrom($this->marketPriced($snapshots)->max(fn (CardPriceSnapshot $s) => $s->capturedOnKey()));
        [$tcgplayer, $market] = $this->chainFor($snapshots, $variant);

        foreach ([$tcgplayer, $market] as $step) {
            $pick = $step->sortByDesc(fn (CardPriceSnapshot $s) => $s->capturedOnKey())->first();

            if ($pick !== null && $pick->market_minor !== null && $pick->capturedOnKey() >= $cutoff) {
                return true;
            }
        }

        return false;
    }

    /** The earliest capture day still current against $syncedOn, or null when nothing is dated. */
    private function currentFrom(?string $syncedOn): ?string
    {
        return $syncedOn === null
            ? null
            : Carbon::parse($syncedOn)->subDays(self::STALE_AFTER_DAYS)->toDateString();
    }

    /**
     * The card's rows that come from a marketplace sync and carry a price
     * — the ones that say how recently tcgdex priced this card at all.
     *
     * @param  Collection<int, CardPriceSnapshot>  $snapshots
     * @return Collection<int, CardPriceSnapshot>
     */
    private function marketPriced(Collection $snapshots): Collection
    {
        return $snapshots->filter(fn (CardPriceSnapshot $s) => $s->source !== 'manual' && $s->market_minor !== null);
    }

    /**
     * The first step's pick that is current — captured within
     * STALE_AFTER_DAYS of $syncedOn, the card's latest priced marketplace
     * row of any variant. Priority alone would keep a source tcgdex
     * stopped sending (a set whose TCGplayer prices froze) ahead of one
     * updated today, and measuring only against the chain's own picks
     * would let a frozen row pass whenever nothing else of its variant is
     * dated. The tolerance keeps a single missed sync from flipping the
     * price to another source. A manual price is written once and stands
     * until replaced (see CardPriceSnapshot::isRecent()), so it is current
     * at any age. A pick with no price never wins.
     *
     * @param  array<int, CardPriceSnapshot|null>  $picks  one per chain step, best first
     */
    private function firstCurrent(array $picks, ?string $syncedOn): ?CardPriceSnapshot
    {
        $cutoff = $this->currentFrom($syncedOn);

        foreach ($picks as $pick) {
            if ($pick === null || $pick->market_minor === null) {
                continue;
            }

            if ($pick->source === 'manual' || $cutoff === null || $pick->capturedOnKey() >= $cutoff) {
                return $pick;
            }
        }

        // Nothing current and priced: keep the old behaviour of returning
        // the best row there is, so callers still tell "synced, no price"
        // or "only an old price" apart from "never synced".
        return array_values(array_filter($picks))[0] ?? null;
    }

    /**
     * The card's price that makes no claim about WHICH print it is:
     * cardmarket's 'default' row, stored for a card whose own `variants`
     * flags were missing at sync time (see
     * TcgdexCardCatalogProvider::extractPrices) and which therefore
     * prices the card as a whole.
     *
     * This is the only honest fallback for a screen that prints a price
     * beside a SPECIFIC variant name. resolve() is not: its chain
     * prefers tcgplayer 'normal'/'holofoil', so a copy with no row of
     * its own would show a different print's value under its own label —
     * exactly the mislabelling resolveForVariant() exists to prevent.
     * Null when the card has no variant-agnostic price, in which case
     * the caller must show nothing rather than something wrong.
     */
    public function resolveCardWide(Card $card): ?CardPriceSnapshot
    {
        return $card->priceSnapshots
            ->filter(fn (CardPriceSnapshot $s) => $s->variant === 'default')
            ->sortByDesc(fn (CardPriceSnapshot $s) => $s->capturedOnKey())
            ->first();
    }

    /**
     * Same priority-order resolution as resolve(), but only considering
     * snapshots captured on or before $asOf — lets a caller ask "what
     * was the price as of THIS date," not just "the latest."
     */
    public function resolveAsOf(Card $card, CarbonInterface $asOf): ?CardPriceSnapshot
    {
        $asOfKey = $asOf->toDateString();

        $eligible = $card->priceSnapshots->filter(
            fn (CardPriceSnapshot $s) => $s->capturedOnKey() <= $asOfKey,
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
     * @return Collection<int, Carbon>
     */
    public function distinctSnapshotDates(Card $card): Collection
    {
        return $card->priceSnapshots
            ->unique(fn (CardPriceSnapshot $s) => $s->capturedOnKey())
            ->sortByDesc(fn (CardPriceSnapshot $s) => $s->capturedOnKey())
            ->map(fn (CardPriceSnapshot $s) => $s->captured_on)
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
        $latestKey = $latest->capturedOnKey();

        return $card->priceSnapshots
            ->filter(fn (CardPriceSnapshot $s) => $s->capturedOnKey() < $latestKey
                && $s->source === $latest->source
                && $s->variant === $latest->variant
                && $s->currency === $latest->currency
                && $s->market_minor !== null)
            ->sortByDesc(fn (CardPriceSnapshot $s) => $s->capturedOnKey())
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
        return $this->deltaFor($card, $this->resolve($card));
    }

    /**
     * The price movement for a SPECIFIC snapshot — e.g. the headline
     * variant a collector's copy resolves to (`Valuation::headlineSnapshot()`),
     * which resolve()'s own card-level chain might not have picked. Showing
     * the trend for a different variant than the one priced on screen would
     * be a lie, so any caller with its own resolved snapshot should go
     * through here instead of resolveDelta().
     */
    public function deltaFor(Card $card, ?CardPriceSnapshot $latest): ?PriceDelta
    {
        if ($latest === null || $latest->market_minor === null) {
            return null;
        }

        // previousComparable() owns every guard (strictly earlier day, same
        // source/variant/currency, non-null price) — including the
        // self-comparison trap where two resolveAsOf() calls land on the
        // same row and would fabricate a 0.00 move.
        $previous = $this->previousComparable($card, $latest);

        if ($previous === null) {
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
        return $this->historyFor($card, $this->resolve($card));
    }

    /**
     * The daily series for a SPECIFIC snapshot's own source+variant —
     * e.g. the headline variant a collector's copy resolves to
     * (`Valuation::headlineSnapshot()`), which resolve()'s card-level
     * chain might not have picked. A sparkline for one variant next to a
     * headline price from another would be showing the wrong history.
     *
     * @return Collection<int, CardPriceSnapshot>
     */
    public function historyFor(Card $card, ?CardPriceSnapshot $resolved): Collection
    {
        if ($resolved === null) {
            return collect();
        }

        return $card->priceSnapshots
            ->filter(fn (CardPriceSnapshot $s) => $s->source === $resolved->source
                && $s->variant === $resolved->variant
                && $s->market_minor !== null)
            ->sortBy(fn (CardPriceSnapshot $s) => $s->capturedOnKey())
            ->values();
    }

    /**
     * @param  Collection<int, CardPriceSnapshot>  $snapshots
     */
    private function resolveFrom(Collection $snapshots): ?CardPriceSnapshot
    {
        return $this->variantFrom($snapshots, null);
    }
}
