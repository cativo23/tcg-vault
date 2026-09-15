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
        if ($rarity === null || trim($rarity) === '') {
            return '';
        }

        $key = Str::lower(trim($rarity));

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
        return $rarity === null ? '' : Str::title($rarity);
    }
}
