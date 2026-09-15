<?php

declare(strict_types=1);

use App\Modules\Catalog\Support\Rarity;

test('abbreviates the rarities tcgdex actually emits', function (string $raw, string $expected) {
    expect(Rarity::abbreviate($raw))->toBe($expected);
})->with([
    ['Common', 'C'],
    ['Uncommon', 'U'],
    ['Rare', 'R'],
    ['Rare Holo', 'RH'],
    ['Double rare', 'RR'],
    ['Ultra Rare', 'UR'],
    ['Illustration rare', 'IR'],
    ['Special illustration rare', 'SIR'],
    ['Hyper rare', 'HR'],
    ['Mega hyper rare', 'MHR'],
    ['ACE SPEC Rare', 'ACE'],
    ['Promo', 'PR'],
]);

test('falls back to initials for an unknown rarity instead of failing', function () {
    expect(Rarity::abbreviate('Radiant Rare'))->toBe('RR');
    expect(Rarity::abbreviate('Amazing Rare'))->toBe('AR');
});

test('a missing rarity yields an empty abbreviation', function () {
    expect(Rarity::abbreviate(null))->toBe('');
});
