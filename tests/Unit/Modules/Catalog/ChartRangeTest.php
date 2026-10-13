<?php

declare(strict_types=1);

use App\Modules\Catalog\Support\ChartRange;
use Carbon\CarbonImmutable;

test('the four ranges keep their lengths, strides and labels', function () {
    expect(array_map(fn (ChartRange $r) => [$r->value, $r->days(), $r->stride(), $r->button(), $r->label()], ChartRange::cases()))->toBe([
        ['1m', 30, 1, '1M', '1 month'],
        ['3m', 90, 3, '3M', '3 months'],
        ['6m', 180, 7, '6M', '6 months'],
        ['1y', 365, 7, '1Y', '1 year'],
    ]);
});

test('anything that is not one of the four values falls back to one month', function (mixed $input) {
    expect(ChartRange::fromInput($input))->toBe(ChartRange::OneMonth);
})->with([[null], [''], ['7d'], ['1M'], [['3m']], [3]]);

test('a valid value parses to its range', function () {
    expect(ChartRange::fromInput('6m'))->toBe(ChartRange::SixMonths);
});

test('the range starts its length in days before today', function () {
    expect(ChartRange::ThreeMonths->start(CarbonImmutable::parse('2026-10-13'))->toDateString())->toBe('2026-07-15');
});

test('sample days count back from today by the stride, oldest first', function () {
    $days = ChartRange::ThreeMonths->sampleDays(CarbonImmutable::parse('2026-10-13'), CarbonImmutable::parse('2026-01-01'));

    expect($days[array_key_last($days)]->toDateString())->toBe('2026-10-13')
        ->and($days[array_key_last($days) - 1]->toDateString())->toBe('2026-10-10')
        ->and($days[0]->toDateString())->toBe('2026-07-15')
        ->and(count($days))->toBe(31);
});

test('one month samples every day, 31 points including both ends', function () {
    $days = ChartRange::OneMonth->sampleDays(CarbonImmutable::parse('2026-10-13'), CarbonImmutable::parse('2026-01-01'));

    expect(count($days))->toBe(31)->and($days[0]->toDateString())->toBe('2026-09-13');
});

test('sample days never start before the earliest data day', function () {
    $days = ChartRange::OneYear->sampleDays(CarbonImmutable::parse('2026-10-13'), CarbonImmutable::parse('2026-09-20'));

    expect(array_map(fn ($d) => $d->toDateString(), $days))->toBe(['2026-09-22', '2026-09-29', '2026-10-06', '2026-10-13']);
});
