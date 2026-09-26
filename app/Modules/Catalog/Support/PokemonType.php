<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Support;

/**
 * Presentation helper for tcgdex's `types` array — Trainer/Energy cards
 * carry no types at all, only Pokémon do, so every accessor here is
 * null-safe rather than assuming a type exists.
 */
final class PokemonType
{
    /** @var array<string, string> tcgdex type name → accent hex */
    private const COLORS = [
        'Colorless' => '#A8A77A',
        'Grass' => '#63BC5A',
        'Fire' => '#FF9C54',
        'Water' => '#4D90D5',
        'Lightning' => '#F4D23C',
        'Psychic' => '#FA8581',
        'Fighting' => '#CE416B',
        'Darkness' => '#5B5466',
        'Metal' => '#8E8FA3',
        'Fairy' => '#EC8FE6',
        'Dragon' => '#8562E0',
    ];

    /** @param  array<string, mixed>|null  $raw */
    public static function of(?array $raw): ?string
    {
        $type = $raw['types'][0] ?? null;

        return is_string($type) && $type !== '' ? $type : null;
    }

    public static function color(?string $type): string
    {
        return self::COLORS[$type] ?? '#9a988c';
    }
}
