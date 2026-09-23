<?php

declare(strict_types=1);

namespace App\Modules\Collection\Services;

use App\Modules\Catalog\Models\Card;
use App\Modules\Catalog\Models\CardPriceSnapshot;
use App\Modules\Catalog\Services\CardPriceResolver;
use App\Modules\Collection\Models\CollectionItem;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Sums owned-card value WITHOUT ever adding two currencies together.
 * tcgdex prices the same card in EUR (Cardmarket) and USD (TCGplayer),
 * and the resolver's priority chain can legitimately land on either per
 * card — so a collection total is a small map of currency → minor units,
 * never one number. USD is listed first when present because it is the
 * chain's preferred source; the screens show the first entry big and
 * the rest as a footnote.
 */
final class Valuation
{
    public function __construct(private readonly CardPriceResolver $resolver = new CardPriceResolver) {}

    /**
     * @param  iterable<int, Card>  $cards  each with `collectionItems` (owned copies) and `priceSnapshots` loaded
     * @return array<string, int> currency → total minor units, ordered USD first
     */
    public function totalsByCurrency(iterable $cards): array
    {
        $totals = [];

        foreach ($cards as $card) {
            foreach ($this->itemTotals($card) as $currency => $minor) {
                $totals[$currency] = ($totals[$currency] ?? 0) + $minor;
            }
        }

        return self::order($totals);
    }

    /**
     * totalsByCurrency() as it stood on $asOf — every copy priced from
     * the newest snapshot of ITS OWN variant captured on or before that
     * day. Sharing itemTotals() with the live total is the point: a
     * value-over-time chart whose last point disagreed with the headline
     * figure above it would be describing a different collection.
     *
     * @param  iterable<int, Card>  $cards
     * @return array<string, int>
     */
    public function totalsByCurrencyAsOf(iterable $cards, CarbonInterface $asOf): array
    {
        $totals = [];

        foreach ($cards as $card) {
            foreach ($this->itemTotals($card, $asOf) as $currency => $minor) {
                $totals[$currency] = ($totals[$currency] ?? 0) + $minor;
            }
        }

        return self::order($totals);
    }

    /**
     * Total of one card's owned copies, each copy valued at the price of
     * the variant it ACTUALLY is — not one resolved snapshot times the
     * total quantity. A collector's normal and reverse-holofoil copies
     * of the same card can be worth very different amounts.
     *
     * @return array<string, int>|null currency => minor units, ordered USD first; null if nothing priced
     */
    public function cardTotal(Card $card): ?array
    {
        $totals = $this->itemTotals($card);

        return $totals === [] ? null : self::order($totals);
    }

    /**
     * The single "headline" price for a card tile: the priciest owned
     * variant's resolved snapshot (what the collector's best copy is
     * actually worth), falling back to the card-level priority chain
     * for a ghost card (nothing owned) or when no owned item resolves
     * to a real price.
     */
    public function headlineSnapshot(Card $card): ?CardPriceSnapshot
    {
        $best = $card->collectionItems
            ->map(fn (CollectionItem $item) => $this->resolver->resolveForVariant($card, $item->variant))
            ->filter(fn (?CardPriceSnapshot $s) => $s !== null && $s->market_minor !== null)
            ->sortByDesc(fn (CardPriceSnapshot $s) => $s->market_minor)
            ->first();

        return $best ?? $this->resolver->resolve($card);
    }

    /**
     * @return array<string, int> currency => minor units, unordered (caller orders)
     */
    private function itemTotals(Card $card, ?CarbonInterface $asOf = null): array
    {
        $totals = [];

        foreach ($card->collectionItems as $item) {
            $snapshot = $asOf === null
                ? $this->resolver->resolveForVariant($card, $item->variant)
                : $this->resolver->resolveForVariantAsOf($card, $item->variant, $asOf);

            if ($snapshot === null || $snapshot->market_minor === null) {
                continue;
            }

            $quantity = max((int) $item->quantity, 1);
            $totals[$snapshot->currency] = ($totals[$snapshot->currency] ?? 0) + $snapshot->market_minor * $quantity;
        }

        return $totals;
    }

    /**
     * @param  array<string, int>  $totals
     * @return array<string, int>
     */
    public static function order(array $totals): array
    {
        return Collection::make($totals)
            ->sortBy(fn (int $minor, string $currency) => $currency === 'USD' ? 0 : 1)
            ->all();
    }
}
