<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Exceptions;

use RuntimeException;
use Throwable;

final class CatalogUnavailableException extends RuntimeException
{
    public static function forSetListing(Throwable $previous): self
    {
        return new self('The tcgdex set list is unreachable and no cached copy exists.', 0, $previous);
    }

    public static function forSetListingBackoff(): self
    {
        return new self('The tcgdex set list failed moments ago and no cached copy exists; not retrying yet.');
    }
}
