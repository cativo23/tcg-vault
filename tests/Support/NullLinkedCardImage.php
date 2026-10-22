<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Modules\Catalog\Contracts\LinkedCardImage;
use App\Modules\Catalog\Models\Card;

/**
 * Stands in for the linked-product image lookup in tests, which then never
 * reach the network; the real lookup has its own tests.
 */
final class NullLinkedCardImage implements LinkedCardImage
{
    public function resolve(Card $card): ?string
    {
        return null;
    }
}
