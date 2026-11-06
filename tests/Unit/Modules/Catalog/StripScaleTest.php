<?php

declare(strict_types=1);

use App\Modules\Catalog\Support\StripScale;

test('the scale marks the first slot, every fiftieth, and the last, never one crowding the last', function () {
    expect(StripScale::ticks(207))->toBe([
        ['label' => 1, 'left' => '0.00'],
        ['label' => 50, 'left' => '23.79'],
        ['label' => 100, 'left' => '48.06'],
        ['label' => 150, 'left' => '72.33'],
        ['label' => 207, 'left' => '100.00'],
    ]);
    // 150 would sit ten slots from 160: dropped.
    expect(array_column(StripScale::ticks(160), 'label'))->toBe([1, 50, 100, 160]);
    expect(array_column(StripScale::ticks(5), 'label'))->toBe([1, 5]);
});

test('a strip of one slot has one mark and an empty strip has none', function () {
    expect(StripScale::ticks(1))->toBe([['label' => 1, 'left' => '0.00']])
        ->and(StripScale::ticks(0))->toBe([]);
});
