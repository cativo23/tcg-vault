<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Exceptions;

use RuntimeException;

final class CatalogIdentityMismatchException extends RuntimeException
{
    public static function forCardMismatch(string $requested, string $actual): self
    {
        return new self(sprintf(
            'Catalog provider returned card [%s] when [%s] was requested.',
            $actual,
            $requested,
        ));
    }

    public static function forSetMismatch(string $requested, string $actual): self
    {
        return new self(sprintf(
            'Catalog provider returned set [%s] when [%s] was requested.',
            $actual,
            $requested,
        ));
    }
}
