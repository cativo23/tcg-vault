<?php

declare(strict_types=1);

namespace App\Modules\Collection\Data;

use Spatie\LaravelData\Data;

final class MatchedImportLine extends Data
{
    public function __construct(
        public readonly int $qty,
        public readonly string $tcgdexId,
        public readonly string $name,
        // TCGplayer's own collection export never marks which physical
        // copy is holofoil/reverse-holofoil vs normal — found live
        // 2026-09-15 (Carlos owns 3 Lampent, one holo, but both export
        // lines for it are byte-identical apart from quantity). When
        // tcgdex knows this card has more than one price variant, we
        // genuinely cannot tell which copies are which, so every copy
        // is added with no variant set rather than silently guessing —
        // this flag drives a "review variant" note in the preview.
        public readonly bool $variantAmbiguous = false,
    ) {}
}
