<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\Card;
use App\Modules\Catalog\Models\CardPriceSnapshot;
use App\Modules\Catalog\Models\CardTcgplayerLink;
use App\Modules\Catalog\Models\Set;
use App\Modules\Catalog\Tcgcsv\TcgcsvPriceRow;
use App\Modules\Catalog\Tcgcsv\TcgplayerLinkDiscovery;
use Illuminate\Support\Facades\DB;

/** @param  list<int>  $groups  the TCGplayer groups the card's set pulls */
function linkCard(string $tcgdexId, array $raw = [], string $set = 'me05', array $groups = [24688], ?string $name = null): Card
{
    $s = Set::firstOrCreate(['tcgdex_id' => $set], ['name' => $set]);
    foreach ($groups as $group) {
        DB::table('set_tcgplayer_groups')->insertOrIgnore(['set_id' => $s->id, 'group_id' => $group]);
    }

    return Card::create(['tcgdex_id' => $tcgdexId, 'set_id' => $s->id, 'local_id' => explode('-', $tcgdexId)[1], 'name' => $name ?? $tcgdexId, 'raw' => $raw]);
}

/** @return array<int, array<int, array<string, TcgcsvPriceRow>>> groupId → productId → subType → row */
function priceIndex(int $groupId, array $products): array
{
    $index = [];
    foreach ($products as $productId => $subTypes) {
        foreach ($subTypes as $subType) {
            $index[$groupId][$productId][$subType] = new TcgcsvPriceRow($groupId, $productId, $subType, 100, 50);
        }
    }

    return $index;
}

function linksOf(Card $card): array
{
    return CardTcgplayerLink::where('card_id', $card->id)->orderBy('variant')->get()
        ->map(fn ($l) => [$l->variant, $l->product_id, $l->sub_type, $l->method, $l->group_id])->all();
}

test('a print tcgdex already prices on TCGplayer links to the product it priced', function () {
    $card = linkCard('me05-116');
    CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'tcgplayer', 'variant' => 'holofoil', 'captured_on' => today(), 'currency' => 'USD', 'market_minor' => 16027, 'raw' => ['productId' => 704873, 'marketPrice' => 160.27]]);

    app(TcgplayerLinkDiscovery::class)->discover($card, priceIndex(24688, [704873 => ['Holofoil']]));

    expect(linksOf($card))->toBe([['holofoil', 704873, 'Holofoil', 'tcgdex-price', 24688]]);
});

test('a print tcgdex lists but doesn\'t price links through its product id, a lone stamped print as the base', function () {
    // 30th-047 Pikachu: tcgdex's only entry is {holo, stamp 30th-anniversary}.
    $card = linkCard('30th-047', ['variants_detailed' => [
        ['type' => 'holo', 'size' => 'standard', 'stamp' => ['30th-anniversary'], 'thirdParty' => ['tcgplayer' => 696682]],
    ]], '30th', [24722]);

    app(TcgplayerLinkDiscovery::class)->discover($card, priceIndex(24722, [696682 => ['Holofoil']]));

    expect(linksOf($card))->toBe([['holofoil', 696682, 'Holofoil', 'tcgdex-thirdparty', 24722]]);
});

test('base and special prints of one card link to their own products and subtypes', function () {
    // me02.5-183 Boss's Orders: one product for normal + reverse, separate Prize Pack products.
    $card = linkCard('me02.5-183', ['variants' => ['normal' => true, 'reverse' => true, 'holo' => true], 'pricing' => [
        'cardmarket' => ['unit' => 'EUR', 'idProduct' => 869794, 'avg' => 0.25],
        'tcgplayer' => ['unit' => 'USD', 'normal' => ['productId' => 675995, 'marketPrice' => 0.24]],
    ], 'variants_detailed' => [
        ['type' => 'normal', 'size' => 'standard', 'thirdParty' => ['cardmarket' => 869794, 'tcgplayer' => 675995]],
        ['type' => 'reverse', 'size' => 'standard', 'thirdParty' => ['cardmarket' => 869794, 'tcgplayer' => 675995]],
        ['type' => 'normal', 'size' => 'standard', 'stamp' => ['player-rewards-program'], 'thirdParty' => ['cardmarket' => 894199, 'tcgplayer' => 704398]],
        ['type' => 'holo', 'size' => 'standard', 'foil' => 'cosmos', 'stamp' => ['player-rewards-program'], 'thirdParty' => ['cardmarket' => 894200, 'tcgplayer' => 704399]],
    ]], 'me02.5', [24600, 22880]);

    app(TcgplayerLinkDiscovery::class)->discover($card, priceIndex(24600, [675995 => ['Normal', 'Reverse Holofoil']]) + priceIndex(22880, [704398 => ['Normal'], 704399 => ['Holofoil']]));

    expect(linksOf($card))->toBe([
        // Its lone holo entry is the Prize Pack cosmos — a product of its
        // own, so it links under its own key and no plain holo exists.
        ['holofoil:cosmos+player-rewards-program', 704399, 'Holofoil', 'tcgdex-thirdparty', 22880],
        ['normal', 675995, 'Normal', 'tcgdex-thirdparty', 24600],
        ['normal+player-rewards-program', 704398, 'Normal', 'tcgdex-thirdparty', 22880],
        ['reverse-holofoil', 675995, 'Reverse Holofoil', 'tcgdex-thirdparty', 24600],
    ]);
});

