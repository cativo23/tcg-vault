<?php

declare(strict_types=1);

use App\Support\Money;

test('formats minor units in the currency they were stored in', function () {
    expect(Money::format(19468, 'USD'))->toBe('$194.68')
        ->and(Money::format(16665, 'EUR'))->toBe('€166.65')
        ->and(Money::format(248190, 'USD'))->toBe('$2,481.90');
});

test('signed format carries the direction of a delta', function () {
    expect(Money::signed(200, 'USD'))->toBe('+$2.00')
        ->and(Money::signed(-15, 'USD'))->toBe('-$0.15')
        ->and(Money::signed(0, 'EUR'))->toBe('€0.00');
});

test('a move carries a true minus sign, and a plus-minus when nothing changed', function () {
    expect(Money::move(240, 'USD'))->toBe('+$2.40')
        ->and(Money::move(-100, 'USD'))->toBe("\u{2212}\$1.00")
        ->and(Money::move(0, 'EUR'))->toBe("\u{00B1}€0.00");
});
