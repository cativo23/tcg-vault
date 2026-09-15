<?php

declare(strict_types=1);

namespace App\Modules\Collection\Data;

use Illuminate\Support\Collection;
use Spatie\LaravelData\Data;

final class ParsedImport extends Data
{
    public function __construct(
        /** @var Collection<int, MatchedImportLine> */
        public readonly Collection $matched,
        /** @var Collection<int, UnmatchedImportLine> */
        public readonly Collection $unmatched,
    ) {}
}
