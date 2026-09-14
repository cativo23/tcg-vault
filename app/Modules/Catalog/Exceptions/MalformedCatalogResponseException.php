<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Exceptions;

use RuntimeException;

final class MalformedCatalogResponseException extends RuntimeException
{
    public static function forCard(string $tcgdexId, string $reason): self
    {
        return new self("Malformed tcgdex card response for [{$tcgdexId}]: {$reason}");
    }

    public static function forSet(string $tcgdexId, string $reason): self
    {
        return new self("Malformed tcgdex set response for [{$tcgdexId}]: {$reason}");
    }
}
