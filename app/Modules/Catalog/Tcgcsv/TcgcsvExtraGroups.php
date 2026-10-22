<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Tcgcsv;

/**
 * The TCGplayer groups in config `tcgcsv.extra_groups`: groups of products
 * that belong to many sets, so no set maps them. Only positive integer ids
 * count — a stray entry is dropped, never read as group 0.
 */
final class TcgcsvExtraGroups
{
    /** @return list<int> */
    public static function ids(): array
    {
        $configured = config('tcgcsv.extra_groups');
        $ids = [];

        foreach (is_array($configured) ? $configured : [] as $entry) {
            if (is_int($entry) ? $entry > 0 : (is_string($entry) && preg_match('/^[1-9]\d*$/D', $entry) === 1)) {
                $ids[] = (int) $entry;
            }
        }

        return array_values(array_unique($ids));
    }
}
