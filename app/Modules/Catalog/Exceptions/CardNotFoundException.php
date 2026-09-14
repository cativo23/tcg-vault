<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Exceptions;

use App\Modules\Catalog\Exceptions\Concerns\SanitizesLogMessages;
use RuntimeException;

final class CardNotFoundException extends RuntimeException
{
    use SanitizesLogMessages;

    public static function forTcgdexId(string $tcgdexId): self
    {
        return new self('No card found on tcgdex for ID ['.self::sanitizeForLog($tcgdexId).'].');
    }
}