test('a print whose product is not in any fetched group, or that tcgdex does not list, gets no link', function () {
    $card = linkCard('sv06.5-061', ['variants_detailed' => [
        ['type' => 'normal', 'size' => 'standard', 'thirdParty' => ['tcgplayer' => 999]],
    ]], 'sv06.5', [1]);

    app(TcgplayerLinkDiscovery::class)->discover($card, priceIndex(1, [123 => ['Normal']]));

    expect(linksOf($card))->toBe([]);
});

test('a link is replaced only by a method at least as trusted', function () {
    $card = linkCard('me05-116', ['variants_detailed' => [
        ['type' => 'holo', 'size' => 'standard', 'thirdParty' => ['tcgplayer' => 111]],
    ]]);
    CardTcgplayerLink::create(['card_id' => $card->id, 'variant' => 'holofoil', 'product_id' => 704873, 'sub_type' => 'Holofoil', 'method' => 'admin']);

    app(TcgplayerLinkDiscovery::class)->discover($card, priceIndex(24688, [111 => ['Holofoil']]));
    expect(linksOf($card)[0][1])->toBe(704873);

    CardTcgplayerLink::where('card_id', $card->id)->update(['method' => 'tcgdex-thirdparty']);
    CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'tcgplayer', 'variant' => 'holofoil', 'captured_on' => today(), 'currency' => 'USD', 'market_minor' => 1, 'raw' => ['productId' => 111]]);
    app(TcgplayerLinkDiscovery::class)->discover($card, priceIndex(24688, [111 => ['Holofoil']]));
    expect(linksOf($card))->toBe([['holofoil', 111, 'Holofoil', 'tcgdex-price', 24688]]);
});

test('a product in a group the card\'s set doesn\'t pull is never linked', function () {
    $card = linkCard('me05-117', ['variants_detailed' => [
        ['type' => 'holo', 'size' => 'standard', 'thirdParty' => ['tcgplayer' => 555]],
    ]]);

    app(TcgplayerLinkDiscovery::class)->discover($card, priceIndex(99999, [555 => ['Holofoil']]));

    expect(linksOf($card))->toBe([]);
});

test('a base print never links to a subtype that isn\'t its own, and a special print takes its product\'s only one', function () {
    // An old-set product with only a "1st Edition Holofoil" row, and a
    // Poké Ball pattern reverse priced under "Holofoil".
    $card = linkCard('me05-118', ['variants' => ['holo' => true, 'reverse' => true], 'variants_detailed' => [
        ['type' => 'holo', 'size' => 'standard', 'thirdParty' => ['tcgplayer' => 600]],
        ['type' => 'reverse', 'size' => 'standard', 'thirdParty' => ['tcgplayer' => 601]],
        ['type' => 'reverse', 'size' => 'standard', 'foil' => 'pokeball', 'thirdParty' => ['tcgplayer' => 602]],
    ]]);

    $skipped = app(TcgplayerLinkDiscovery::class)->discover($card, priceIndex(24688, [600 => ['1st Edition Holofoil'], 601 => ['Reverse Holofoil'], 602 => ['Holofoil']]));

    expect(linksOf($card))->toBe([
        ['reverse-holofoil', 601, 'Reverse Holofoil', 'tcgdex-thirdparty', 24688],
        ['reverse-holofoil:pokeball', 602, 'Holofoil', 'tcgdex-thirdparty', 24688],
    ]);
    expect($skipped)->toBe(1);
});

