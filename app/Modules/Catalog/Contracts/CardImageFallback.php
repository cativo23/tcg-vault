<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Contracts;

/**
 * Finds a card's image when the catalog API doesn't send one. Returns the
 * image URL only once it is confirmed to exist, otherwise null.
 */
interface CardImageFallback
{
    public function resolve(string $setTcgdexId, string $localId): ?string;
}
