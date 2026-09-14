<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Data;

use Spatie\LaravelData\Data;

final class CardSummaryData extends Data
{
    public function __construct(
        public string $tcgdexId,
        public string $setTcgdexId,
        public string $localId,
        public string $name,
        public ?string $imageUrl,
    ) {}
}
