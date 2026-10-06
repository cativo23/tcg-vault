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

    /**
     * tcgdex's own `foil` and `stamp` vocabulary (VariantStamps and
     * variant_detailed.foil in tcgdex/cards-database interfaces.d.ts), so
     * a print entered by hand gets the key tcgdex would give it if it
     * later lists that print.
     */
    private const FOILS = [
        'pokeball', 'greatball', 'ultraball', 'masterball', 'gold', 'cosmos', 'galaxy',
        'starlight', 'energy', 'cracked-ice', 'mirror', 'league', 'player-reward',
        'professor-program', 'tinsel', 'loveball', 'friendball', 'quickball', 'team-rocket',
        'duskball', 'rainbow', 'glitter',
    ];

    private const STAMPS = [
        '10th-anniversary', '1st-edition', '1st-edition-error', '1st-edition-scratch-error',
        '1st-movie', '1st-movie-inverted', '25th-celebration', '30th-anniversary', '30th-pokeday',
        'ace-trainer', 'akira-miyazaki', 'asia-2023-24', 'asia-promo', 'bulbasaur', 'champion',
        'charmander', 'chase-moloney', 'chicago-2009', 'chris-fulop', 'christopher-kan',
        'city-championships', 'comic-con', 'countdown-calendar', 'curran-hill', 'd-edition-error',
        'david-cohen', 'destiny-deoxys', 'distributor-meeting', 'dylan-lefavour', 'eb-games',
        'finalist', 'fossil-museum', 'gabriel-fernandez', 'games-expo', 'gamestop', 'gen-con',
        'great-ball-league', 'grey-star', 'gustavo-wada', 'gym-challenge', 'hiroki-yano',
        'horizons', 'igor-costa', 'illustration-contest-2022', 'illustration-contest-2024',
        'inquest-gamer', 'international-championship-europe',
        'international-championship-latin-america', 'international-championship-north-america',
        'international-championships', 'jason-klaczynski', 'jason-martinez', 'jeremy-maron',
        'jeremy-scharff-kim', 'jesse-parker', 'jimmy-ballard', 'jose-cruz-galindo-resendiz',
        'jr-stamp-rally', 'judge', 'jun-hasebe', 'kevin-nguyen', 'kraze-club', 'liao-fu-guan',
        'master-ball-league', 'mcdonalds', 'michael-gonzalez', 'michael-pramawat', 'miska-saari',
        'mychael-bryan', 'national-championships', 'nintendo-world', 'origins', 'origins-2008',
        'paul-atanassov', 'pikachu', 'pikachu-tail', 'platinum', 'player-rewards-program',
        'poke-ball-league', 'pokeball', 'pokemon-4-ever', 'pokemon-center', 'pokemon-center-ny',
        'pokemon-day', 'pokemon-rocks-america', 'pokemon-together', 'poketour-99',
        'pop-tournament', 'pre-release', 'professor-program', 'quarter-finalist', 'rain-city',
        'reed-weichler', 'regional-championships', 'ross-cawthorn', 'sakuya-ota', 'scrye',
        'semi-finalist', 'set-logo', 'shao-tong-yen', 'shuto-itagaki', 'snowflake', 'squirtle',
        'stadium-challenge', 'staff', 'state-championships', 'stephen-silvestro', 'takashi-yoneda',
        'thank-you', 'tom-roos', 'top-eight', 'top-sixteen', 'top-thirty-two',
        'tournament-collection', 'trick-or-trade', 'tristan-robinson', 'tsubasa-nakamura',
        'tsuguyoshi-yamato', 'ultra-ball-league', 'w-promo', 'winner', 'wizard-world-chicago',
        'wizard-world-philadelphia', 'worlds-2004', 'worlds-2005', 'worlds-2007', 'worlds-2008',
        'worlds-2009', 'worlds-2010', 'worlds-2022', 'worlds-2023', 'worlds-2024', 'worlds-2025',
        'wotc', 'yuka-furusawa', 'yuta-komatsuda', 'zachary-bokhari',
    ];

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

        $prints = self::specialPrints($raw);
        $special = collect($prints)->pluck('key')->unique()->values()->all();

        // A flagged base print whose type tcgdex lists ONLY as special
        // prints doesn't exist on its own — Ascended Heroes has no plain
        // reverse, just Poké Ball and Energy pattern ones — so it isn't
        // offered beside them. A type still has its base print when an
        // entry of it is plain, or is the stamped/foiled entry
        // specialPrints() recognised AS the base print (a promo's
        // {holo, set-logo}).
        /** @var array<int, mixed> $detailed */
        $detailed = is_array($raw['variants_detailed'] ?? null) ? $raw['variants_detailed'] : [];
        $printEntries = array_column($prints, 'entry');
        $basePresent = collect($detailed)
            ->filter(fn (mixed $e) => is_array($e) && isset(self::MAP[$e['type'] ?? '']))
            ->filter(fn (array $e) => (empty($e['foil']) && empty($e['stamp']))
                || (self::keyFor($e) !== null && ! in_array($e, $printEntries, true)))
            ->map(fn (array $e) => self::MAP[$e['type']])
            ->unique();
        $onlySpecial = collect($prints)
            ->map(fn (array $p) => self::MAP[$p['entry']['type']])
            ->unique()
            ->diff($basePresent)
            ->all();

        return [...collect(self::ORDER)->intersect($mapped)->diff($onlySpecial)->values()->all(), ...$special];
    }

    /**
     * The `variants_detailed` entries that are prints of their own, beside
     * the base ones the flags already cover. tcgdex also files a card's
     * ONLY print of a type with its foil or stamp — a gold Hyper rare is
     * `{holo, foil: gold}`, a promo's sole print carries its stamp — and
     * offering that as a second option would split one physical card into
     * two collection rows. Such an entry is the base print when it is the
     * same product as the card's top-level pricing, or the lone entry of
     * its type with no plain sibling — unless their product ids show it is
     * a separate product.
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

            $entryProducts = self::productIds($entry['thirdParty'] ?? null);
            $sharesBaseProduct = array_intersect($entryProducts, $baseProducts) !== [];
            // A lone entry is the base print only when nothing says it is a
            // separate product. When both it and the card carry product ids
            // and they differ, it is one — Boss's Orders' Prize Pack cosmos
            // is its only holo entry, yet a product of its own.
            $isSeparateProduct = ! $sharesBaseProduct && $entryProducts !== [] && $baseProducts !== [];

            if ($sharesBaseProduct || (! $hasPlainSibling && $isLoneSpecial && ! $isSeparateProduct)) {
                continue;
            }

            $prints[] = ['key' => $key, 'entry' => $entry];
        }

        return $prints;
    }

    /**
     * The key for a print entered by hand — one tcgdex doesn't list, like
     * a Prize Pack cosmos holo — built only from tcgdex's own foil and
     * stamp vocabulary. Null for anything outside it, or for a plain base
     * print, which needs no composing.
     *
     * @param  array<int, string>  $stamps
     */
    public static function compose(string $base, ?string $foil, array $stamps): ?string
    {
        if (! in_array($base, self::ORDER, true)
            || ($foil !== null && ! in_array($foil, self::FOILS, true))
            || array_diff($stamps, self::STAMPS) !== []
            || ($foil === null && $stamps === [])) {
            return null;
        }

        $stamps = array_values(array_unique($stamps));
        sort($stamps);
        $key = $base.($foil !== null ? ":{$foil}" : '').implode('', array_map(fn (string $s) => "+{$s}", $stamps));

        return self::isValid($key) ? $key : null;
    }

    /** @return array<string, string> foil => label */
    public static function foilOptions(): array
    {
        return collect(self::FOILS)
            ->mapWithKeys(fn (string $f) => [$f => self::FOIL_LABELS[$f] ?? Str::headline($f)])
            ->sort()
            ->all();
    }

    /** @return array<string, string> stamp => label */
    public static function stampOptions(): array
    {
        return collect(self::STAMPS)->mapWithKeys(fn (string $s) => [$s => Str::headline($s)])->sort()->all();
    }

    /** This app's base key for a tcgdex print type (`holo` → `holofoil`), or null for one it doesn't offer. */
    public static function baseKey(string $tcgdexType): ?string
    {
        return self::MAP[$tcgdexType] ?? null;
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
