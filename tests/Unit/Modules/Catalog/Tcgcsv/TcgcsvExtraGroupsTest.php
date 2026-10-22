<?php

declare(strict_types=1);

use App\Modules\Catalog\Tcgcsv\TcgcsvExtraGroups;

test('the extra groups are the configured group ids', function () {
    config(['tcgcsv.extra_groups' => [1840, '2374']]);

    expect(TcgcsvExtraGroups::ids())->toBe([1840, 2374]);
});

test('an entry that is not a positive integer id is ignored, never read as group 0', function (mixed $entry) {
    config(['tcgcsv.extra_groups' => [1840, $entry]]);

    expect(TcgcsvExtraGroups::ids())->toBe([1840]);
})->with(['text' => ['abc'], 'zero' => [0], 'negative' => [-5], 'null' => [null], 'array' => [[1]], 'decimal' => ['18.5']]);

test('a config that is not a list gives no extra groups', function () {
    config(['tcgcsv.extra_groups' => 'oops']);

    expect(TcgcsvExtraGroups::ids())->toBe([]);
});

test('a repeated id is listed once', function () {
    config(['tcgcsv.extra_groups' => [1840, 1840]]);

    expect(TcgcsvExtraGroups::ids())->toBe([1840]);
});
