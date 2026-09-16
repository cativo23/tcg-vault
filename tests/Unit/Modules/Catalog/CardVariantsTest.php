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