test('a special print listed before the plain one never lends the base print its product', function () {
    $card = linkCard('me05-119', ['variants' => ['reverse' => true], 'variants_detailed' => [
        ['type' => 'reverse', 'size' => 'standard', 'foil' => 'pokeball', 'thirdParty' => ['tcgplayer' => 700]],
        ['type' => 'reverse', 'size' => 'standard', 'foil' => 'pokeball', 'thirdParty' => ['tcgplayer' => 701]],
        ['type' => 'reverse', 'size' => 'standard', 'thirdParty' => ['tcgplayer' => 702]],
    ]]);

    app(TcgplayerLinkDiscovery::class)->discover($card, priceIndex(24688, [700 => ['Holofoil'], 701 => ['Holofoil'], 702 => ['Reverse Holofoil']]));

    expect(collect(linksOf($card))->firstWhere(0, 'reverse-holofoil')[1])->toBe(702);
});

test('a card may also link within a group one of its links already uses', function () {
    // A Prize Pack group isn't mapped to the card's set, but an existing
    // (admin) link points the card at it.
    $card = linkCard('sv06.5-061', ['variants' => ['normal' => true], 'variants_detailed' => [
        ['type' => 'normal', 'size' => 'standard', 'thirdParty' => ['tcgplayer' => 541000]],
    ]], 'sv06.5', [23900]);
    CardTcgplayerLink::create(['card_id' => $card->id, 'variant' => 'holofoil:cosmos+player-rewards-program', 'product_id' => 703837, 'sub_type' => 'Holofoil', 'group_id' => 22880, 'method' => 'admin']);

    app(TcgplayerLinkDiscovery::class)->discover($card, priceIndex(22880, [541000 => ['Normal']]));

    expect(collect(linksOf($card))->firstWhere(0, 'normal'))->toBe(['normal', 541000, 'Normal', 'tcgdex-thirdparty', 22880]);
});

/** Bastiodon me05-062: its `normal` print is a deck product of its own, in a group no set owns. */
function deckCard(array $normalEntry = []): Card
{
    return linkCard('me05-062', ['pricing' => ['cardmarket' => ['unit' => 'EUR', 'idProduct' => 895847]], 'variants_detailed' => [
        [...['type' => 'normal', 'size' => 'standard', 'thirdParty' => ['cardmarket' => 897297, 'tcgplayer' => 707025]], ...$normalEntry],
        ['type' => 'holo', 'size' => 'standard', 'thirdParty' => ['cardmarket' => 895847, 'tcgplayer' => 704819]],
    ]], 'me05', [24688], 'Bastiodon');
}

/** @return array<int, array<int, array{name: string, number: ?string}>> */
function deckIdentity(string $name = 'Bastiodon - 062/084 (Non-Holo)', ?string $number = '062/084'): array
{
    return [1840 => [707025 => ['name' => $name, 'number' => $number]]];
}

test('a deck product of a group no set owns links when it is that card\'s own normal print, and is marked verified', function () {
    config(['tcgcsv.extra_groups' => [1840]]);
    $card = deckCard();

    app(TcgplayerLinkDiscovery::class)->discover($card, priceIndex(1840, [707025 => ['Normal']]), deckIdentity());

    expect(linksOf($card))->toBe([['normal', 707025, 'Normal', 'tcgdex-thirdparty', 1840]]);
    expect(CardTcgplayerLink::where('card_id', $card->id)->first()->verified_at)->not->toBeNull();
});

test('a product in a group that is not an extra group is never linked to a card of another set', function () {
    config(['tcgcsv.extra_groups' => []]);
    $card = deckCard();

    app(TcgplayerLinkDiscovery::class)->discover($card, priceIndex(1840, [707025 => ['Normal']]), deckIdentity());

    expect(linksOf($card))->toBe([]);
});

test('an extra-group product whose printed number is not the card\'s is not linked', function () {
    config(['tcgcsv.extra_groups' => [1840]]);
    $card = deckCard();

    app(TcgplayerLinkDiscovery::class)->discover($card, priceIndex(1840, [707025 => ['Normal']]), deckIdentity('Bastiodon - 063/084 (Non-Holo)', '063/084'));

    expect(linksOf($card))->toBe([]);
});

