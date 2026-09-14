<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Exceptions;

use App\Modules\Catalog\Exceptions\Concerns\SanitizesLogMessages;
use InvalidArgumentException;

final class InvalidTcgdexIdException extends InvalidArgumentException
{
    use SanitizesLogMessages;

    public static function forId(string $id): self
    {
        return new self('Invalid tcgdex ID format: ['.self::sanitizeForLog($id).'].');
    }
}
