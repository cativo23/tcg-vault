<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Data;

use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\DataCollection;

final class CardDetailData extends Data
{
    public function __construct(
        public string $tcgdexId,
        public string $setTcgdexId,
        public string $localId, // zero-padded exactly as tcgdex returns it
        public string $name,
        public ?string $rarity,
        /** @var array<string, mixed> */
        public array $variants,
        public ?string $officialImageUrl,
        #[DataCollectionOf(PriceEntryData::class)]
        /** @var DataCollection<int, PriceEntryData> */
        public DataCollection $prices,
        /** @var array<string, mixed> */
        public array $raw,
    ) {}
}
