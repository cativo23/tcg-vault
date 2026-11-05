<?php

declare(strict_types=1);

use App\Modules\Catalog\Support\Completion;

test('completion rounds to a whole percent, never down to 0% while a card is owned and never up to 100% while one is missing', function (int $owned, int $total, int $percent) {
    expect(Completion::percent($owned, $total))->toBe($percent);
})->with([
    'one of a huge set' => [1, 217, 1],
    'two of 120' => [2, 120, 2],
    'none owned' => [0, 10, 0],
    'no known total' => [5, 0, 0],
    'complete' => [120, 120, 100],
    'one short of a big set' => [216, 217, 99],
    'one short of 200' => [199, 200, 99],
    'complete at 217' => [217, 217, 100],
    'complete at 200' => [200, 200, 100],
    'owned past the total is not capped' => [121, 120, 101],
]);