test('an extra-group product whose name is not the card\'s is not linked', function () {
    config(['tcgcsv.extra_groups' => [1840]]);
    $card = deckCard();

    app(TcgplayerLinkDiscovery::class)->discover($card, priceIndex(1840, [707025 => ['Normal']]), deckIdentity('Rampardos - 062/084 (Non-Holo)'));

    expect(linksOf($card))->toBe([]);
});

test('an extra-group product with no printed number is not linked', function () {
    config(['tcgcsv.extra_groups' => [1840]]);
    $card = deckCard();

    app(TcgplayerLinkDiscovery::class)->discover($card, priceIndex(1840, [707025 => ['Normal']]), deckIdentity('Bastiodon Box', null));

    expect(linksOf($card))->toBe([]);
});

test('an extra-group product nobody identified is not linked', function () {
    config(['tcgcsv.extra_groups' => [1840]]);
    $card = deckCard();

    app(TcgplayerLinkDiscovery::class)->discover($card, priceIndex(1840, [707025 => ['Normal']]));

    expect(linksOf($card))->toBe([]);
});

test('an extra-group product missing from the prices is not linked', function () {
    config(['tcgcsv.extra_groups' => [1840]]);
    $card = deckCard();

    app(TcgplayerLinkDiscovery::class)->discover($card, priceIndex(1840, [111 => ['Normal']]), deckIdentity());

    expect(linksOf($card))->toBe([]);
});

test('an extra group never links a print that shares the main product\'s cardmarket id', function () {
    // sv03-062 Palafin: tcgdex files its pre-release print under `normal`, on the main cardmarket product.
    config(['tcgcsv.extra_groups' => [1840]]);
    $card = deckCard(['thirdParty' => ['cardmarket' => 895847, 'tcgplayer' => 707025]]);

    app(TcgplayerLinkDiscovery::class)->discover($card, priceIndex(1840, [707025 => ['Normal']]), deckIdentity());

    expect(linksOf($card))->toBe([]);
});

test('an extra group never links a stamped or foil print', function (array $marker) {
    config(['tcgcsv.extra_groups' => [1840]]);
    $card = deckCard($marker);

    app(TcgplayerLinkDiscovery::class)->discover($card, priceIndex(1840, [707025 => ['Normal']]), deckIdentity());

    expect(collect(linksOf($card))->where(3, 'tcgdex-thirdparty')->where(4, 1840)->all())->toBe([]);
})->with([
    'stamp' => [['stamp' => ['pre-release']]],
    'foil' => [['foil' => 'cosmos']],
]);

test('an extra group never links a print that is not the normal one', function () {
    config(['tcgcsv.extra_groups' => [1840]]);
    $card = linkCard('me05-062', ['pricing' => ['cardmarket' => ['idProduct' => 895847]], 'variants_detailed' => [
        ['type' => 'holo', 'size' => 'standard', 'thirdParty' => ['cardmarket' => 897297, 'tcgplayer' => 707025]],
    ]], 'me05', [24688], 'Bastiodon');

    app(TcgplayerLinkDiscovery::class)->discover($card, priceIndex(1840, [707025 => ['Holofoil']]), deckIdentity());

    expect(linksOf($card))->toBe([]);
});

test('a product in the set\'s own group is linked as before, not marked verified', function () {
    config(['tcgcsv.extra_groups' => [1840]]);
    $card = deckCard();

    app(TcgplayerLinkDiscovery::class)->discover($card, priceIndex(24688, [707025 => ['Normal']]));

    expect(linksOf($card))->toBe([['normal', 707025, 'Normal', 'tcgdex-thirdparty', 24688]]);
    expect(CardTcgplayerLink::where('card_id', $card->id)->first()->verified_at)->toBeNull();
});

test('a link that moves to another product loses its verification', function () {
    config(['tcgcsv.extra_groups' => [1840]]);
    $card = deckCard();
    CardTcgplayerLink::create(['card_id' => $card->id, 'variant' => 'normal', 'product_id' => 707025, 'sub_type' => 'Normal', 'group_id' => 1840, 'method' => 'tcgdex-thirdparty', 'verified_at' => now()]);
    $card->update(['raw' => ['pricing' => ['cardmarket' => ['idProduct' => 895847]], 'variants_detailed' => [
        ['type' => 'normal', 'size' => 'standard', 'thirdParty' => ['cardmarket' => 897297, 'tcgplayer' => 24688001]],
    ]]]);

    app(TcgplayerLinkDiscovery::class)->discover($card->fresh(), priceIndex(24688, [24688001 => ['Normal']]));

    expect(CardTcgplayerLink::where('card_id', $card->id)->first()->verified_at)->toBeNull();
});

