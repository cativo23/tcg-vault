<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Tcgcsv;

/**
 * A card number as TCGplayer prints it ("152/128", "055", "TG01/TG30"): the
 * number and, when given, the set total after the slash. Leading zeros of a
 * plain number don't count ("055" is "55"); a lettered number keeps its
 * letters and is compared without regard to case.
 */
final readonly class PrintedNumber
{
    private function __construct(
        public string $number,
        public ?string $total,
    ) {}

    public static function parse(?string $value): ?self
    {
        if ($value === null || preg_match('/^\s*([A-Za-z0-9]+)(?:\/([A-Za-z0-9]+))?\s*$/D', $value, $parts) !== 1) {
            return null;
        }

        return new self(self::normalise($parts[1]), isset($parts[2]) ? self::normalise($parts[2]) : null);
    }

    /** Whether this is the number a card carries as its local id. */
    public function isNumber(string $localId): bool
    {
        return strtolower($this->number) === strtolower(self::normalise(trim($localId)));
    }

    /** Whether the total after the slash is a set's printed card count. */
    public function isTotal(int $official): bool
    {
        return $this->total === (string) $official;
    }

    private static function normalise(string $part): string
    {
        return ctype_digit($part) ? (ltrim($part, '0') ?: '0') : $part;
    }
}
