<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Exceptions;

use RuntimeException;

final class SetNotFoundException extends RuntimeException
{
    public static function forTcgdexId(string $tcgdexId): self
    {
        return new self("No set found on tcgdex for ID [{$tcgdexId}].");
    }
}
