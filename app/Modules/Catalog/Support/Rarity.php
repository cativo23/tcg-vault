<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Support;

use Illuminate\Support\Str;

/**
 * Presentation helper for tcgdex's free-text `rarity` strings. The grid
 * tile has room for two or three characters, so it shows the collector
 * shorthand ("SIR", "MHR") and leaves the full name for the detail page.
 */
final class Rarity
{
    /** @var array<string, string> lowercase tcgdex rarity → collector shorthand */
    private const KNOWN = [
        'common' => 'C',
        'uncommon' => 'U',
        'rare' => 'R',
        'rare holo' => 'RH',
        'holo rare' => 'RH',
        'double rare' => 'RR',
        'ultra rare' => 'UR',
        'illustration rare' => 'IR',
        'special illustration rare' => 'SIR',
        'hyper rare' => 'HR',
        'mega hyper rare' => 'MHR',
        'ace spec rare' => 'ACE',
        'shiny rare' => 'SR',
        'shiny ultra rare' => 'SUR',
        'promo' => 'PR',
        'secret rare' => 'SEC',
        'trainer gallery rare holo' => 'TG',
    ];

    public static function abbreviate(?string $rarity): string
    {
        $key = Str::lower(trim((string) $rarity));

        // tcgdex emits the literal string "None" for promos and other
        // unrated prints — that is "no rarity", not a rarity called N.
        if ($key === '' || $key === 'none') {
            return '';
        }

        if (isset(self::KNOWN[$key])) {
            return self::KNOWN[$key];
        }

        // Unknown wording: first letter of each word, capped so a long
        // phrase never overflows the tile header.
        $initials = collect(preg_split('/\s+/', $rarity) ?: [])
            ->map(fn (string $word) => Str::upper(Str::substr($word, 0, 1)))
            ->implode('');

        return Str::substr($initials, 0, 4);
    }

    public static function label(?string $rarity): string
    {
        if ($rarity === null || Str::lower(trim($rarity)) === 'none') {
            return '';
        }

        return Str::title($rarity);
    }

    /**
     * Standard/silver/chase are which pulls a collector actually hunts
     * for, not just a listing of KNOWN — 'common'/'uncommon'/'rare' stay
     * unaccented (the grid's current look), everything one tier up from
     * a plain pull is 'silver', and the small set of prints that are the
     * actual chase get 'chase' (a restrained holo treatment, not a
     * per-rarity color for every one of the 16+ known tiers).
     *
     * @var array<string, string>
     */
    private const TIERS = [
        'common' => 'standard',
        'uncommon' => 'standard',
        'rare' => 'standard',
        'rare holo' => 'silver',
        'holo rare' => 'silver',
        'double rare' => 'silver',
        'ultra rare' => 'silver',
        'promo' => 'silver',
        'trainer gallery rare holo' => 'silver',
        'illustration rare' => 'chase',
        'special illustration rare' => 'chase',
        'hyper rare' => 'chase',
        'mega hyper rare' => 'chase',
        'ace spec rare' => 'chase',
        'shiny rare' => 'chase',
        'shiny ultra rare' => 'chase',
        'secret rare' => 'chase',
    ];

    /**
     * A visual accent, not a value judgment — an unrecognized rarity
     * string (a new set using wording this list doesn't have yet) tiers
     * as 'standard' rather than guessing, unlike abbreviate()'s own
     * initials fallback for display.
     */
    public static function tier(?string $rarity): string
    {
        $key = Str::lower(trim((string) $rarity));

        return self::TIERS[$key] ?? 'standard';
    }
}
