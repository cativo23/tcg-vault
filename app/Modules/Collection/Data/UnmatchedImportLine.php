<?php

declare(strict_types=1);

namespace App\Modules\Collection\Data;

use Spatie\LaravelData\Data;

final class UnmatchedImportLine extends Data
{
    public function __construct(
        public readonly string $rawLine,
        /** One of: 'unparsed', 'unknown_set', 'card_not_found'. */
        public readonly string $reason,
    ) {}
}
