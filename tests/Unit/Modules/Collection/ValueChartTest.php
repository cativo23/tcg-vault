<?php

declare(strict_types=1);

use App\Modules\Collection\Support\ValueChart;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * A value series as the page receives it, from day => minor units.
 *
 * @param  array<string, int>  $points
 * @return Collection<int, array{date: CarbonImmutable, minor: int}>
 */
function activitySeries(array $points): Collection
{
    return collect($points)->map(fn (int $minor, string $day) => ['date' => CarbonImmutable::parse($day), 'minor' => $minor])->values();
}

test('one point or none is not a chart', function () {
    expect(ValueChart::of(activitySeries([]), 'USD'))->toBeNull()
        ->and(ValueChart::of(activitySeries(['2026-10-01' => 100]), 'USD'))->toBeNull();
});

test('x follows the date, not the index, and y leaves headroom above the high and below the low', function () {
    $chart = ValueChart::of(activitySeries(['2026-10-01' => 100, '2026-10-03' => 300, '2026-10-04' => 200]), 'USD');

    expect(array_column($chart->points, 'x'))->toBe([0.0, 66.67, 100.0])
        ->and(array_column($chart->points, 'y'))->toBe([89.06, 10.94, 50.0])
        ->and($chart->highY)->toBe(10.94)
        ->and($chart->lowY)->toBe(89.06)
        ->and($chart->endY)->toBe(50.0);
});

test('the line and its area are drawn in viewBox units', function () {
    $chart = ValueChart::of(activitySeries(['2026-10-01' => 100, '2026-10-03' => 300, '2026-10-04' => 200]), 'USD');

    expect($chart->line)->toBe('M0.0 178.1L400.0 21.9L600.0 100.0')
        ->and($chart->area)->toBe('M0.0 178.1L400.0 21.9L600.0 100.0L600 200L0 200Z');
});

test('a flat series sits on the middle line, zero included', function (int $minor) {
    $chart = ValueChart::of(activitySeries(['2026-10-01' => $minor, '2026-10-02' => $minor]), 'USD');

    expect(array_column($chart->points, 'y'))->toBe([50.0, 50.0])
        ->and($chart->up)->toBeFalse();
})->with([[500], [0]]);

test('marks and readings are formatted money and short dates', function () {
    $chart = ValueChart::of(activitySeries(['2026-10-01' => 100, '2026-10-03' => 300, '2026-10-04' => 200]), 'USD');

    expect($chart->high)->toBe('$3.00')
        ->and($chart->low)->toBe('$1.00')
        ->and(array_map(fn (array $p) => [$p['d'], $p['v']], $chart->points))->toBe([['1 Oct', '$1.00'], ['3 Oct', '$3.00'], ['4 Oct', '$2.00']]);
});

test('the chart is up only when it ends higher than it started', function (array $points, bool $up) {
    expect(ValueChart::of(activitySeries($points), 'USD')->up)->toBe($up);
})->with([
    'rise' => [['2026-10-01' => 100, '2026-10-02' => 300, '2026-10-03' => 200], true],
    'fall' => [['2026-10-01' => 300, '2026-10-02' => 200], false],
]);
