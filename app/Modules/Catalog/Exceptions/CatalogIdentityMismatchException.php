<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Exceptions;

use App\Modules\Catalog\Exceptions\Concerns\SanitizesLogMessages;
use RuntimeException;

final class CatalogIdentityMismatchException extends RuntimeException
{
    use SanitizesLogMessages;

    public static function forCardMismatch(string $requested, string $actual): self
    {
        return new self(sprintf(
            'Catalog provider returned card [%s] when [%s] was requested.',
            self::sanitizeForLog($actual),
            self::sanitizeForLog($requested),
        ));
    }

    public static function forSetMismatch(string $requested, string $actual): self
    {
        return new self(sprintf(
            'Catalog provider returned set [%s] when [%s] was requested.',
            self::sanitizeForLog($actual),
            self::sanitizeForLog($requested),
        ));
    }
}
