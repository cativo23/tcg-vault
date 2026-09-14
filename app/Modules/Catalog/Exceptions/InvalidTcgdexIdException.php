<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Exceptions;

use InvalidArgumentException;

final class InvalidTcgdexIdException extends InvalidArgumentException
{
    public static function forId(string $id): self
    {
        return new self("Invalid tcgdex ID format: [{$id}].");
    }
}
