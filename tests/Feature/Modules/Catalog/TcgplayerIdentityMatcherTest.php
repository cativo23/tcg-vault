<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\Card;
use App\Modules\Catalog\Models\CardTcgplayerLink;
use App\Modules\Catalog\Models\Set;
use App\Modules\Catalog\Tcgcsv\TcgcsvPriceRow;
use App\Modules\Catalog\Tcgcsv\TcgplayerIdentityMatcher;
use Illuminate\Support\Facades\DB;

/**
 * @param  list<int>  $groups  the TCGplayer groups the card's set pulls
 * @param  array<string, mixed>  $variants  tcgdex's variant flags
 * @param  array<string, mixed>  $raw
 */
function identityCard(string $tcgdexId, string $name, array $variants = ['normal' => true], array $raw = [], array $groups = [24451], string $set = 'mep', ?int $official = null): Card
{
    $s = Set::firstOrCreate(['tcgdex_id' => $set], ['name' => $set]);
    foreach ($groups as $group) {
        DB::table('set_tcgplayer_groups')->insertOrIgnore(['set_id' => $s->id, 'group_id' => $group]);
    }
    if ($official !== null) {
        $raw['set']['cardCount']['official'] = $official;
    }

    return Card::create([
        'tcgdex_id' => $tcgdexId, 'set_id' => $s->id, 'local_id' => substr($tcgdexId, strrpos($tcgdexId, '-') + 1),
        'name' => $name, 'variants' => $variants, 'raw' => $raw,
    ]);
}

/**
 * @param  array<int, array{name: string, number: ?string, subTypes: list<string>}>  $products  by product id
 * @return array{array<int, array<int, array<string, TcgcsvPriceRow>>>, array<int, array<int, array{name: string, number: ?string}>>}
 */
function identityFeed(int $group, array $products): array
{
    $prices = [];
    $identities = [];
    foreach ($products as $productId => $product) {
        $identities[$group][$productId] = ['name' => $product['name'], 'number' => $product['number']];
        foreach ($product['subTypes'] as $subType) {
            $prices[$group][$productId][$subType] = new TcgcsvPriceRow($group, $productId, $subType, 100, 50);
        }
    }

    return [$prices, $identities];
}

/** @return array<string, array{status: string, reason: ?string, productId: ?int, subType: ?string, groupId: ?int}> by variant */
function identityOutcomes(Card $card, array $feed): array
{
    $outcomes = [];
    foreach (app(TcgplayerIdentityMatcher::class)->evaluate($card, $feed[0], $feed[1]) as $o) {
        $outcomes[$o->variant] = ['status' => $o->status, 'reason' => $o->reason, 'productId' => $o->productId, 'subType' => $o->subType, 'groupId' => $o->groupId];
    }

    return $outcomes;
}

test('a base print whose product has its number and exact name would be linked', function () {
    $card = identityCard('mep-055', 'Treecko', ['holo' => true]);

    $outcomes = identityOutcomes($card, identityFeed(24451, [709969 => ['name' => 'Treecko -  055', 'number' => '055', 'subTypes' => ['Holofoil']]]));

    expect($outcomes)->toBe(['holofoil' => ['status' => 'new', 'reason' => null, 'productId' => 709969, 'subType' => 'Holofoil', 'groupId' => 24451]]);
});

test('every base print takes the subtype of the one product', function () {
    $card = identityCard('sv01-100', 'Pikachu', ['normal' => true, 'reverse' => true, 'holo' => false], groups: [22873], set: 'sv01');

    $outcomes = identityOutcomes($card, identityFeed(22873, [1 => ['name' => 'Pikachu - 100/198', 'number' => '100/198', 'subTypes' => ['Normal', 'Reverse Holofoil']]]));

    expect($outcomes['normal']['subType'])->toBe('Normal')
        ->and($outcomes['reverse-holofoil']['subType'])->toBe('Reverse Holofoil')
        ->and($outcomes)->toHaveCount(2);
});

test('a print linked to the same product and subtype agrees', function () {
    $card = identityCard('mep-055', 'Treecko', ['holo' => true]);
    CardTcgplayerLink::create(['card_id' => $card->id, 'variant' => 'holofoil', 'product_id' => 709969, 'sub_type' => 'Holofoil', 'group_id' => 24451, 'method' => 'tcgdex-price']);

    $outcomes = identityOutcomes($card, identityFeed(24451, [709969 => ['name' => 'Treecko - 055', 'number' => '055', 'subTypes' => ['Holofoil']]]));

    expect($outcomes['holofoil']['status'])->toBe('agree');
});

