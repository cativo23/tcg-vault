<?php

declare(strict_types=1);

use App\Support\AgeScreen;
use Carbon\CarbonImmutable;

test('age counts a birthday as passed only once its month is over', function (int $month, int $year, int $expected) {
    $today = CarbonImmutable::parse('2026-09-25');

    expect(AgeScreen::ageOn($month, $year, $today))->toBe($expected);
})->with([
    'birthday month already over' => [8, 2013, 13],
    'born this month: not counted yet' => [9, 2013, 12],
    'birthday later this year' => [12, 2013, 12],
    'adult' => [1, 1990, 36],
]);

test('under 13 is refused and 13 to 17 needs a guardian', function () {
    $today = CarbonImmutable::parse('2026-09-25');

    expect(AgeScreen::isUnder13(9, 2013, $today))->toBeTrue()
        ->and(AgeScreen::isUnder13(8, 2013, $today))->toBeFalse()
        ->and(AgeScreen::needsGuardian(8, 2013, $today))->toBeTrue()
        ->and(AgeScreen::needsGuardian(8, 2008, $today))->toBeFalse();
});
