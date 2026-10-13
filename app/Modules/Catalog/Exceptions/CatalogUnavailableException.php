<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Exceptions;

use RuntimeException;
use Throwable;

final class CatalogUnavailableException extends RuntimeException
{
    /** True for the short failure window after a failed attempt: the outage was already reported when it began. */
    public bool $isBackoff = false;

    public static function forSetListing(Throwable $previous): self
    {
        return new self('The tcgdex set list is unreachable and no cached copy exists.', 0, $previous);
    }

    public static function forSetListingBackoff(): self
    {
        $exception = new self('The tcgdex set list failed moments ago and no cached copy exists; not retrying yet.');
        $exception->isBackoff = true;

        return $exception;
    }
}
