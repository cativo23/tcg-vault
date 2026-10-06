<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Tcgcsv;

/** One TCGplayer price from tcgcsv: a product's price for one subtype. */
final readonly class TcgcsvPriceRow
{
    /** @param  array<string, mixed>  $raw */
    public function __construct(
        public int $groupId,
        public int $productId,
        public string $subType, // 'Normal' | 'Holofoil' | 'Reverse Holofoil' | …
        public ?int $marketMinor,
        public ?int $lowMinor,
        public array $raw,
    ) {}
}
