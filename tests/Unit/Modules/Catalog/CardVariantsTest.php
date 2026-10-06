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
        ['variants_detailed' => [
            ['type' => 'normal', 'size' => 'standard'],
            ['type' => 'reverse', 'size' => 'standard'],
            ['type' => 'reverse', 'size' => 'standard', 'foil' => 'pokeball'],
            ['type' => 'reverse', 'size' => 'standard', 'foil' => 'masterball'],
        ]],
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

test('a special entry that is the same product as the card\'s top-level pricing is just the base print', function () {
    // Terapagos ex (sv08.5-180), a gold Hyper rare: tcgdex's only entry is
    // {holo, foil: gold}, the same tcgplayer product as the card itself.
    expect(CardVariants::available(['holo' => true], [
        'pricing' => ['tcgplayer' => ['unit' => 'USD', 'holofoil' => ['productId' => 610535, 'marketPrice' => 40.0]]],
        'variants_detailed' => [
            ['type' => 'holo', 'size' => 'standard', 'foil' => 'gold', 'thirdParty' => ['tcgplayer' => 610535]],
        ],
    ]))->toBe(['holofoil']);
});

test('the only entry of a type with no plain sibling is the base print when nothing says otherwise', function () {
    // A promo whose only print carries the set logo, with no product ids
    // anywhere to tell it apart from the card itself.
    expect(CardVariants::available(['normal' => true], [
        'variants_detailed' => [['type' => 'normal', 'size' => 'standard', 'stamp' => ['set-logo']]],
    ]))->toBe(['normal']);
});

test('several special entries of a type with no plain sibling stay separate prints', function () {
    // me02.5-016 Budew: no plain reverse, but Friend Ball and Energy reverses.
    expect(CardVariants::available(['normal' => true, 'reverse' => true], [
        'variants_detailed' => [
            ['type' => 'normal', 'size' => 'standard'],
            ['type' => 'reverse', 'size' => 'standard', 'foil' => 'friendball', 'thirdParty' => ['tcgplayer' => 1]],
            ['type' => 'reverse', 'size' => 'standard', 'foil' => 'energy', 'thirdParty' => ['tcgplayer' => 2]],
        ],
    ]))->toBe(['normal', 'reverse-holofoil:friendball', 'reverse-holofoil:energy']);
});

test('isSpecial tells a special print key from a base one', function () {
    expect(CardVariants::isSpecial('reverse-holofoil:pokeball'))->toBeTrue();
    expect(CardVariants::isSpecial('normal+staff'))->toBeTrue();
    expect(CardVariants::isSpecial('reverse-holofoil'))->toBeFalse();
    expect(CardVariants::isSpecial('default'))->toBeFalse();
});

test('compose builds a key for a print tcgdex does not list, from its own vocabulary', function () {
    // A Prize Pack cosmos holo: tcgdex's stamp for the Play! Pokémon Prize Pack.
    expect(CardVariants::compose('holofoil', 'cosmos', ['player-rewards-program']))
        ->toBe('holofoil:cosmos+player-rewards-program');
    expect(CardVariants::compose('normal', null, ['staff', 'pre-release']))->toBe('normal+pre-release+staff');
    expect(CardVariants::compose('reverse-holofoil', 'pokeball', []))->toBe('reverse-holofoil:pokeball');
});

test('compose refuses anything outside tcgdex\'s vocabulary, or a key with nothing special', function () {
    expect(CardVariants::compose('holofoil', 'sparkly', []))->toBeNull();
    expect(CardVariants::compose('holofoil', null, ['my-own-stamp']))->toBeNull();
    expect(CardVariants::compose('default', 'cosmos', []))->toBeNull();
    expect(CardVariants::compose('normal', null, []))->toBeNull();
});

test('the foil and stamp options are tcgdex\'s, labelled', function () {
    expect(CardVariants::foilOptions())->toHaveKey('cosmos', 'Cosmos');
    expect(CardVariants::foilOptions())->toHaveKey('masterball', 'Master Ball');
    expect(CardVariants::stampOptions())->toHaveKey('player-rewards-program', 'Player Rewards Program');
});

test('a base print tcgdex lists only as special prints is not offered on its own', function () {
    // N's Zorua (me02.5-136, Ascended Heroes): the set has no plain
    // reverse — every reverse is a Poké Ball or an Energy pattern — even
    // though tcgdex's `reverse` flag is true.
    expect(CardVariants::available(['normal' => true, 'reverse' => true], [
        'variants_detailed' => [
            ['type' => 'normal', 'size' => 'standard', 'thirdParty' => ['tcgplayer' => 675948]],
            ['type' => 'reverse', 'size' => 'standard', 'foil' => 'pokeball', 'thirdParty' => ['tcgplayer' => 676960]],
            ['type' => 'reverse', 'size' => 'standard', 'foil' => 'energy', 'thirdParty' => ['tcgplayer' => 677100]],
        ],
    ]))->toBe(['normal', 'reverse-holofoil:pokeball', 'reverse-holofoil:energy']);
});

