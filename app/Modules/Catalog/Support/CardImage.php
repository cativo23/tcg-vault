<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Support;

/**
 * The size of a card image a tile asks for. tcgdex serves every card in two
 * sizes side by side (.../high.webp and .../low.webp) and the catalog stores
 * the high one, which is far more than a 245px tile needs.
 */
final class CardImage
{
    private const TCGDEX_ASSETS = 'https://assets.tcgdex.net/';

    public static function sized(?string $url, bool $large): ?string
    {
        if ($url === null || $url === '') {
            return null;
        }

        if ($large || ! str_starts_with($url, self::TCGDEX_ASSETS) || ! str_ends_with($url, '/high.webp')) {
            return $url;
        }

        return substr($url, 0, -strlen('high.webp')).'low.webp';
    }
}
