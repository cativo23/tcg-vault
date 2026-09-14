<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Exceptions\Concerns;

trait SanitizesLogMessages
{
    /**
     * Strips control characters (CR/LF and other C0/DEL bytes) from a
     * string before it's embedded in an exception message that may reach
     * the application log via report(). Prevents log injection from any
     * value that ultimately traces back to external/user input (a
     * tcgdex response field, a Livewire property, a search query).
     */
    private static function sanitizeForLog(string $value): string
    {
        return preg_replace('/[\r\n\x00-\x1F\x7F]/', ' ', $value) ?? '';
    }
}