test('a print linked to another product differs', function () {
    $card = identityCard('mep-055', 'Treecko', ['holo' => true]);
    CardTcgplayerLink::create(['card_id' => $card->id, 'variant' => 'holofoil', 'product_id' => 111, 'sub_type' => 'Holofoil', 'group_id' => 24451, 'method' => 'admin']);

    $outcomes = identityOutcomes($card, identityFeed(24451, [709969 => ['name' => 'Treecko - 055', 'number' => '055', 'subTypes' => ['Holofoil']]]));

    expect($outcomes['holofoil']['status'])->toBe('different')->and($outcomes['holofoil']['productId'])->toBe(709969);
});

test('a name that only starts with the card name is refused', function () {
    $card = identityCard('mep-055', 'Treecko', ['holo' => true]);

    $outcomes = identityOutcomes($card, identityFeed(24451, [1 => ['name' => 'Treecko (Cosmos Holo) - 055', 'number' => '055', 'subTypes' => ['Holofoil']]]));

    expect($outcomes['holofoil'])->toMatchArray(['status' => 'refused', 'reason' => 'no-candidate']);
});

test('two products that both qualify are refused, not guessed', function () {
    $card = identityCard('mep-055', 'Treecko', ['holo' => true]);

    $outcomes = identityOutcomes($card, identityFeed(24451, [
        1 => ['name' => 'Treecko - 055', 'number' => '055', 'subTypes' => ['Holofoil']],
        2 => ['name' => 'Treecko - 055', 'number' => '055', 'subTypes' => ['Holofoil']],
    ]));

    expect($outcomes['holofoil'])->toMatchArray(['status' => 'refused', 'reason' => 'ambiguous']);
});

test('a printed total that is not the set count is refused', function () {
    $card = identityCard('sv01-100', 'Pikachu', ['normal' => true], groups: [22873], set: 'sv01', official: 198);

    $outcomes = identityOutcomes($card, identityFeed(22873, [1 => ['name' => 'Pikachu - 100/091', 'number' => '100/091', 'subTypes' => ['Normal']]]));

    expect($outcomes['normal'])->toMatchArray(['status' => 'refused', 'reason' => 'no-candidate']);
});

test('a product whose number is another card\'s is refused', function () {
    $card = identityCard('mep-055', 'Treecko', ['holo' => true]);

    $outcomes = identityOutcomes($card, identityFeed(24451, [1 => ['name' => 'Treecko - 056', 'number' => '056', 'subTypes' => ['Holofoil']]]));

    expect($outcomes['holofoil']['status'])->toBe('refused');
});

test('a product in a group the set does not map is never considered', function () {
    $card = identityCard('mep-055', 'Treecko', ['holo' => true]);
    [$prices, $identities] = identityFeed(1840, [1 => ['name' => 'Treecko - 055', 'number' => '055', 'subTypes' => ['Holofoil']]]);

    // The set's own group was fetched and has no matching product.
    $outcomes = identityOutcomes($card, [$prices + [24451 => []], $identities + [24451 => []]]);

    expect($outcomes['holofoil'])->toMatchArray(['status' => 'refused', 'reason' => 'no-candidate']);
});

test('a product without the print\'s subtype is refused', function () {
    $card = identityCard('mep-055', 'Treecko', ['holo' => true]);

    $outcomes = identityOutcomes($card, identityFeed(24451, [1 => ['name' => 'Treecko - 055', 'number' => '055', 'subTypes' => ['Normal']]]));

    expect($outcomes['holofoil'])->toMatchArray(['status' => 'refused', 'reason' => 'no-subtype']);
});

test('a card with no standard size print is refused', function () {
    $card = identityCard('mep-Museum', 'Pikachu', ['holo' => true], ['variants_detailed' => [['type' => 'holo', 'size' => 'jumbo']]]);

    $outcomes = identityOutcomes($card, identityFeed(24451, [1 => ['name' => 'Pikachu - Museum', 'number' => 'Museum', 'subTypes' => ['Holofoil']]]));

    expect($outcomes['holofoil'])->toMatchArray(['status' => 'refused', 'reason' => 'not-standard-size']);
});

