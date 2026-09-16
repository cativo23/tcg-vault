<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Support;

/**
 * Maps tcgdex's own `variants` flags (what print types a card actually
 * has: `holo`/`normal`/`reverse`/…) to this app's variant vocabulary
 * (`normal`/`holofoil`/`reverse-holofoil`). This is the real source of
 * truth for "what variants does this card have" — unlike synced
 * `CardPriceSnapshot` rows, which can be incomplete or mislabeled
 * (cardmarket's importer names its only foil-tier price 'holofoil'
 * regardless of whether the card actually has a straight holo print or
 * only a reverse-holo one — see CardPriceResolver/TcgdexCardCatalogProvider).
 */
final class CardVariants
{
    /** @var array<string, string> tcgdex's own key → this app's vocabulary */
    private const MAP = [
        'normal' => 'normal',
        'holo' => 'holofoil',
        'reverse' => 'reverse-holofoil',
    ];

    /** Stable display order, independent of the input array's key order. */
    private const ORDER = ['normal', 'holofoil', 'reverse-holofoil'];

    /**
     * @param  array<string, mixed>  $tcgdexVariants  a Card's `variants` column
     * @return array<int, string>
     */
    public static function available(array $tcgdexVariants): array
    {
        $mapped = collect(self::MAP)
            ->filter(fn (string $_, string $tcgdex) => ($tcgdexVariants[$tcgdex] ?? false) === true)
            ->values()
            ->all();

        return collect(self::ORDER)->intersect($mapped)->values()->all();
    }
}
