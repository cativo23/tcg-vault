<?php

declare(strict_types=1);

namespace App\Modules\Collection\Support;

use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * The Activity page's value chart as numbers the view draws: the line and
 * its area in a WIDTH × HEIGHT viewBox that the page stretches to its box,
 * and every point, the high and low marks and the end as percentages of
 * that box, for HTML overlays a stretched SVG would distort.
 *
 * x is by date, not by index: the earliest priced day can sit off the
 * range's sampling grid (ChartRange::sampleDays()), and its shorter step is
 * drawn as such. y leaves HEADROOM of the value span above the high and
 * below the low; a flat series sits on the middle line. The series has a
 * value on every sampled day, so the line is one unbroken run.
 */
final readonly class ValueChart
{
    public const WIDTH = 600;

    public const HEIGHT = 200;

    private const HEADROOM = 0.14;

    /**
     * @param  list<array{x: float, y: float, d: string, v: string}>  $points
     */
    private function __construct(
        public string $line,
        public string $area,
        public array $points,
        public float $highY,
        public float $lowY,
        public float $endY,
        public string $high,
        public string $low,
        public bool $up,
    ) {}

    /**
     * Null under two points: one day is not a line.
     *
     * @param  Collection<int, array{date: CarbonImmutable, minor: int}>  $series  oldest first
     */
    public static function of(Collection $series, string $currency): ?self
    {
        $list = array_values($series->values()->all());

        if (count($list) < 2) {
            return null;
        }

        $first = $list[0];
        $last = $list[count($list) - 1];
        $minors = array_column($list, 'minor');
        $high = max($minors);
        $low = min($minors);
        $pad = $high > $low ? ($high - $low) * self::HEADROOM : max(abs($high) * 0.02, 1);
        $top = $high + $pad;
        $span = $top - ($low - $pad);
        $dayOf = fn (CarbonImmutable $day): int => (int) round($first['date']->diffInDays($day, true));
        $days = max(1, $dayOf($last['date']));
        $yOf = fn (int $minor): float => round(($top - $minor) / $span * 100, 2);

        $points = array_map(fn (array $p) => [
            'x' => round($dayOf($p['date']) / $days * 100, 2),
            'y' => $yOf($p['minor']),
            'd' => $p['date']->format('j M'),
            'v' => Money::format($p['minor'], $currency),
        ], $list);

        // %F, not %f: the decimal point must not follow the locale.
        $line = 'M'.implode('L', array_map(fn (array $p) => sprintf('%.1F %.1F', $p['x'] * self::WIDTH / 100, $p['y'] * self::HEIGHT / 100), $points));

        return new self(
            line: $line,
            area: $line.sprintf('L%d %dL0 %dZ', self::WIDTH, self::HEIGHT, self::HEIGHT),
            points: $points,
            highY: $yOf($high),
            lowY: $yOf($low),
            endY: $yOf($last['minor']),
            high: Money::format($high, $currency),
            low: Money::format($low, $currency),
            up: $last['minor'] > $first['minor'],
        );
    }
}
