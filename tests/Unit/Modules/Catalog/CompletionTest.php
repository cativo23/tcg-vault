<?php

declare(strict_types=1);

use App\Modules\Catalog\Support\Completion;

test('completion rounds to a whole percent but never down to 0% when a card is owned', function (int $owned, int $total, int $percent) {
    expect(Completion::percent($owned, $total))->toBe($percent);
})->with([
    'one of a huge set' => [1, 217, 1],
    'two of 120' => [2, 120, 2],
    'none owned' => [0, 10, 0],
    'no known total' => [5, 0, 0],
    'complete' => [120, 120, 100],
]);