test('a verified link stays verified on the next run, when discovery finds the product through the link\'s own group', function () {
    config(['tcgcsv.extra_groups' => [1840]]);
    $card = deckCard();
    $prices = priceIndex(1840, [707025 => ['Normal']]);

    app(TcgplayerLinkDiscovery::class)->discover($card, $prices, deckIdentity());
    app(TcgplayerLinkDiscovery::class)->discover($card, $prices, deckIdentity());

    expect(CardTcgplayerLink::where('card_id', $card->id)->sole()->verified_at)->not->toBeNull();
});

test('a verified link stays verified once tcgdex prices the print itself on that product', function () {
    config(['tcgcsv.extra_groups' => [1840]]);
    $card = deckCard();
    $prices = priceIndex(1840, [707025 => ['Normal']]);
    app(TcgplayerLinkDiscovery::class)->discover($card, $prices, deckIdentity());
    CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'tcgplayer', 'origin' => 'tcgdex', 'variant' => 'normal', 'captured_on' => today(), 'currency' => 'USD', 'market_minor' => 122, 'raw' => ['productId' => 707025]]);

    app(TcgplayerLinkDiscovery::class)->discover($card, $prices, deckIdentity());

    $link = CardTcgplayerLink::where('card_id', $card->id)->sole();
    expect([$link->method, $link->verified_at !== null])->toBe(['tcgdex-price', true]);
});

test('a tcgdex-priced normal product that is the main product is not verified through an extra group', function () {
    config(['tcgcsv.extra_groups' => [1840]]);
    $card = deckCard();
    // tcgdex priced `normal` on the card's holo product, not on the deck product.
    CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'tcgplayer', 'origin' => 'tcgdex', 'variant' => 'normal', 'captured_on' => today(), 'currency' => 'USD', 'market_minor' => 122, 'raw' => ['productId' => 704819]]);

    app(TcgplayerLinkDiscovery::class)->discover($card, priceIndex(1840, [704819 => ['Normal']]), [1840 => [704819 => ['name' => 'Bastiodon - 062/084', 'number' => '062/084']]]);

    expect(CardTcgplayerLink::where('card_id', $card->id)->whereNotNull('verified_at')->exists())->toBeFalse();
});

test('an extra-group product printed for a bigger set than the card\'s is not linked', function () {
    config(['tcgcsv.extra_groups' => [1840]]);
    $card = deckCard();
    $card->update(['raw' => [...$card->raw, 'set' => ['cardCount' => ['official' => 84]]]]);

    app(TcgplayerLinkDiscovery::class)->discover($card->fresh(), priceIndex(1840, [707025 => ['Normal']]), deckIdentity('Bastiodon - 062/200 (Non-Holo)', '062/200'));

    expect(linksOf($card))->toBe([]);
});

test('a card whose set total is unknown is matched on number and name alone', function () {
    config(['tcgcsv.extra_groups' => [1840]]);
    $card = deckCard();

    app(TcgplayerLinkDiscovery::class)->discover($card, priceIndex(1840, [707025 => ['Normal']]), deckIdentity('Bastiodon - 062/200 (Non-Holo)', '062/200'));

    expect(linksOf($card))->toBe([['normal', 707025, 'Normal', 'tcgdex-thirdparty', 1840]]);
});

test('a link whose product is no longer identified loses its verification', function () {
    config(['tcgcsv.extra_groups' => [1840]]);
    $card = deckCard();
    $prices = priceIndex(1840, [707025 => ['Normal']]);

    app(TcgplayerLinkDiscovery::class)->discover($card, $prices, deckIdentity());
    app(TcgplayerLinkDiscovery::class)->discover($card, $prices, [1840 => []]);

    expect(CardTcgplayerLink::where('card_id', $card->id)->sole()->verified_at)->toBeNull();
});

test('an extra-group product whose name only begins with the card\'s name is not linked', function () {
    config(['tcgcsv.extra_groups' => [1840]]);
    $card = linkCard('me05-062', ['pricing' => ['cardmarket' => ['idProduct' => 895847]], 'variants_detailed' => [
        ['type' => 'normal', 'size' => 'standard', 'thirdParty' => ['cardmarket' => 897297, 'tcgplayer' => 707025]],
    ]], 'me05', [24688], 'Mew');

    app(TcgplayerLinkDiscovery::class)->discover($card, priceIndex(1840, [707025 => ['Normal']]), deckIdentity('Mewtwo - 062/084 (Non-Holo)'));

    expect(linksOf($card))->toBe([]);
});

