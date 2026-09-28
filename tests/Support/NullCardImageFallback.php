<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Modules\Catalog\Contracts\CardImageFallback;

/**
 * Stands in for the tcgdex image lookup in tests, which then never reach
 * the network; the real lookup has its own tests. Records what it was asked.
 */
final class NullCardImageFallback implements CardImageFallback
{
    /** @var list<array{string, string}> */
    public array $asked = [];

    public function resolve(string $setTcgdexId, string $localId): ?string
    {
        $this->asked[] = [$setTcgdexId, $localId];

        return null;
    }
}