test('special prints are not evaluated', function () {
    $card = identityCard('me02.5-183', 'Boss\'s Orders', ['normal' => true, 'reverse' => true], [
        'variants_detailed' => [
            ['type' => 'normal', 'size' => 'standard', 'thirdParty' => ['cardmarket' => 1, 'tcgplayer' => 10]],
            ['type' => 'normal', 'size' => 'standard', 'stamp' => ['player-rewards-program'], 'thirdParty' => ['cardmarket' => 2, 'tcgplayer' => 11]],
        ],
    ]);

    $outcomes = identityOutcomes($card, identityFeed(24451, [10 => ['name' => 'Boss\'s Orders - 183', 'number' => '183', 'subTypes' => ['Normal']]]));

    expect(array_keys($outcomes))->not->toContain('normal+player-rewards-program');
});

test('a lone stamped print takes the product\'s only subtype', function () {
    $card = identityCard('30th-047', 'Pikachu', ['holo' => true], [
        'pricing' => ['cardmarket' => ['idProduct' => 5]],
        'variants_detailed' => [['type' => 'holo', 'size' => 'standard', 'stamp' => ['30th-anniversary'], 'thirdParty' => ['cardmarket' => 5]]],
    ], groups: [24722], set: '30th');

    $outcomes = identityOutcomes($card, identityFeed(24722, [696682 => ['name' => 'Pikachu - 047', 'number' => '047', 'subTypes' => ['Special']]]));

    expect($outcomes['holofoil'])->toMatchArray(['status' => 'new', 'productId' => 696682, 'subType' => 'Special']);
});

test('a lone stamped print is refused when another priced product shares its number', function () {
    $card = identityCard('30th-047', 'Pikachu', ['holo' => true], [
        'pricing' => ['cardmarket' => ['idProduct' => 5]],
        'variants_detailed' => [['type' => 'holo', 'size' => 'standard', 'stamp' => ['30th-anniversary'], 'thirdParty' => ['cardmarket' => 5]]],
    ], groups: [24722], set: '30th');

    $outcomes = identityOutcomes($card, identityFeed(24722, [
        696682 => ['name' => 'Pikachu - 047', 'number' => '047', 'subTypes' => ['Special']],
        696683 => ['name' => 'Pikachu (Stamped) - 047', 'number' => '047', 'subTypes' => ['Holofoil']],
    ]));

    expect($outcomes['holofoil'])->toMatchArray(['status' => 'refused', 'reason' => 'number-not-unique']);
});

test('a group whose products could not be read refuses the whole card', function () {
    $card = identityCard('mep-055', 'Treecko', ['holo' => true]);
    [$prices] = identityFeed(24451, [1 => ['name' => 'Treecko - 055', 'number' => '055', 'subTypes' => ['Holofoil']]]);

    $outcomes = identityOutcomes($card, [$prices, []]);

    expect($outcomes['holofoil'])->toMatchArray(['status' => 'refused', 'reason' => 'group-without-identities']);
});

test('accents and punctuation in the product name do not matter', function () {
    $card = identityCard('mep-056', 'Flabébé', ['normal' => true]);

    $outcomes = identityOutcomes($card, identityFeed(24451, [1 => ['name' => 'Flabebe - 056', 'number' => '056', 'subTypes' => ['Normal']]]));

    expect($outcomes['normal']['status'])->toBe('new');
});

test('the evidence records how the match was made', function () {
    $card = identityCard('mep-055', 'Treecko', ['holo' => true]);
    [$prices, $identities] = identityFeed(24451, [709969 => ['name' => 'Treecko - 055/091', 'number' => '055/091', 'subTypes' => ['Holofoil']]]);

    $outcome = app(TcgplayerIdentityMatcher::class)->evaluate($card, $prices, $identities)[0];

    expect($outcome->evidence)->toMatchArray(['rule' => 'same-print', 'number' => '055/091', 'candidates' => 1]);
});

test('an unpriced product with the same name and number still makes the match ambiguous', function () {
    $card = identityCard('mep-055', 'Treecko', ['holo' => true]);
    [$prices, $identities] = identityFeed(24451, [
        1 => ['name' => 'Treecko - 055', 'number' => '055', 'subTypes' => ['Holofoil']],
        2 => ['name' => 'Treecko - 055', 'number' => '055', 'subTypes' => []],
    ]);

    $outcomes = identityOutcomes($card, [$prices, $identities]);

    expect($outcomes['holofoil'])->toMatchArray(['status' => 'refused', 'reason' => 'ambiguous']);
});

