<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Support;

/**
 * Where each card of a set sits on its printed strip. One rule for every
 * strip in the app (the sets list's mini strip and the set page's strip):
 * a card whose number is plain digits between 1 and the set total sits at
 * that number; lettered numbers (TG05, SWSH050) and numbers outside the
 * total are grouped after the numbered run, in natural order; a number the
 * catalogue has not imported is a grey, unnumbered slot.
 *
 * The set total counts every card of the set, lettered ones included, so the
 * numbered run is the total less the lettered cards that are imported (never
 * shorter than the highest numbered card): each card takes one slot, and no
 * grey slot stands for a card that cannot exist. A partly imported set whose
 * numbers are all lettered (SWSH001...) still shows grey slots, because the
 * shape of the numbers that are not imported cannot be known.
 */
final class PrintedStrip
{
    public const OWNED = 'owned';

    public const MISSING = 'missing';

    public const ABSENT = 'absent';

    /**
     * @param  iterable<array{number: string, owned: bool}>  $cards  every imported card of the set, in any order
     * @return list<array{number: ?string, state: 'owned'|'missing'|'absent'}>
     */
    public static function slots(iterable $cards, int $total): array
    {
        $total = max($total, 0);

        $numbered = [];
        $extras = [];
        $highest = 0;
        foreach ($cards as $card) {
            $number = (string) $card['number'];
            $owned = $card['owned'];

            if (ctype_digit($number) && (int) $number >= 1 && (int) $number <= $total) {
                $position = (int) $number;
                $highest = max($highest, $position);
                // The longer spelling ('003' over '3') labels the slot, so the label never depends on input order.
                $numbered[$position] = isset($numbered[$position])
                    ? ['number' => self::longer($numbered[$position]['number'], $number), 'owned' => $numbered[$position]['owned'] || $owned]
                    : ['number' => $number, 'owned' => $owned];

                continue;
            }

            $extras[$number] = ['number' => $number, 'owned' => ($extras[$number]['owned'] ?? false) || $owned];
        }
        uasort($extras, fn (array $a, array $b) => strnatcasecmp($a['number'], $b['number']));

        $slots = [];
        $length = max($highest, $total - count($extras));
        for ($position = 1; $position <= $length; $position++) {
            $slots[] = isset($numbered[$position])
                ? self::slot($numbered[$position])
                : ['number' => null, 'state' => self::ABSENT];
        }
        foreach ($extras as $extra) {
            $slots[] = self::slot($extra);
        }

        return $slots;
    }

    /**
     * Consecutive owned slots, and consecutive missing slots, as percentages
     * of the whole strip. Absent slots are the track itself and are skipped.
     * The markup grows with the runs, not with the size of the set.
     *
     * @param  list<array{number: ?string, state: 'owned'|'missing'|'absent'}>  $slots
     * @return list<array{state: 'owned'|'missing', left: float, width: float}>
     */
    public static function runs(array $slots): array
    {
        $length = count($slots);
        $runs = [];
        $state = null;
        $start = 0;

        foreach ($slots as $index => $slot) {
            if ($slot['state'] === $state) {
                continue;
            }
            if ($state !== null && $state !== self::ABSENT) {
                $runs[] = self::run($state, $start, $index, $length);
            }
            $state = $slot['state'];
            $start = $index;
        }
        if ($state !== null && $state !== self::ABSENT) {
            $runs[] = self::run($state, $start, $length, $length);
        }

        return $runs;
    }

    /**
     * @param  array{number: string, owned: bool}  $card
     * @return array{number: string, state: 'owned'|'missing'}
     */
    private static function slot(array $card): array
    {
        return ['number' => $card['number'], 'state' => $card['owned'] ? self::OWNED : self::MISSING];
    }

    private static function longer(string $a, string $b): string
    {
        if (strlen($a) !== strlen($b)) {
            return strlen($a) > strlen($b) ? $a : $b;
        }

        return strcmp($a, $b) <= 0 ? $a : $b;
    }

    /**
     * @param  'owned'|'missing'  $state
     * @return array{state: 'owned'|'missing', left: float, width: float}
     */
    private static function run(string $state, int $start, int $end, int $length): array
    {
        return [
            'state' => $state,
            'left' => round($start / $length * 100, 4),
            'width' => round(($end - $start) / $length * 100, 4),
        ];
    }
}