test('an accented card name matches the product TCGplayer spells without the accent', function () {
    config(['tcgcsv.extra_groups' => [1840]]);
    $card = linkCard('me05-062', ['pricing' => ['cardmarket' => ['idProduct' => 895847]], 'variants_detailed' => [
        ['type' => 'normal', 'size' => 'standard', 'thirdParty' => ['cardmarket' => 897297, 'tcgplayer' => 707025]],
    ]], 'me05', [24688], 'Flabébé');

    app(TcgplayerLinkDiscovery::class)->discover($card, priceIndex(1840, [707025 => ['Normal']]), deckIdentity('Flabebe - 062/084 (Non-Holo)'));

    expect(linksOf($card))->toBe([['normal', 707025, 'Normal', 'tcgdex-thirdparty', 1840]]);
});

test('a name with an apostrophe still matches its product', function () {
    config(['tcgcsv.extra_groups' => [1840]]);
    $card = linkCard('me05-062', ['pricing' => ['cardmarket' => ['idProduct' => 895847]], 'variants_detailed' => [
        ['type' => 'normal', 'size' => 'standard', 'thirdParty' => ['cardmarket' => 897297, 'tcgplayer' => 707025]],
    ]], 'me05', [24688], "Farfetch'd");

    app(TcgplayerLinkDiscovery::class)->discover($card, priceIndex(1840, [707025 => ['Normal']]), deckIdentity("Farfetch'd - 062/084 (Non-Holo)"));

    expect(linksOf($card))->toBe([['normal', 707025, 'Normal', 'tcgdex-thirdparty', 1840]]);
});

test('an extra group never links a print on a card with no top-level cardmarket product to compare with', function () {
    config(['tcgcsv.extra_groups' => [1840]]);
    $card = linkCard('me05-062', ['variants_detailed' => [
        ['type' => 'normal', 'size' => 'standard', 'thirdParty' => ['cardmarket' => 897297, 'tcgplayer' => 707025]],
    ]], 'me05', [24688], 'Bastiodon');

    app(TcgplayerLinkDiscovery::class)->discover($card, priceIndex(1840, [707025 => ['Normal']]), deckIdentity());

    expect(linksOf($card))->toBe([]);
});

test('a link an admin confirmed keeps its verification when discovery finds the same product', function () {
    config(['tcgcsv.extra_groups' => [1840]]);
    $card = deckCard();
    $verified = now()->subDay()->startOfSecond();
    CardTcgplayerLink::create(['card_id' => $card->id, 'variant' => 'normal', 'product_id' => 707025, 'sub_type' => 'Normal', 'group_id' => 1840, 'method' => 'admin', 'verified_at' => $verified]);

    app(TcgplayerLinkDiscovery::class)->discover($card, priceIndex(1840, [707025 => ['Normal']]), deckIdentity());

    $link = CardTcgplayerLink::where('card_id', $card->id)->first();
    expect($link->method)->toBe('admin')->and($link->verified_at->equalTo($verified))->toBeTrue();
});

/**
 * A 30th Celebration print: tcgdex's only entry is stamped, on the card's own
 * cardmarket product, and TCGplayer sells it as a Holofoil-only product.
 *
 * @param  array<string, mixed>  $entry  overrides for the entry
 * @param  list<array<string, mixed>>  $more  further entries of the card
 */
function stampedCard(array $entry = [], array $more = [], string $tcgdexId = '30th-018', string $set = '30th', array $groups = [24722]): Card
{
    return linkCard($tcgdexId, ['pricing' => ['cardmarket' => ['unit' => 'EUR', 'idProduct' => 907624]], 'variants_detailed' => [
        [...['type' => 'normal', 'size' => 'standard', 'stamp' => ['30th-anniversary'], 'thirdParty' => ['cardmarket' => 907624, 'tcgplayer' => 716451]], ...$entry],
        ...$more,
    ]], $set, $groups, 'Articuno');
}

test('a lone stamped print takes the only subtype its product has', function () {
    $card = stampedCard();

    app(TcgplayerLinkDiscovery::class)->discover($card, priceIndex(24722, [716451 => ['Holofoil']]));

    expect(linksOf($card))->toBe([['normal', 716451, 'Holofoil', 'tcgdex-thirdparty', 24722]]);
});

