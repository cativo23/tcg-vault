<?php

declare(strict_types=1);

use App\Modules\Catalog\Support\PrintedStrip;

test('numbered cards sit at their number, owned or missing, in any order', function () {
    $slots = PrintedStrip::slots([
        ['number' => '002', 'owned' => true],
        ['number' => '1', 'owned' => false],
        ['number' => '3', 'owned' => true],
    ], 3);

    expect($slots)->toBe([
        ['number' => '1', 'state' => 'missing'],
        ['number' => '002', 'state' => 'owned'],
        ['number' => '3', 'state' => 'owned'],
    ]);
});

test('lettered numbers are grouped after the numbered run, in natural order', function () {
    $slots = PrintedStrip::slots([
        ['number' => 'TG02', 'owned' => true],
        ['number' => '1', 'owned' => true],
        ['number' => 'TG10', 'owned' => false],
        ['number' => 'TG01', 'owned' => false],
    ], 3);

    expect($slots)->toBe([
        ['number' => '1', 'state' => 'owned'],
        ['number' => null, 'state' => 'absent'],
        ['number' => null, 'state' => 'absent'],
        ['number' => 'TG01', 'state' => 'missing'],
        ['number' => 'TG02', 'state' => 'owned'],
        ['number' => 'TG10', 'state' => 'missing'],
    ]);
});

test('a partly imported set keeps its full length: one of 217 is one numbered slot among grey ones', function () {
    $slots = PrintedStrip::slots([['number' => '186', 'owned' => true]], 217);

    expect($slots)->toHaveCount(217)
        ->and($slots[185])->toBe(['number' => '186', 'state' => 'owned'])
        ->and(array_count_values(array_column($slots, 'state')))->toBe(['absent' => 216, 'owned' => 1])
        ->and($slots[0])->toBe(['number' => null, 'state' => 'absent']);
});

test('the same number written two ways is one slot, owned if either is', function () {
    $slots = PrintedStrip::slots([
        ['number' => '003', 'owned' => false],
        ['number' => '3', 'owned' => true],
    ], 5);

    expect($slots)->toHaveCount(5)
        ->and($slots[2])->toBe(['number' => '003', 'state' => 'owned']);
});

test('numbers outside 1..total (0, above the total) are treated like lettered ones', function () {
    $slots = PrintedStrip::slots([
        ['number' => '11', 'owned' => true],
        ['number' => '0', 'owned' => false],
        ['number' => '2', 'owned' => true],
    ], 10);

    expect($slots)->toHaveCount(12)
        ->and($slots[1])->toBe(['number' => '2', 'state' => 'owned'])
        ->and(array_slice($slots, 10))->toBe([
            ['number' => '0', 'state' => 'missing'],
            ['number' => '11', 'state' => 'owned'],
        ]);
});

test('no cards and no total is an empty strip; no total keeps the lettered cards', function () {
    expect(PrintedStrip::slots([], 0))->toBe([])
        ->and(PrintedStrip::slots([['number' => 'TG01', 'owned' => true]], 0))->toBe([['number' => 'TG01', 'state' => 'owned']]);
});

test('consecutive owned slots become one run and consecutive missing slots another, as percentages of the strip', function () {
    $cards = array_map(fn (int $n) => ['number' => (string) $n, 'owned' => in_array($n, [1, 2, 3, 5], true)], range(1, 10));

    expect(PrintedStrip::runs(PrintedStrip::slots($cards, 10)))->toBe([
        ['state' => 'owned', 'left' => 0.0, 'width' => 30.0],
        ['state' => 'missing', 'left' => 30.0, 'width' => 10.0],
        ['state' => 'owned', 'left' => 40.0, 'width' => 10.0],
        ['state' => 'missing', 'left' => 50.0, 'width' => 50.0],
    ]);
});

test('grey slots are the track: they break a run and are not emitted', function () {
    expect(PrintedStrip::runs(PrintedStrip::slots([['number' => '186', 'owned' => true]], 217)))->toBe([
        ['state' => 'owned', 'left' => 85.2535, 'width' => 0.4608],
    ])
        ->and(PrintedStrip::runs(PrintedStrip::slots([
            ['number' => '3', 'owned' => true],
            ['number' => '116', 'owned' => true],
        ], 120)))->toBe([
            ['state' => 'owned', 'left' => 1.6667, 'width' => 0.8333],
            ['state' => 'owned', 'left' => 95.8333, 'width' => 0.8333],
        ]);
});

test('a complete set is one full owned run, and an empty strip has no runs', function () {
    $cards = array_map(fn (int $n) => ['number' => (string) $n, 'owned' => true], range(1, 4));

    expect(PrintedStrip::runs(PrintedStrip::slots($cards, 4)))->toBe([['state' => 'owned', 'left' => 0.0, 'width' => 100.0]])
        ->and(PrintedStrip::runs([]))->toBe([]);
});
