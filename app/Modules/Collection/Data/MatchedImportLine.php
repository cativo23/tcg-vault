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
    ) {}
}
