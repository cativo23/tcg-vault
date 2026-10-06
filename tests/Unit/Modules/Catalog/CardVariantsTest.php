<?php

declare(strict_types=1);

use App\Modules\Catalog\Support\CardVariants;

test('maps tcgdex\'s own variant flags to this app\'s variant vocabulary', function () {
    // Antique Jaw Fossil (Perfect Order): normal + reverse-holofoil print,
    // no straight holo. A card like this must map to its real variants
    // even though the cardmarket importer mislabels its synced price row
    // as 'holofoil'.
    expect(CardVariants::available([
        'holo' => false,
        'normal' => true,
        'wPromo' => false,
        'reverse' => true,
        'firstEdition' => false,
    ]))->toBe(['normal', 'reverse-holofoil']);
});

test('a straight-holo-only card maps to just holofoil', function () {
    expect(CardVariants::available([
        'holo' => true,
        'normal' => false,
        'reverse' => false,
    ]))->toBe(['holofoil']);
});

test('a card with all three print types maps to all three, in a stable order', function () {
    expect(CardVariants::available([
        'reverse' => true,
        'holo' => true,
        'normal' => true,
    ]))->toBe(['normal', 'holofoil', 'reverse-holofoil']);
});

test('an empty or missing variants map yields no known variants', function () {
    expect(CardVariants::available([]))->toBe([]);
    expect(CardVariants::available(['wPromo' => true, 'firstEdition' => true]))->toBe([]);
});

test('a special foil from variants_detailed becomes its own variant after the base ones', function () {
    // Budew (Prismatic Evolutions): normal + reverse, plus Poké Ball and
    // Master Ball pattern reverses that are separate products with their
    // own prices.
    expect(CardVariants::available(
        ['normal' => true, 'reverse' => true, 'holo' => false],
        [
            ['type' => 'normal', 'size' => 'standard'],
            ['type' => 'reverse', 'size' => 'standard'],
            ['type' => 'reverse', 'size' => 'standard', 'foil' => 'pokeball'],
            ['type' => 'reverse', 'size' => 'standard', 'foil' => 'masterball'],
        ],
    ))->toBe(['normal', 'reverse-holofoil', 'reverse-holofoil:pokeball', 'reverse-holofoil:masterball']);
});

test('stamps are appended to the key, sorted so the same print always gets the same key', function () {
    expect(CardVariants::keyFor(['type' => 'normal', 'stamp' => ['staff', 'set-logo']]))
        ->toBe('normal+set-logo+staff');
    expect(CardVariants::keyFor(['type' => 'holo', 'foil' => 'cosmos', 'stamp' => ['pre-release']]))
        ->toBe('holofoil:cosmos+pre-release');
});

test('a detailed entry with nothing beyond its base type adds no new variant', function () {
    expect(CardVariants::keyFor(['type' => 'reverse', 'size' => 'standard']))->toBeNull();
});

test('non-standard sizes and unknown print types are not offered', function () {
    expect(CardVariants::keyFor(['type' => 'holo', 'size' => 'jumbo', 'foil' => 'cosmos']))->toBeNull();
    expect(CardVariants::keyFor(['type' => 'metal', 'foil' => 'gold']))->toBeNull();
});

test('a detailed entry with values outside the key alphabet is dropped, not stored', function () {
    expect(CardVariants::keyFor(['type' => 'reverse', 'foil' => 'Poke Ball!']))->toBeNull();
    expect(CardVariants::keyFor(['type' => 'normal', 'stamp' => ['ok', '../x']]))->toBeNull();
    expect(CardVariants::keyFor(['type' => 'normal', 'stamp' => 'staff']))->toBeNull();
});

test('isValid accepts the base and special key shapes and nothing else', function (string $key, bool $valid) {
    expect(CardVariants::isValid($key))->toBe($valid);
})->with([
    ['normal', true],
    ['reverse-holofoil', true],
    ['reverse-holofoil:pokeball', true],
    ['holofoil:cosmos+player-rewards-program', true],
    ['normal+staff', true],
    ['default', false],
    ['reverse-holofoil:', false],
    ['holofoil:cosmos+', false],
    ['normal:<script>', false],
    ['NORMAL', false],
]);

test('isValid rejects a key longer than the column-safe ceiling', function () {
    expect(CardVariants::isValid('normal+'.str_repeat('a', 200)))->toBeFalse();
});

test('label reads like a collector would say it', function (string $key, string $label) {
    expect(CardVariants::label($key))->toBe($label);
})->with([
    ['normal', 'Normal'],
    ['reverse-holofoil', 'Reverse Holofoil'],
    ['reverse-holofoil:pokeball', 'Reverse Holofoil · Poké Ball'],
    ['reverse-holofoil:masterball', 'Reverse Holofoil · Master Ball'],
    ['holofoil:cosmos+player-rewards-program', 'Holofoil · Cosmos · Player Rewards Program'],
    ['default', 'Default'],
]);