test('a lone stamped holo print still links to its Holofoil product', function () {
    $card = stampedCard(['type' => 'holo']);

    app(TcgplayerLinkDiscovery::class)->discover($card, priceIndex(24722, [716451 => ['Holofoil']]));

    expect(linksOf($card))->toBe([['holofoil', 716451, 'Holofoil', 'tcgdex-thirdparty', 24722]]);
});

test('a lone stamped holo print takes the only subtype its product has when that is Normal', function () {
    // mep-028's shape: a single stamped holo whose TCGplayer product is sold as Normal only.
    $card = stampedCard(['type' => 'holo']);

    app(TcgplayerLinkDiscovery::class)->discover($card, priceIndex(24722, [716451 => ['Normal']]));

    expect(linksOf($card))->toBe([['holofoil', 716451, 'Normal', 'tcgdex-thirdparty', 24722]]);
});

test('a lone stamped print whose product has several subtypes still needs the one for its key', function () {
    $card = stampedCard();

    // Holofoil is listed first, so taking "the first subtype" would pick it.
    app(TcgplayerLinkDiscovery::class)->discover($card, priceIndex(24722, [716451 => ['Holofoil', 'Normal']]));

    expect(linksOf($card))->toBe([['normal', 716451, 'Normal', 'tcgdex-thirdparty', 24722]]);
});

test('a lone stamped print with no cardmarket product to tie it to the card is not given the only subtype', function () {
    $card = stampedCard(['thirdParty' => ['tcgplayer' => 716451]]);

    app(TcgplayerLinkDiscovery::class)->discover($card, priceIndex(24722, [716451 => ['Holofoil']]));

    expect(linksOf($card))->toBe([]);
});

test('a lone stamped reverse print is not given the only subtype of its product', function () {
    $card = stampedCard(['type' => 'reverse']);

    app(TcgplayerLinkDiscovery::class)->discover($card, priceIndex(24722, [716451 => ['Holofoil']]));

    expect(linksOf($card))->toBe([]);
});

test('a plain print is not given the only subtype of its product', function () {
    $card = stampedCard(['stamp' => null]);

    app(TcgplayerLinkDiscovery::class)->discover($card, priceIndex(24722, [716451 => ['Holofoil']]));

    expect(linksOf($card))->toBe([]);
});

test('a stamped print on a cardmarket product of its own stays a special print, not the base', function () {
    $card = stampedCard(['thirdParty' => ['cardmarket' => 999999, 'tcgplayer' => 716451]]);

    app(TcgplayerLinkDiscovery::class)->discover($card, priceIndex(24722, [716451 => ['Holofoil']]));

    expect(linksOf($card))->toBe([['normal+30th-anniversary', 716451, 'Holofoil', 'tcgdex-thirdparty', 24722]]);
});

test('a stamped print on a card with another standard entry is not given the only subtype', function () {
    // mep-064's shape: a plain holo with no product ids beside a stamped one.
    $card = stampedCard(['type' => 'holo', 'stamp' => ['set-logo']], [['type' => 'holo', 'size' => 'standard']]);

    app(TcgplayerLinkDiscovery::class)->discover($card, priceIndex(24722, [716451 => ['Normal']]));

    expect(linksOf($card))->toBe([]);
});

test('an admin link on the same print is not replaced by a stamped-print link', function () {
    $card = stampedCard();
    CardTcgplayerLink::create(['card_id' => $card->id, 'variant' => 'normal', 'product_id' => 111, 'sub_type' => 'Holofoil', 'group_id' => 24722, 'method' => 'admin']);

    app(TcgplayerLinkDiscovery::class)->discover($card, priceIndex(24722, [716451 => ['Holofoil']]));

    expect(linksOf($card))->toBe([['normal', 111, 'Holofoil', 'admin', 24722]]);
});

test('a tcgdex-priced candidate for a stamped print never gets the only-subtype exception', function () {
    $card = stampedCard();
    CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'tcgplayer', 'origin' => 'tcgdex', 'variant' => 'normal', 'captured_on' => today(), 'currency' => 'USD', 'market_minor' => 22, 'raw' => ['productId' => 716451]]);

    app(TcgplayerLinkDiscovery::class)->discover($card, priceIndex(24722, [716451 => ['Holofoil']]));

    expect(linksOf($card))->toBe([]);
});
