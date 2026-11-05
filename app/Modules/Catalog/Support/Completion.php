<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Support;

/** The completion figure shown next to "x / y": one rule for every page. */
final class Completion
{
    /** Whole-percent completion; a set with at least one card owned never reads 0%. Not capped. */
    public static function percent(int $owned, int $total): int
    {
        if ($total <= 0) {
            return 0;
        }

        return max($owned > 0 ? 1 : 0, (int) round($owned / $total * 100));
    }
}
