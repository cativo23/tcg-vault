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
     * coming back from a form. A tcgdex foil/stamp value outside it never
     * becomes a key; a submitted key only has its shape checked.
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
     * @param  array<string, mixed>  $raw  the card's full tcgdex payload (a Card's `raw` column)
     * @return array<int, string>
     */
    public static function available(array $tcgdexVariants, array $raw = []): array
    {
        $mapped = collect(self::MAP)
            ->filter(fn (string $_, string $tcgdex) => ($tcgdexVariants[$tcgdex] ?? false) === true)
            ->values()
            ->all();

        $special = collect(self::specialPrints($raw))->pluck('key')->unique()->values()->all();

        return [...collect(self::ORDER)->intersect($mapped)->values()->all(), ...$special];
    }

    /**
     * The `variants_detailed` entries that are prints of their own, beside
     * the base ones the flags already cover. tcgdex also files a card's
     * ONLY print of a type with its foil or stamp — a gold Hyper rare is
     * `{holo, foil: gold}`, a promo's sole print carries its stamp — and
     * offering that as a second option would split one physical card into
     * two collection rows. Such an entry is the base print when it is the
     * same product as the card's top-level pricing, or the lone entry of
     * its type with no plain sibling.
     *
     * @param  array<string, mixed>  $raw  the card's full tcgdex payload
     * @return array<int, array{key: string, entry: array<string, mixed>}>
     */
    public static function specialPrints(array $raw): array
    {
        $detailed = array_values(array_filter(
            is_array($raw['variants_detailed'] ?? null) ? $raw['variants_detailed'] : [],
            fn (mixed $entry) => is_array($entry),
        ));
        $baseProducts = self::baseProductIds(is_array($raw['pricing'] ?? null) ? $raw['pricing'] : []);

        $prints = [];
        foreach ($detailed as $entry) {
            $key = self::keyFor($entry);
            if ($key === null) {
                continue;
            }

            $siblings = array_filter($detailed, fn (array $e) => ($e['type'] ?? null) === $entry['type']);
            $hasPlainSibling = collect($siblings)->contains(fn (array $e) => empty($e['foil']) && empty($e['stamp']));
            $isLoneSpecial = collect($siblings)->filter(fn (array $e) => self::keyFor($e) !== null)->count() === 1;

            if (array_intersect(self::productIds($entry['thirdParty'] ?? null), $baseProducts) !== []
                || (! $hasPlainSibling && $isLoneSpecial)) {
                continue;
            }

            $prints[] = ['key' => $key, 'entry' => $entry];
        }

        return $prints;
    }

    /** A key for a print only `variants_detailed` describes (a foil or stamp suffix). */
    public static function isSpecial(string $key): bool
    {
        return str_contains($key, ':') || str_contains($key, '+');
    }

    /**
     * @param  array<string, mixed>  $pricing
     * @return array<int, string>
     */
    private static function baseProductIds(array $pricing): array
    {
        $ids = [];

        if (isset($pricing['cardmarket']['idProduct'])) {
            $ids[] = 'cardmarket:'.$pricing['cardmarket']['idProduct'];
        }

        foreach (is_array($pricing['tcgplayer'] ?? null) ? $pricing['tcgplayer'] : [] as $label) {
            if (is_array($label) && isset($label['productId'])) {
                $ids[] = 'tcgplayer:'.$label['productId'];
            }
        }

        return $ids;
    }

    /** @return array<int, string> */
    private static function productIds(mixed $thirdParty): array
    {
        if (! is_array($thirdParty)) {
            return [];
        }

        return collect(['cardmarket', 'tcgplayer'])
            ->filter(fn (string $source) => isset($thirdParty[$source]) && is_scalar($thirdParty[$source]))
            ->map(fn (string $source) => $source.':'.$thirdParty[$source])
            ->values()
            ->all();
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
        return strlen($key) <= self::MAX_KEY_LENGTH && preg_match(self::pattern(), $key) === 1;
    }

    /**
     * Form validation for a submitted variant: the key's shape, not
     * membership in the card's list — the dropdown's options live in a
     * client-settable Livewire property, so they can't be the check.
     *
     * @return array<int, string>
     */
    public static function rules(): array
    {
        return ['nullable', 'string', 'max:'.self::MAX_KEY_LENGTH, 'regex:'.self::pattern()];
    }

    private static function pattern(): string
    {
        $base = implode('|', array_map(fn (string $b) => preg_quote($b, '/'), self::ORDER));

        return '/^(?:'.$base.')(?::'.self::SEGMENT.')?(?:\\+'.self::SEGMENT.')*$/';
    }

    public static function label(string $key): string
    {
        $stamps = explode('+', $key);
        $head = explode(':', array_shift($stamps), 2);

        $parts = [Str::headline($head[0])];

        if (isset($head[1])) {
            $parts[] = self::FOIL_LABELS[$head[1]] ?? Str::headline($head[1]);
        }

        foreach ($stamps as $stamp) {
            $parts[] = Str::headline($stamp);
        }

        return implode(' · ', $parts);
    }
}
