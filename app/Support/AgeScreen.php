<?php

declare(strict_types=1);

namespace App\Support;

use Carbon\CarbonInterface;

/**
 * The neutral age screen both signup forms run: 13 is the minimum age,
 * and 13–17 need a parent's or guardian's permission. Works from birth
 * month and year only, and errs young — a birthday counts as passed only
 * once its month is over — so nobody is let through early.
 */
final class AgeScreen
{
    /** Set once someone is refused, so re-entering a different date is refused too. */
    public const BLOCK_COOKIE = 'tcgv_signup_blocked';

    public const MINIMUM_AGE = 13;

    public const ADULT_AGE = 18;

    public static function ageOn(int $month, int $year, CarbonInterface $today): int
    {
        $age = $today->year - $year;

        return $today->month <= $month ? $age - 1 : $age;
    }

    public static function isUnder13(int $month, int $year, CarbonInterface $today): bool
    {
        return self::ageOn($month, $year, $today) < self::MINIMUM_AGE;
    }

    public static function needsGuardian(int $month, int $year, CarbonInterface $today): bool
    {
        return self::ageOn($month, $year, $today) < self::ADULT_AGE;
    }
}
