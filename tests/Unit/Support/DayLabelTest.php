<?php

declare(strict_types=1);

use App\Support\DayLabel;

test('a day in the current year is the day and the month alone', function () {
    expect(DayLabel::of(today()->startOfYear()))->toBe('1 Jan');
});

test('a day in an earlier year carries its year', function () {
    expect(DayLabel::of(today()->subYear()->startOfYear()))->toBe('1 Jan '.(today()->year - 1));
});

test('the last day of last year already carries its year', function () {
    expect(DayLabel::of(today()->startOfYear()->subDay()))->toBe('31 Dec '.(today()->year - 1));
});
