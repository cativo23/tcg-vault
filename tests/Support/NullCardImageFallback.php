<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Modules\Catalog\Contracts\CardImageFallback;

/**
 * Stands in for the tcgdex image lookup in tests, which then never reach
 * the network; the real lookup has its own tests.
 */
final class NullCardImageFallback implements CardImageFallback
{
    public function resolve(string $setTcgdexId, string $localId): ?string
    {
        return null;
    }
}
