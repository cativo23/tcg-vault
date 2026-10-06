<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\Card;
use App\Modules\Catalog\Models\CardPriceSnapshot;
use App\Modules\Catalog\Models\CardTcgplayerLink;
use App\Modules\Catalog\Models\Set;
use App\Modules\Catalog\Tcgcsv\TcgcsvPriceRow;
use App\Modules\Catalog\Tcgcsv\TcgplayerLinkDiscovery;

function linkCard(string $tcgdexId, array $raw = [], string $set = 'me05'): Card
{
    $s = Set::firstOrCreate(['tcgdex_id' => $set], ['name' => $set]);

    return Card::create(['tcgdex_id' => $tcgdexId, 'set_id' => $s->id, 'local_id' => explode('-', $tcgdexId)[1], 'name' => $tcgdexId, 'raw' => $raw]);
}

/** @return array<int, array<string, TcgcsvPriceRow>> */
function priceIndex(int $groupId, array $products): array
{
    $index = [];
    foreach ($products as $productId => $subTypes) {
        foreach ($subTypes as $subType) {
            $index[$productId][$subType] = new TcgcsvPriceRow($groupId, $productId, $subType, 100, 50, []);
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
    ]], '30th');

    app(TcgplayerLinkDiscovery::class)->discover($card, priceIndex(24722, [696682 => ['Holofoil']]));

    expect(linksOf($card))->toBe([['holofoil', 696682, 'Holofoil', 'tcgdex-thirdparty', 24722]]);
});

test('base and special prints of one card link to their own products and subtypes', function () {
    // me02.5-183 Boss's Orders: one product for normal + reverse, separate Prize Pack products.
    $card = linkCard('me02.5-183', ['variants' => ['normal' => true, 'reverse' => true, 'holo' => true], 'variants_detailed' => [
        ['type' => 'normal', 'size' => 'standard', 'thirdParty' => ['tcgplayer' => 675995]],
        ['type' => 'reverse', 'size' => 'standard', 'thirdParty' => ['tcgplayer' => 675995]],
        ['type' => 'normal', 'size' => 'standard', 'stamp' => ['player-rewards-program'], 'thirdParty' => ['tcgplayer' => 704398]],
        ['type' => 'holo', 'size' => 'standard', 'foil' => 'cosmos', 'stamp' => ['player-rewards-program'], 'thirdParty' => ['tcgplayer' => 704399]],
    ]], 'me02.5');

    app(TcgplayerLinkDiscovery::class)->discover($card, priceIndex(24600, [675995 => ['Normal', 'Reverse Holofoil']]) + priceIndex(22880, [704398 => ['Normal'], 704399 => ['Holofoil']]));

    expect(linksOf($card))->toBe([
        // tcgdex flags this card holo only because of the Prize Pack cosmos,
        // its lone holo entry, which CardVariants reads as the holo print.
        ['holofoil', 704399, 'Holofoil', 'tcgdex-thirdparty', 22880],
        ['normal', 675995, 'Normal', 'tcgdex-thirdparty', 24600],
        ['normal+player-rewards-program', 704398, 'Normal', 'tcgdex-thirdparty', 22880],
        ['reverse-holofoil', 675995, 'Reverse Holofoil', 'tcgdex-thirdparty', 24600],
    ]);
});

test('a print whose product is not in any fetched group, or that tcgdex does not list, gets no link', function () {
    $card = linkCard('sv06.5-061', ['variants_detailed' => [
        ['type' => 'normal', 'size' => 'standard', 'thirdParty' => ['tcgplayer' => 999]],
    ]], 'sv06.5');

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
