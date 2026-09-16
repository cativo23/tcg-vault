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

test('a missing rarity yields an empty abbreviation, and so does tcgdex\'s literal "None"', function () {
    expect(Rarity::abbreviate(null))->toBe('')
        ->and(Rarity::abbreviate('None'))->toBe('')
        ->and(Rarity::label('None'))->toBe('');
});

test('tiers the rarities tcgdex actually emits into standard/silver/chase', function (string $raw, string $expected) {
    expect(Rarity::tier($raw))->toBe($expected);
})->with([
    // Standard: the ordinary pulls, no accent — same look as today.
    ['Common', 'standard'],
    ['Uncommon', 'standard'],
    ['Rare', 'standard'],
    // Silver: still a real pull, but not the chase.
    ['Rare Holo', 'silver'],
    ['Holo Rare', 'silver'],
    ['Double Rare', 'silver'],
    ['Ultra Rare', 'silver'],
    ['Promo', 'silver'],
    ['Trainer Gallery Rare Holo', 'silver'],
    // Chase: the pulls a collector actually hunts for.
    ['Illustration Rare', 'chase'],
    ['Special Illustration Rare', 'chase'],
    ['Hyper Rare', 'chase'],
    ['Mega Hyper Rare', 'chase'],
    ['ACE SPEC Rare', 'chase'],
    ['Shiny Rare', 'chase'],
    ['Shiny Ultra Rare', 'chase'],
    ['Secret Rare', 'chase'],
]);

test('an unrecognized rarity tiers as standard rather than guessing at chase-ness', function () {
    // tier() is a visual accent, not a value judgment — an unknown
    // string (a new set's rarity wording tcgdex added before this
    // list was updated) must never be silently promoted to "chase" on
    // a guess. abbreviate() already has its own initials fallback for
    // display; tier() intentionally does not mirror that here.
    expect(Rarity::tier('Radiant Rare'))->toBe('standard');
});

test('a missing rarity tiers as standard, and so does tcgdex\'s literal "None"', function () {
    expect(Rarity::tier(null))->toBe('standard')
        ->and(Rarity::tier('None'))->toBe('standard');
});
