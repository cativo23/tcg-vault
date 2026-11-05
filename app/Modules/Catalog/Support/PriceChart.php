<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Support;

use Carbon\CarbonImmutable;

/**
 * The geometry of a card's price chart, in a fixed WIDTH x HEIGHT box the
 * page stretches to its container. Points sit by date, not by position.
 *
 * A market series has one row per day, so a day without a price (no row, or a
 * row with no market price: historyIn() returns priced rows only) leaves a
 * hole: the line breaks there and the two neighbours are joined by a gap
 * segment the page draws dotted; a day with a gap on both sides is a dot.
 *
 * A manual series ($step) is a price typed in once that stands until it is
 * changed, so its rows are days apart by nature: it is one step line (each
 * price holds until the day the next one starts) with no gaps and no dots.
 *
 * The highest and lowest prices keep HEADROOM of the span clear of the edges;
 * a flat series sits in the middle.
 */
final readonly class PriceChart
{
    public const WIDTH = 600;

    public const HEIGHT = 200;

    private const HEADROOM = 0.12;

    /**
     * @param  list<string>  $lines  SVG path data, one per run of consecutive days (one in step mode)
     * @param  list<string>  $areas  the same runs closed down to the bottom edge
     * @param  list<array{x1: float, y1: float, x2: float, y2: float}>  $gaps
     * @param  list<array{x: float, y: float}>  $dots
     * @param  list<array{x: float, y: float, day: string, minor: int}>  $points
     */
    private function __construct(
        public array $lines,
        public array $areas,
        public array $gaps,
        public array $dots,
        public array $points,
        public int $high,
        public float $highY,
        public int $low,
        public float $lowY,
        public bool $step,
    ) {}

    /**
     * @param  array<int, array{day: string, minor: int}>  $series  oldest first, one entry per day
     */
    public static function plot(array $series, bool $step = false): ?self
    {
        $series = array_values($series);

        if (count($series) < 2) {
            return null;
        }

        $minors = array_map(fn (array $p): int => $p['minor'], $series);
        $high = max($minors);
        $low = min($minors);
        $span = ($high - $low) ?: (($high * 0.02) ?: 1);
        $top = $high + $span * self::HEADROOM;
        $bottom = $low - $span * self::HEADROOM;

        $first = self::dayNumber($series[0]['day']);
        $days = max(1, self::dayNumber($series[count($series) - 1]['day']) - $first);
        $y = fn (int $minor): float => round((1 - ($minor - $bottom) / ($top - $bottom)) * self::HEIGHT, 1);

        $points = [];
        foreach ($series as $p) {
            $points[] = [
                'x' => round((self::dayNumber($p['day']) - $first) / $days * self::WIDTH, 1),
                'y' => $y($p['minor']),
                'day' => $p['day'],
                'minor' => $p['minor'],
            ];
        }

        if ($step) {
            return self::stepped($points, $high, $y($high), $low, $y($low));
        }

        /** @var list<list<array{x: float, y: float, day: string, minor: int}>> $runs */
        $runs = [[$points[0]]];
        $gaps = [];
        for ($i = 1, $n = count($points); $i < $n; $i++) {
            if (self::dayNumber($points[$i]['day']) - self::dayNumber($points[$i - 1]['day']) > 1) {
                $gaps[] = ['x1' => $points[$i - 1]['x'], 'y1' => $points[$i - 1]['y'], 'x2' => $points[$i]['x'], 'y2' => $points[$i]['y']];
                $runs[] = [$points[$i]];
            } else {
                $runs[count($runs) - 1][] = $points[$i];
            }
        }

        $lines = [];
        $areas = [];
        $dots = [];
        foreach ($runs as $run) {
            if (count($run) === 1) {
                $dots[] = ['x' => $run[0]['x'], 'y' => $run[0]['y']];

                continue;
            }

            $path = '';
            foreach ($run as $k => $p) {
                $path .= sprintf('%s%.1F %.1F', $k === 0 ? 'M' : 'L', $p['x'], $p['y']);
            }
            $lines[] = $path;
            $areas[] = $path.sprintf('L%.1F %.1FL%.1F %.1FZ', $run[count($run) - 1]['x'], self::HEIGHT, $run[0]['x'], self::HEIGHT);
        }

        return new self($lines, $areas, $gaps, $dots, $points, $high, $y($high), $low, $y($low), false);
    }

    /**
     * @param  list<array{x: float, y: float, day: string, minor: int}>  $points
     */
    private static function stepped(array $points, int $high, float $highY, int $low, float $lowY): self
    {
        $path = sprintf('M%.1F %.1F', $points[0]['x'], $points[0]['y']);
        for ($i = 1, $n = count($points); $i < $n; $i++) {
            // Hold the previous price across to the new day, then jump if it changed.
            $path .= sprintf('H%.1F', $points[$i]['x']);
            if ($points[$i]['y'] !== $points[$i - 1]['y']) {
                $path .= sprintf('V%.1F', $points[$i]['y']);
            }
        }

        $last = $points[count($points) - 1];
        $area = $path.sprintf('L%.1F %.1FL%.1F %.1FZ', $last['x'], self::HEIGHT, $points[0]['x'], self::HEIGHT);

        return new self([$path], [$area], [], [], $points, $high, $highY, $low, $lowY, true);
    }

    /** Whole days since the epoch, so two dates subtract to a day count whatever the time zone. */
    private static function dayNumber(string $day): int
    {
        return intdiv(CarbonImmutable::parse(substr($day, 0, 10), 'UTC')->getTimestamp(), 86400);
    }
}
