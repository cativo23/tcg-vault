<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Number;

/**
 * The only place minor units become a string. Everything in the DB is
 * bigint minor units + an ISO currency; nothing outside this class
 * divides by 100.
 */
final class Money
{
    public static function format(int $minor, string $currency): string
    {
        // Number::currency() returns false only if intl can't format at
        // all; show the plain amount rather than fail the whole page.
        return Number::currency($minor / 100, in: $currency, locale: 'en')
            ?: $currency.' '.number_format($minor / 100, 2);
    }

    /** "+$2.00" / "-$0.15" / "$0.00" — for deltas, where the sign is the point. */
    public static function signed(int $minor, string $currency): string
    {
        $formatted = self::format(abs($minor), $currency);

        return match (true) {
            $minor > 0 => '+'.$formatted,
            $minor < 0 => '-'.$formatted,
            default => $formatted,
        };
    }
}
