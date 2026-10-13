<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * How far back a price chart looks. The value is what `?range=` carries.
 * Long ranges are sampled (every 3rd or 7th day) so a chart never plots
 * more than about fifty points, whatever the range.
 */
enum ChartRange: string
{
    case OneMonth = '1m';
    case ThreeMonths = '3m';
    case SixMonths = '6m';
    case OneYear = '1y';

    /** The URL is untrusted: anything but one of the four values (an array included) is the default. */
    public static function fromInput(mixed $value): self
    {
        return (is_string($value) ? self::tryFrom($value) : null) ?? self::OneMonth;
    }

    public function days(): int
    {
        return match ($this) {
            self::OneMonth => 30,
            self::ThreeMonths => 90,
            self::SixMonths => 180,
            self::OneYear => 365,
        };
    }

    /** Days between two sampled points on the collection chart. */
    public function stride(): int
    {
        return match ($this) {
            self::OneMonth => 1,
            self::ThreeMonths => 3,
            self::SixMonths, self::OneYear => 7,
        };
    }

    public function button(): string
    {
        return strtoupper($this->value);
    }

    public function label(): string
    {
        return match ($this) {
            self::OneMonth => '1 month',
            self::ThreeMonths => '3 months',
            self::SixMonths => '6 months',
            self::OneYear => '1 year',
        };
    }

    /** First day of the range, inclusive — the same shape as CardPriceSnapshot::recentFrom(). */
    public function start(CarbonInterface $today): CarbonImmutable
    {
        return CarbonImmutable::parse($today->toDateString())->subDays($this->days());
    }

    /**
     * The days a sampled chart plots, oldest first: today, then back by
     * the stride, stopping at the range start or the earliest day with
     * data — a point before any price exists would chart a zero nobody
     * owned. Counting back from today keeps the last point on today.
     *
     * @return list<CarbonImmutable>
     */
    public function sampleDays(CarbonInterface $today, CarbonInterface $earliest): array
    {
        $floor = max($this->start($today)->toDateString(), $earliest->toDateString());
        $days = [];

        for ($day = CarbonImmutable::parse($today->toDateString()); $day->toDateString() >= $floor; $day = $day->subDays($this->stride())) {
            $days[] = $day;
        }

        return array_reverse($days);
    }
}