test('a card with no variants_detailed keeps every flagged base print', function () {
    expect(CardVariants::available(['normal' => true, 'reverse' => true], []))->toBe(['normal', 'reverse-holofoil']);
});

test('a base print tcgdex files with a stamp still counts as present beside another special print', function () {
    // Quaquaval (svp-005): the regular promo is {holo, set-logo} — the
    // card's own cardmarket product — and the staff promo is separate.
    expect(CardVariants::available(['holo' => true], [
        'pricing' => ['cardmarket' => ['unit' => 'EUR', 'idProduct' => 692227, 'avg' => 0.5]],
        'variants_detailed' => [
            ['type' => 'holo', 'size' => 'standard', 'stamp' => ['set-logo'], 'thirdParty' => ['cardmarket' => 692227, 'tcgplayer' => 487751]],
            ['type' => 'holo', 'size' => 'standard', 'stamp' => ['set-logo', 'staff'], 'thirdParty' => ['cardmarket' => 761119, 'tcgplayer' => 522650]],
        ],
    ]))->toBe(['holofoil', 'holofoil+set-logo+staff']);
});

test('a lone special print that is a different product from the card\'s own is a print of its own', function () {
    // me02.5-183 Boss's Orders, as tcgdex serves it: flagged holo only
    // because of the Prize Pack cosmos, whose product (704399) is not the
    // card's own (675995 / cardmarket 869794).
    expect(CardVariants::available(['normal' => true, 'reverse' => true, 'holo' => true], [
        'pricing' => [
            'cardmarket' => ['unit' => 'EUR', 'idProduct' => 869794, 'avg' => 0.25],
            'tcgplayer' => ['unit' => 'USD', 'normal' => ['productId' => 675995, 'marketPrice' => 0.24], 'reverse-holofoil' => ['productId' => 675995, 'marketPrice' => 0.57]],
        ],
        'variants_detailed' => [
            ['type' => 'normal', 'size' => 'standard', 'thirdParty' => ['cardmarket' => 869794, 'tcgplayer' => 675995]],
            ['type' => 'reverse', 'size' => 'standard', 'thirdParty' => ['cardmarket' => 869794, 'tcgplayer' => 675995]],
            ['type' => 'normal', 'size' => 'standard', 'stamp' => ['player-rewards-program'], 'thirdParty' => ['cardmarket' => 894199, 'tcgplayer' => 704398]],
            ['type' => 'holo', 'size' => 'standard', 'foil' => 'cosmos', 'stamp' => ['player-rewards-program'], 'thirdParty' => ['cardmarket' => 894200, 'tcgplayer' => 704399]],
        ],
    ]))->toBe(['normal', 'reverse-holofoil', 'normal+player-rewards-program', 'holofoil:cosmos+player-rewards-program']);
});

test('a lone special print with no ids of its own is separate when the card\'s base prints carry the card\'s own ids', function () {
    // me01-075 Solrock: plain normal and reverse are the card's product;
    // the Prize Pack cosmos entry has no ids yet.
    expect(CardVariants::available(['normal' => true, 'reverse' => true, 'holo' => true], [
        'pricing' => ['tcgplayer' => ['unit' => 'USD', 'normal' => ['productId' => 640001, 'marketPrice' => 0.1]]],
        'variants_detailed' => [
            ['type' => 'normal', 'size' => 'standard', 'thirdParty' => ['tcgplayer' => 640001]],
            ['type' => 'reverse', 'size' => 'standard', 'thirdParty' => ['tcgplayer' => 640001]],
            ['type' => 'holo', 'size' => 'standard', 'foil' => 'cosmos', 'stamp' => ['player-rewards-program']],
        ],
    ]))->toBe(['normal', 'reverse-holofoil', 'holofoil:cosmos+player-rewards-program']);
});

test('product ids are only compared within one marketplace', function () {
    // A gold Hyper rare whose entry carries only a cardmarket id while the
    // card's own prices list only tcgplayer ids: nothing says it differs.
    expect(CardVariants::available(['holo' => true], [
        'pricing' => ['tcgplayer' => ['unit' => 'USD', 'holofoil' => ['productId' => 610535, 'marketPrice' => 40.0]]],
        'variants_detailed' => [
            ['type' => 'holo', 'size' => 'standard', 'foil' => 'gold', 'thirdParty' => ['cardmarket' => 812345]],
        ],
    ]))->toBe(['holofoil']);
});
