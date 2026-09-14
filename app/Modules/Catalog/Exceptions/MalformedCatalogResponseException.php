<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Exceptions;

use App\Modules\Catalog\Exceptions\Concerns\SanitizesLogMessages;
use RuntimeException;

final class MalformedCatalogResponseException extends RuntimeException
{
    use SanitizesLogMessages;

    public static function forCard(string $tcgdexId, string $reason): self
    {
        return new self("Malformed tcgdex card response for [{$tcgdexId}]: {$reason}");
    }

    public static function forSet(string $tcgdexId, string $reason): self
    {
        return new self("Malformed tcgdex set response for [{$tcgdexId}]: {$reason}");
    }

    public static function forSearch(string $query, string $reason): self
    {
        // $query is raw, attacker-controlled Livewire input (the search
        // box) — this message reaches the log via report(), so control
        // characters (CR/LF) must be stripped here, at the one place this
        // string is built, rather than trusted to every future caller.
        return new self('Malformed tcgdex search response for query ['.self::sanitizeForLog($query)."]: {$reason}");
    }
}
