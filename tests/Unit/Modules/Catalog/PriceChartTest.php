<?php

declare(strict_types=1);

use App\Modules\Catalog\Support\PriceChart;

/** @param  array<string, int>  $days  'Y-m-d' => minor units, oldest first */
function chartOf(array $days, bool $step = false): ?PriceChart
{
    return PriceChart::plot(array_map(fn (string $day, int $minor) => ['day' => $day, 'minor' => $minor], array_keys($days), array_values($days)), $step);
}

test('fewer than two priced days give nothing to draw', function () {
    expect(chartOf([]))->toBeNull()
        ->and(chartOf(['2026-10-01' => 1000]))->toBeNull()
        ->and(chartOf(['2026-10-01' => 1000], step: true))->toBeNull();
});

test('consecutive days are one line across the whole width, with an area under it and no gaps', function () {
    $chart = chartOf(['2026-10-01' => 1000, '2026-10-02' => 1200]);

    expect($chart->lines)->toBe(['M0.0 180.6L600.0 19.4'])
        ->and($chart->areas)->toBe(['M0.0 180.6L600.0 19.4L600.0 200.0L0.0 200.0Z'])
        ->and($chart->gaps)->toBe([])
        ->and($chart->dots)->toBe([])
        ->and($chart->step)->toBeFalse();
});

test('a day with no price breaks the line and its neighbours are joined by one gap segment', function () {
    $chart = chartOf(['2026-10-01' => 1000, '2026-10-02' => 1100, '2026-10-04' => 1200, '2026-10-05' => 1000]);

    expect($chart->lines)->toBe(['M0.0 180.6L150.0 100.0', 'M450.0 19.4L600.0 180.6'])
        ->and($chart->gaps)->toBe([['x1' => 150.0, 'y1' => 100.0, 'x2' => 450.0, 'y2' => 19.4]]);
});

test('a day with gaps on both sides is a dot, not a line', function () {
    $chart = chartOf(['2026-10-01' => 1000, '2026-10-03' => 1000, '2026-10-05' => 1000]);

    expect($chart->lines)->toBe([])
        ->and($chart->dots)->toBe([['x' => 0.0, 'y' => 100.0], ['x' => 300.0, 'y' => 100.0], ['x' => 600.0, 'y' => 100.0]])
        ->and($chart->gaps)->toHaveCount(2);
});

test('points sit by date, not by position in the list', function () {
    expect(array_column(chartOf(['2026-10-01' => 500, '2026-10-02' => 600, '2026-10-11' => 700])->points, 'x'))->toBe([0.0, 60.0, 600.0]);
});

test('the highest and lowest prices keep headroom from the edges, a flat series sits in the middle, and both ends are labelled', function () {
    $chart = chartOf(['2026-10-01' => 1000, '2026-10-02' => 1200]);

    expect([$chart->high, $chart->highY, $chart->low, $chart->lowY])->toBe([1200, 19.4, 1000, 180.6])
        ->and(array_column(chartOf(['2026-10-01' => 0, '2026-10-02' => 0])->points, 'y'))->toBe([100.0, 100.0]);
});

test('every point carries its day and price for the reading', function () {
    expect(chartOf(['2026-10-01' => 1000, '2026-10-02' => 1200])->points[0])
        ->toBe(['x' => 0.0, 'y' => 180.6, 'day' => '2026-10-01', 'minor' => 1000]);
});

test('a step series holds each price until the day it changed: one line, no gaps, no dots, however far apart the days are', function () {
    $chart = chartOf(['2026-10-01' => 1000, '2026-10-05' => 1200, '2026-10-11' => 1200], step: true);

    expect($chart->step)->toBeTrue()
        ->and($chart->lines)->toBe(['M0.0 180.6H240.0V19.4H600.0'])
        ->and($chart->areas)->toBe(['M0.0 180.6H240.0V19.4H600.0L600.0 200.0L0.0 200.0Z'])
        ->and($chart->gaps)->toBe([])
        ->and($chart->dots)->toBe([])
        ->and(array_column($chart->points, 'x'))->toBe([0.0, 240.0, 600.0]);
});

test('a step series that never changes is one flat line through the middle', function () {
    $chart = chartOf(['2026-10-01' => 1000, '2026-10-02' => 1000, '2026-10-06' => 1000], step: true);

    expect($chart->lines)->toBe(['M0.0 100.0H120.0H600.0'])
        ->and($chart->gaps)->toBe([]);
});
