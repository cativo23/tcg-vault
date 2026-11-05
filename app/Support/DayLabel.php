<?php

declare(strict_types=1);

namespace App\Support;

use Carbon\CarbonInterface;

/**
 * A day as a short label: "4 Nov" in the current year, "4 Nov 2025" in any
 * other, so a date that is not this year's never reads as one.
 */
final class DayLabel
{
    public static function of(CarbonInterface $day): string
    {
        return $day->format($day->year === today()->year ? 'j M' : 'j M Y');
    }
}
