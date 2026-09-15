<?php

declare(strict_types=1);

namespace App\Modules\Collection\Services;

use App\Modules\Catalog\Models\Card;
use App\Modules\Catalog\Models\CardPriceSnapshot;
use App\Modules\Catalog\Services\CardPriceResolver;
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
            $snapshot = $this->resolver->resolve($card);

            if ($snapshot === null || $snapshot->market_minor === null) {
                continue;
            }

            $quantity = (int) $card->collectionItems->sum('quantity');

            $totals[$snapshot->currency] = ($totals[$snapshot->currency] ?? 0) + $snapshot->market_minor * max($quantity, 1);
        }

        return self::order($totals);
    }

    /**
     * Total of one card's owned copies at its resolved price.
     *
     * @return array{snapshot: CardPriceSnapshot, minor: int}|null
     */
    public function cardTotal(Card $card): ?array
    {
        $snapshot = $this->resolver->resolve($card);

        if ($snapshot === null || $snapshot->market_minor === null) {
            return null;
        }

        $quantity = max((int) $card->collectionItems->sum('quantity'), 1);

        return ['snapshot' => $snapshot, 'minor' => $snapshot->market_minor * $quantity];
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
