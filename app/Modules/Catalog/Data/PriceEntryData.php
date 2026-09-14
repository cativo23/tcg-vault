<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Data;

use Carbon\CarbonImmutable;
use Spatie\LaravelData\Data;

final class PriceEntryData extends Data
{
    public function __construct(
        public string $source, // 'cardmarket' | 'tcgplayer'
        public string $variant, // 'default' | 'normal' | 'holofoil' | 'reverse-holofoil' | ...
        public string $currency, // ISO 4217, e.g. 'USD', 'EUR'
        public ?int $marketMinor,
        public ?int $lowMinor,
        public ?int $trendMinor,
        public ?CarbonImmutable $sourceUpdatedAt,
        /** @var array<string, mixed> */
        public array $raw,
    ) {}
}
