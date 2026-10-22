<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Contracts;

use App\Modules\Catalog\Models\Card;

/**
 * Finds a card's image from the marketplace product it is linked to, for
 * cards the catalog API and its asset server have no image for. Returns the
 * image URL only once it is confirmed to exist, otherwise null.
 */
interface LinkedCardImage
{
    public function resolve(Card $card): ?string;
}
