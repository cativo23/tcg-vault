<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Support;

/**
 * The completion figure shown next to "x / y" on the public sets list. The
 * set page and the admin set grids still compute their own inline copy of
 * the rounding.
 */
final class Completion
{
    /**
     * Whole-percent completion, honest at both ends: a nonzero owned count
     * never reads 0%, and 100% means owned has reached the total, so 216 of
     * 217 reads 99%. An unknown total (zero or less) reads 0%. Owned past the
     * total is not capped.
     */
    public static function percent(int $owned, int $total): int
    {
        if ($total <= 0) {
            return 0;
        }

        $percent = max($owned > 0 ? 1 : 0, (int) round($owned / $total * 100));

        return $owned < $total ? min(99, $percent) : $percent;
    }
}
