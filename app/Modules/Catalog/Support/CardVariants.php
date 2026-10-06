<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Support;

use Illuminate\Support\Str;

/**
 * Maps tcgdex's own `variants` flags (what print types a card actually
 * has: `holo`/`normal`/`reverse`/…) to this app's variant vocabulary
 * (`normal`/`holofoil`/`reverse-holofoil`). This is the real source of
 * truth for "what variants does this card have" — unlike synced
 * `CardPriceSnapshot` rows, which can be incomplete or mislabeled
 * (cardmarket's importer names its only foil-tier price 'holofoil'
 * regardless of whether the card actually has a straight holo print or
 * only a reverse-holo one — see CardPriceResolver/TcgdexCardCatalogProvider).
 *
 * tcgdex's `variants_detailed` adds prints the flags can't express — a
 * Poké Ball or Master Ball pattern reverse, a cosmos holo, a stamped
 * promo — which are separate products with their own prices. Each one
 * gets a key of the form `{base}[:{foil}][+{stamp}…]`, e.g.
 * `reverse-holofoil:pokeball` or `holofoil:cosmos+pre-release`, so the
 * three base keys (and every row already stored under them) keep their
 * exact meaning.
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
     * Every key is built from these, and the same pattern validates keys
     * coming back from a form — the key is stored and echoed into views,
     * so a foil/stamp value outside it is dropped rather than escaped.
     */
    private const SEGMENT = '[a-z0-9]+(?:-[a-z0-9]+)*';

    /** Well under collection_items.variant's 255, with room to spare. */
    private const MAX_KEY_LENGTH = 120;

    /** Ball names collectors write with their own spelling. */
    private const FOIL_LABELS = [
        'pokeball' => 'Poké Ball',
        'greatball' => 'Great Ball',
        'ultraball' => 'Ultra Ball',
        'masterball' => 'Master Ball',
        'loveball' => 'Love Ball',
        'friendball' => 'Friend Ball',
        'quickball' => 'Quick Ball',
        'duskball' => 'Dusk Ball',
    ];

    /**
     * @param  array<string, mixed>  $tcgdexVariants  a Card's `variants` column
     * @param  array<int, mixed>  $detailed  tcgdex's `variants_detailed`, from the Card's `raw` column
     * @return array<int, string>
     */
    public static function available(array $tcgdexVariants, array $detailed = []): array
    {
        $mapped = collect(self::MAP)
            ->filter(fn (string $_, string $tcgdex) => ($tcgdexVariants[$tcgdex] ?? false) === true)
            ->values()
            ->all();

        $special = collect($detailed)
            ->map(fn (mixed $entry) => is_array($entry) ? self::keyFor($entry) : null)
            ->filter()
            ->unique()
            ->values()
            ->all();

        return [...collect(self::ORDER)->intersect($mapped)->values()->all(), ...$special];
    }

    /**
     * The key for one `variants_detailed` entry, or null when it is just a
     * base print the flags already cover — or one this app doesn't offer
     * (a jumbo, a metal card) or can't safely key.
     *
     * @param  array<string, mixed>  $entry
     */
    public static function keyFor(array $entry): ?string
    {
        $base = self::MAP[$entry['type'] ?? ''] ?? null;

        if ($base === null || ($entry['size'] ?? 'standard') !== 'standard') {
            return null;
        }

        $foil = $entry['foil'] ?? null;
        $stamps = $entry['stamp'] ?? [];

        if (! is_array($stamps) || ($foil !== null && ! is_string($foil))) {
            return null;
        }

        if ($foil === null && $stamps === []) {
            return null;
        }

        sort($stamps);
        $key = $base.($foil !== null ? ":{$foil}" : '').implode('', array_map(fn (mixed $s) => '+'.(is_string($s) ? $s : '?'), $stamps));

        return self::isValid($key) ? $key : null;
    }

    public static function isValid(string $key): bool
    {
        $base = implode('|', array_map(fn (string $b) => preg_quote($b, '/'), self::ORDER));

        return strlen($key) <= self::MAX_KEY_LENGTH
            && preg_match('/^(?:'.$base.')(?::'.self::SEGMENT.')?(?:\+'.self::SEGMENT.')*$/', $key) === 1;
    }

    public static function label(string $key): string
    {
        [$head, $stamps] = array_pad(explode('+', $key, 2), 2, null);
        [$base, $foil] = array_pad(explode(':', $head, 2), 2, null);

        $parts = [Str::headline($base)];

        if ($foil !== null) {
            $parts[] = self::FOIL_LABELS[$foil] ?? Str::headline($foil);
        }

        foreach ($stamps !== null ? explode('+', $stamps) : [] as $stamp) {
            $parts[] = Str::headline($stamp);
        }

        return implode(' · ', $parts);
    }
}
