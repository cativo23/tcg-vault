<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Support;

/**
 * The scale under a set's printed strip: a mark at the first slot, at every
 * fiftieth, and at the last. The slots themselves are shaped elsewhere
 * (PrintedStrip, shared with the sets list); this only places the marks.
 */
final class StripScale
{
    /** Marks fall on every fiftieth slot... */
    private const TICK_EVERY = 50;

    /** ...except one this close to the last slot, whose label it would collide with. */
    private const TICK_CLEARANCE = 20;

    /** @return list<array{label: int, left: string}> left is the mark's offset as a percentage of the strip */
    public static function ticks(int $count): array
    {
        if ($count < 1) {
            return [];
        }

        if ($count === 1) {
            return [['label' => 1, 'left' => '0.00']];
        }

        $marks = [1];
        for ($n = self::TICK_EVERY; $n <= $count - self::TICK_CLEARANCE; $n += self::TICK_EVERY) {
            $marks[] = $n;
        }
        $marks[] = $count;

        return array_map(fn (int $n) => [
            'label' => $n,
            'left' => number_format(($n - 1) / ($count - 1) * 100, 2, '.', ''),
        ], $marks);
    }
}
