<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Services;

use App\Modules\Catalog\Models\Card;
use App\Modules\Catalog\Models\CardPriceSnapshot;
use Carbon\CarbonImmutable;
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
        return $this->variantFrom(
            $card->priceSnapshots->filter(fn (CardPriceSnapshot $s) => $s->captured_on->lte($asOf)),
            $variant,
        );
    }

    /**
     * @param  Collection<int, CardPriceSnapshot>  $snapshots
     */
    private function variantFrom(Collection $snapshots, ?string $variant): ?CardPriceSnapshot
    {
        if ($variant === null) {
            return $this->resolveFrom($snapshots);
        }

        $matching = $snapshots
            ->filter(fn (CardPriceSnapshot $s) => $s->variant === $variant)
            ->sortByDesc('captured_on')
            ->values();

        if ($matching->isEmpty()) {
            return null;
        }

        // Same source preference as resolve(): tcgplayer over cardmarket
        // when both cover this exact variant. $matching is already
        // latest-first, so the first tcgplayer row is also the most
        // recent one.
        return $matching->first(fn (CardPriceSnapshot $s) => $s->source === 'tcgplayer')
            ?? $matching->first();
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
            ->sortByDesc('captured_on')
            ->first();
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
     * @return Collection<int, CarbonImmutable>
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