test('an unpriced product sharing the number refuses a lone stamped print', function () {
    $card = identityCard('30th-047', 'Pikachu', ['holo' => true], [
        'pricing' => ['cardmarket' => ['idProduct' => 5]],
        'variants_detailed' => [['type' => 'holo', 'size' => 'standard', 'stamp' => ['30th-anniversary'], 'thirdParty' => ['cardmarket' => 5]]],
    ], groups: [24722], set: '30th');
    [$prices, $identities] = identityFeed(24722, [
        696682 => ['name' => 'Pikachu - 047', 'number' => '047', 'subTypes' => ['Special']],
        696683 => ['name' => 'Pikachu (Stamped) - 047', 'number' => '047', 'subTypes' => []],
    ]);

    expect(identityOutcomes($card, [$prices, $identities])['holofoil'])->toMatchArray(['status' => 'refused', 'reason' => 'number-not-unique']);
});

test('a product with the card\'s name and no readable number counts as a possible second match', function () {
    $card = identityCard('mep-055', 'Treecko', ['holo' => true]);
    [$prices, $identities] = identityFeed(24451, [
        1 => ['name' => 'Treecko - 055', 'number' => '055', 'subTypes' => ['Holofoil']],
        2 => ['name' => 'Treecko', 'number' => null, 'subTypes' => ['Holofoil']],
    ]);

    expect(identityOutcomes($card, [$prices, $identities])['holofoil'])->toMatchArray(['status' => 'refused', 'reason' => 'ambiguous']);
});

test('the only matching product having no price is refused as unpriced', function () {
    $card = identityCard('mep-055', 'Treecko', ['holo' => true]);
    [$prices, $identities] = identityFeed(24451, [1 => ['name' => 'Treecko - 055', 'number' => '055', 'subTypes' => []]]);

    expect(identityOutcomes($card, [$prices, $identities])['holofoil'])->toMatchArray(['status' => 'refused', 'reason' => 'no-price']);
});

test('a group with products and no price rows is read, not treated as unreadable', function () {
    $card = identityCard('mep-055', 'Treecko', ['holo' => true]);
    [, $identities] = identityFeed(24451, [1 => ['name' => 'Treecko - 055', 'number' => '055', 'subTypes' => []]]);

    expect(identityOutcomes($card, [[], $identities])['holofoil']['reason'])->toBe('no-price');
});

test('a product name with an unpadded number matches its padded printed number', function () {
    $card = identityCard('sv01-058', 'Pikachu', ['normal' => true], groups: [22873], set: 'sv01', official: 102);

    $outcomes = identityOutcomes($card, identityFeed(22873, [1 => ['name' => 'Pikachu - 58/102', 'number' => '058/102', 'subTypes' => ['Normal']]]));

    expect($outcomes['normal']['status'])->toBe('new');
});

test('a lettered printed total does not fail the set count check', function () {
    $card = identityCard('swsh9-TG01', 'Pikachu', ['normal' => true], groups: [1], set: 'swsh9', official: 186);

    $outcomes = identityOutcomes($card, identityFeed(1, [1 => ['name' => 'Pikachu - TG01/TG30', 'number' => 'TG01/TG30', 'subTypes' => ['Normal']]]));

    expect($outcomes['normal']['status'])->toBe('new');
});

test('a lone product with no readable number is never linked, even with the card\'s exact name', function () {
    $card = identityCard('mep-055', 'Treecko', ['holo' => true]);

    $outcomes = identityOutcomes($card, identityFeed(24451, [1 => ['name' => 'Treecko', 'number' => null, 'subTypes' => ['Holofoil']]]));

    expect($outcomes['holofoil'])->toMatchArray(['status' => 'refused', 'reason' => 'no-candidate']);
});

test('a name that gives only the number still matches a product whose number has a total', function () {
    $card = identityCard('sv01-100', 'Pikachu', ['normal' => true], groups: [22873], set: 'sv01');

    $outcomes = identityOutcomes($card, identityFeed(22873, [1 => ['name' => 'Pikachu - 100', 'number' => '100/198', 'subTypes' => ['Normal']]]));

    expect($outcomes['normal']['status'])->toBe('new');
});
