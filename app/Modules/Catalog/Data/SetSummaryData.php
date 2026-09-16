<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Data;

use Carbon\CarbonImmutable;
use Spatie\LaravelData\Data;

final class SetSummaryData extends Data
{
    public function __construct(
        public string $tcgdexId,
        public string $name,
        public ?string $series,
        public ?CarbonImmutable $releasedOn,
        public ?int $cardCount,
        public ?string $logoUrl,
        public ?string $abbreviation = null,
    ) {}
}
